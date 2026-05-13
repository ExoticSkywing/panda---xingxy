<?php
/**
 * BBS 帖子级分群标签访问控制
 *
 * 三层过滤设计：
 *   1. 板块登录墙（Zibll 原生 allow_view=signin）→ 挡住游客
 *   2. 帖子列表橱窗 → 标题可见，吸引注册用户
 *   3. 帖子内容标签门 → 只有拥有对应标签的用户才能阅读正文
 *
 * 存储: post_meta `_xingxy_post_segments` (array of tag slugs)
 *        或预设索引 `_xingxy_post_seg_preset` (int, 对应 CSF 预设模板索引)
 *
 * @package Xingxy
 */

if (!defined('ABSPATH')) exit;

// ===================================================================
// 0. 统一保存/清除函数（所有入口共用，确保双 key 同步）
// ===================================================================

/**
 * 统一保存或清除帖子标签限制
 * 同时写入内部 key (_xingxy_*) 和 CSF key (xingxy_*)
 *
 * @param int         $post_id     帖子 ID
 * @param string|int  $preset_idx  预设索引，空字符串 = 清除限制
 */
function xingxy_save_post_segment($post_id, $preset_idx) {
    if ($preset_idx === '' || $preset_idx === null || $preset_idx === false) {
        delete_post_meta($post_id, '_xingxy_post_seg_preset');
        delete_post_meta($post_id, '_xingxy_post_segments');
        update_post_meta($post_id, 'xingxy_post_seg_preset', '');
        return;
    }

    $preset_idx = intval($preset_idx);
    $presets = xingxy_pz('post_segment_presets', []);
    if (!isset($presets[$preset_idx])) return;

    $raw_segments = $presets[$preset_idx]['segments'];
    $segments = is_array($raw_segments)
        ? array_values(array_unique(array_filter(array_map('trim', $raw_segments))))
        : array_values(array_unique(array_filter(array_map('trim', explode(',', $raw_segments)))));

    update_post_meta($post_id, '_xingxy_post_seg_preset', $preset_idx);
    update_post_meta($post_id, '_xingxy_post_segments', $segments);
    update_post_meta($post_id, 'xingxy_post_seg_preset', (string) $preset_idx);
}

// ===================================================================
// 1. 帖子内容渲染拦截：无标签权限时替换正文为拒绝信息
//    复用 Zibll 原生 not_html 样式，只替换正文区，保留评论/评分/分享等
// ===================================================================

/**
 * 检查当前帖子是否因标签限制而被拦截
 * 返回 false = 放行，返回 not_html 字符串 = 拦截
 */
function xingxy_post_segment_get_denial($post = null) {
    if (!$post) return false;

    $required = get_post_meta($post->ID, '_xingxy_post_segments', true);
    if (empty($required) || !is_array($required)) {
        // fallback: 从 CSF metabox 存储的预设索引解析标签
        $csf_preset = get_post_meta($post->ID, 'xingxy_post_seg_preset', true);
        if ($csf_preset !== '' && $csf_preset !== false) {
            $presets = xingxy_pz('post_segment_presets', []);
            $idx = intval($csf_preset);
            if (isset($presets[$idx]) && !empty($presets[$idx]['segments'])) {
                $raw = $presets[$idx]['segments'];
                $required = is_array($raw) ? array_values(array_filter($raw)) : array_filter(explode(',', $raw));
                // 同步写入，下次直接命中
                if (!empty($required)) {
                    update_post_meta($post->ID, '_xingxy_post_seg_preset', $idx);
                    update_post_meta($post->ID, '_xingxy_post_segments', $required);
                }
            }
        }
    }
    if (empty($required) || !is_array($required)) return false;

    // 管理员 bypass
    if (is_super_admin()) return false;
    if (function_exists('zib_bbs_user_is_forum_admin') && zib_bbs_user_is_forum_admin()) return false;

    $user_id = get_current_user_id();

    // 作者 bypass
    if ($user_id && $post->post_author == $user_id) return false;

    // 版主 bypass
    if ($user_id && function_exists('zib_bbs_get_user_moderator_badge')) {
        $badge = zib_bbs_get_user_moderator_badge($user_id, $post);
        if ($badge) return false;
    }

    $user_segments = [];
    if ($user_id) {
        $user_segments = get_user_meta($user_id, '_xingxy_segments', true);
        if (!is_array($user_segments)) $user_segments = [];
    }

    // 交集检查
    if (!empty(array_intersect($required, $user_segments))) return false;

    // ===== 无权限 → 构建 Zibll 原生风格的 not_html =====
    $denial_text = xingxy_pz('post_segment_denial_text', '该内容仅对特定用户开放，暂无查看权限');
    global $zib_bbs;
    $type_name = isset($zib_bbs->posts_name) ? $zib_bbs->posts_name : '星讯';
    $title = '该' . $type_name . '内容已隐藏';

    $con = '<div class="text-center em09 mt20">';
    $con .= '<p class="separator muted-3-color mb20">' . esc_html($denial_text) . '</p>';
    $con .= '</div>';

    $not_html = '<div class="hide-post mt6">';
    $not_html .= '<div class=""><i class="fa fa-lock mr6"></i>' . $title . '</div>';
    $not_html .= $con;
    $not_html .= '</div>';

    return $not_html;
}

/**
 * 在 zib_bbs_single_content 之前，用 OB 拦截其输出
 * 当 Zibll 原生权限通过但标签权限不通过时，替换正文为 not_html
 */
add_action('bbs_posts_page_content', 'xingxy_post_segment_ob_start', 1);
function xingxy_post_segment_ob_start() {
    global $post;
    if (!$post || $post->post_type !== 'forum_post') return;

    $not_html = xingxy_post_segment_get_denial($post);
    if ($not_html === false) return;

    // 标记全局，供 OB 回调使用
    $GLOBALS['_xingxy_post_seg_not_html'] = $not_html;

    // 拦截 the_content 输出——用 filter 替换为 not_html
    add_filter('the_content', 'xingxy_post_segment_replace_content', 1);
}

function xingxy_post_segment_replace_content($content) {
    global $post;
    if (!$post || $post->post_type !== 'forum_post') return $content;
    if (empty($GLOBALS['_xingxy_post_seg_not_html'])) return $content;

    // 帖子级标签限制始终优先——即使内容含 [hidecontent type="segtag"]
    // 也不跳过全文替换（两者不应共存，UI 层已阻止）
    $not_html = $GLOBALS['_xingxy_post_seg_not_html'];
    unset($GLOBALS['_xingxy_post_seg_not_html']);
    remove_filter('the_content', 'xingxy_post_segment_replace_content', 1);

    return '<div class="theme-box">' . $not_html . '</div>';
}

// ===================================================================
// 2. 帖子列表橱窗预览：标题始终可见（设计上不在列表层过滤）
//    用户看到标题 → 点进去 → 被内容层拦截 → 留下预期窗口
// ===================================================================

// ===================================================================
// 3. 前台「设置阅读权限」模态框注入标签预设选择器
// ===================================================================

/**
 * 拦截帖子阅读权限 modal 的 AJAX 输出，追加标签预设 UI
 */
add_action('wp_ajax_posts_allow_view_set_modal', 'xingxy_inject_post_segment_ui', 1);
function xingxy_inject_post_segment_ui() {
    ob_start(function ($html) {
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        $segment_html = xingxy_render_post_segment_preset_ui($id);
        if (!$segment_html) return $html;

        // 在提交按钮 div 之前插入（按钮在 <div class="mt20 but-average"> 里）
        $insert_before = '<div class="mt20 but-average">';
        $pos = strrpos($html, $insert_before);
        if ($pos !== false) {
            $html = substr($html, 0, $pos) . $segment_html . substr($html, $pos);
        } else {
            // fallback: 在 </form> 之前
            $pos = strrpos($html, '</form>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $segment_html . substr($html, $pos);
            }
        }
        return $html;
    });
}

/**
 * 渲染标签预设选择 UI
 */
function xingxy_render_post_segment_preset_ui($post_id = 0) {
    $presets = xingxy_pz('post_segment_presets', []);
    if (empty($presets) || !is_array($presets)) return '';

    // 当前帖子的预设索引（兼容 AJAX 保存的 _xingxy_ 和 CSF metabox 保存的 xingxy_）
    $current_preset = '';
    $current_segments = [];
    if ($post_id) {
        $current_preset = get_post_meta($post_id, '_xingxy_post_seg_preset', true);
        if ($current_preset === '' || $current_preset === false) {
            $current_preset = get_post_meta($post_id, 'xingxy_post_seg_preset', true);
        }
        $current_segments = get_post_meta($post_id, '_xingxy_post_segments', true);
        if (!is_array($current_segments)) $current_segments = [];
    }

    $html = '<div class="xingxy-seg-preset-section" style="border-top: 1px solid var(--muted-border-color, #e5e5e5); margin-top: 15px; padding-top: 15px;">';
    $html .= '<div class="muted-color mb6"><i class="fa fa-tags mr6"></i>标签访问限制</div>';

    // 不限制选项
    $none_checked = empty($current_preset) && empty($current_segments) ? ' checked="checked"' : '';
    $html .= '<div><label class="badg p2-10 mr10 mb6 pointer"><input type="radio" name="xingxy_post_seg_preset" value=""' . $none_checked . ' style="margin-right:4px;">🌐 不限制标签</label></div>';

    // 各预设选项
    foreach ($presets as $idx => $preset) {
        if (empty($preset['name'])) continue;
        $icon = !empty($preset['icon']) ? $preset['icon'] . ' ' : '';
        $checked = ($current_preset !== '' && intval($current_preset) === $idx) ? ' checked="checked"' : '';
        $segs = !empty($preset['segments']) ? $preset['segments'] : '';
        $segs_display = is_array($segs) ? implode(', ', $segs) : $segs;
        $html .= '<div><label class="badg p2-10 mr10 mb6 pointer"><input type="radio" name="xingxy_post_seg_preset" value="' . esc_attr($idx) . '"' . $checked . ' style="margin-right:4px;">' . esc_html($icon . $preset['name']) . '</label>';
        if ($segs_display) {
            $html .= '<span class="muted-3-color em09 ml6">' . esc_html($segs_display) . '</span>';
        }
        $html .= '</div>';
    }

    $html .= '<div class="muted-3-color em09 mt6">选择预设后，仅拥有对应标签的用户才能查看帖子内容</div>';
    $html .= '</div>';

    return $html;
}

// ===================================================================
// 4. 保存帖子标签限制（拦截 edit_allow_view AJAX）
// ===================================================================

add_action('wp_ajax_edit_allow_view', 'xingxy_save_post_segment_preset', 1);
function xingxy_save_post_segment_preset() {
    // 只在有预设字段时处理
    if (!isset($_REQUEST['xingxy_post_seg_preset'])) return;

    $post_id = !empty($_REQUEST['post_id']) ? (int) $_REQUEST['post_id'] : 0;
    if (!$post_id) return;

    $preset_idx = $_REQUEST['xingxy_post_seg_preset'];

    // 选了标签预设 + allow_view=roles 但没选 VIP/等级/认证 → Zibll 会报错
    // 自动将 allow_view 降级为 signin，让 Zibll 校验通过，标签限制照常生效
    if ($preset_idx !== '' && $preset_idx !== null) {
        $allow_view = !empty($_REQUEST['allow_view']) ? $_REQUEST['allow_view'] : '';
        if ($allow_view === 'roles') {
            $roles = !empty($_REQUEST['allow_view_roles']) ? array_filter((array) $_REQUEST['allow_view_roles']) : [];
            if (empty($roles)) {
                $_REQUEST['allow_view'] = 'signin';
                $_POST['allow_view']    = 'signin';
            }
        }
    }

    xingxy_save_post_segment($post_id, $preset_idx);
}

// ===================================================================
// 5. 发帖时保存标签预设（拦截新帖创建 AJAX）
// ===================================================================

/**
 * 帖子保存时同步标签预设（新建 + 编辑共用）
 * 覆盖 bbs_posts_save / bbs_posts_draft 等所有前台保存路径
 */
add_action('save_post_forum_post', 'xingxy_save_post_segment_on_create', 20, 3);
function xingxy_save_post_segment_on_create($post_id, $post, $update) {
    if (!isset($_REQUEST['xingxy_post_seg_preset'])) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    xingxy_save_post_segment($post_id, $_REQUEST['xingxy_post_seg_preset']);
}

// ===================================================================
// 6. 前台发帖编辑器注入标签预设选择（内嵌在发帖表单中）
// ===================================================================

add_action('bbs_locate_template_posts_edit', function() {
    $post_id = (int) get_query_var('forum_post_edit');
    xingxy_inject_post_editor_segment_data($post_id);
});

function xingxy_inject_post_editor_segment_data($post_id = 0) {
    // 注入 JS 数据供前台发帖/编辑时使用
    $presets = xingxy_pz('post_segment_presets', []);
    if (empty($presets)) return;

    // 编辑时读取当前预设
    $current_preset = '';
    if ($post_id) {
        $current_preset = get_post_meta($post_id, '_xingxy_post_seg_preset', true);
        if ($current_preset === '' || $current_preset === false) {
            $current_preset = get_post_meta($post_id, 'xingxy_post_seg_preset', true);
        }
    }
    if ($current_preset === false) $current_preset = '';

    add_action('wp_footer', function () use ($presets, $current_preset) {
        $js_presets = [];
        foreach ($presets as $idx => $p) {
            if (empty($p['name'])) continue;
            $js_presets[] = [
                'idx'  => $idx,
                'name' => $p['name'],
                'icon' => !empty($p['icon']) ? $p['icon'] : '🏷️',
                'segs' => !empty($p['segments']) ? (is_array($p['segments']) ? implode(', ', $p['segments']) : $p['segments']) : '',
            ];
        }
        if (empty($js_presets)) return;
        ?>
        <script>
        (function($){
            if (typeof window.xingxyPostSegPresets !== 'undefined') return;
            window.xingxyPostSegPresets = <?php echo json_encode($js_presets); ?>;
            window.xingxyPostSegCurrentPreset = <?php echo json_encode((string)$current_preset); ?>;

            // 在发帖编辑器的「阅读权限」区域旁注入标签预设
            function injectSegPresetUI() {
                // 桌面端: .allow-view-drop; 移动端: .allow-view-drawer 的父级 .drop-select
                var $drop = $('.allow-view-drop');
                if (!$drop.length) {
                    $drop = $('.allow-view-drawer').closest('.drop-select');
                }
                if (!$drop.length) return false;
                if ($drop.find('.xingxy-seg-preset-section').length) return true;

                var presets = window.xingxyPostSegPresets;
                var cur = window.xingxyPostSegCurrentPreset || '';
                var noneChecked = (cur === '') ? ' checked' : '';
                var html = '<div class="xingxy-seg-preset-section" style="border-top:1px solid var(--muted-border-color,#e5e5e5);margin-top:12px;padding-top:12px;">';
                html += '<div class="muted-color mb6" style="font-size:13px;"><i class="fa fa-tags mr6"></i>标签访问限制</div>';
                html += '<div><label class="badg p2-10 mr10 mb6 pointer"><input type="radio" name="xingxy_post_seg_preset" value=""' + noneChecked + ' style="margin-right:4px;">🌐 不限制标签</label></div>';
                for (var i = 0; i < presets.length; i++) {
                    var p = presets[i];
                    var chk = (String(p.idx) === String(cur)) ? ' checked' : '';
                    html += '<div><label class="badg p2-10 mr10 mb6 pointer"><input type="radio" name="xingxy_post_seg_preset" value="' + p.idx + '"' + chk + ' style="margin-right:4px;">' + p.icon + ' ' + p.name + '</label>';
                    if (p.segs) html += '<span class="muted-3-color em09 ml6">' + p.segs + '</span>';
                    html += '</div>';
                }
                html += '<div class="muted-3-color em09 mt6">选择预设后，仅拥有对应标签的用户才能查看</div>';
                html += '</div>';

                // 桌面端: .select-drop-box; 移动端: .allow-view-drawer .zib-widget
                var $container = $drop.find('.select-drop-box').first();
                if (!$container.length) {
                    $container = $drop.find('.allow-view-drawer .zib-widget').first();
                }
                if ($container.length) {
                    $container.append(html);
                } else {
                    $drop.append(html);
                }
                return true;
            }

            // 尝试注入（可能 DOM 还没就绪）
            $(function(){
                if (!injectSegPresetUI()) {
                    // DOM 还没渲染，用 MutationObserver 等待
                    var observer = new MutationObserver(function(mutations, obs){
                        if (injectSegPresetUI()) obs.disconnect();
                    });
                    observer.observe(document.body, {childList: true, subtree: true});
                    setTimeout(function(){ observer.disconnect(); }, 15000);
                }
            });
        })(jQuery);
        </script>
        <?php
    }, 999);
}

// ===================================================================
// 7. 后台帖子编辑 CSF meta box（和 Zibll 阅读权限面板同框架）
// ===================================================================

add_action('zib_require_end', 'xingxy_register_post_segment_csf_metabox');
function xingxy_register_post_segment_csf_metabox() {
    if (!class_exists('CSF')) return;
    $presets = xingxy_pz('post_segment_presets', []);
    if (empty($presets)) return;

    // 构建 radio 选项
    $options = ['' => '🌐 不限制标签'];
    foreach ($presets as $idx => $preset) {
        if (empty($preset['name'])) continue;
        $icon = !empty($preset['icon']) ? $preset['icon'] : '🏷️';
        $segs_raw = !empty($preset['segments']) ? $preset['segments'] : '';
        $segs_display = $segs_raw ? (is_array($segs_raw) ? implode(', ', $segs_raw) : $segs_raw) : '';
        $label = $icon . ' ' . $preset['name'];
        if ($segs_display) $label .= '（' . $segs_display . '）';
        $options[$idx] = $label;
    }

    CSF::createMetabox('xingxy_post_segment', array(
        'title'     => '标签访问限制',
        'post_type' => array('forum_post'),
        'context'   => 'side',
        'priority'  => 'default',
        'data_type' => 'unserialize',
    ));

    CSF::createSection('xingxy_post_segment', array(
        'fields' => array(
            array(
                'id'      => 'xingxy_post_seg_preset',
                'type'    => 'radio',
                'title'   => '标签预设',
                'desc'    => '选择预设后，仅拥有对应标签的用户才能查看帖子内容',
                'default' => '',
                'options' => $options,
            ),
        ),
    ));
}

/**
 * CSF metabox 保存后同步到内部 post_meta
 * 使用 CSF 自带的 save_after hook，确保在 CSF 写入完成之后执行
 */
add_action('csf_xingxy_post_segment_save_after', 'xingxy_sync_post_segment_from_csf', 10, 3);
function xingxy_sync_post_segment_from_csf($data, $post_id, $csf) {
    $preset_idx = isset($data['xingxy_post_seg_preset']) ? $data['xingxy_post_seg_preset'] : '';
    // CSF 已写入 xingxy_post_seg_preset，统一函数也会再写一次（幂等），
    // 核心目的是同步 _xingxy_* 内部 key
    xingxy_save_post_segment($post_id, $preset_idx);
}

// ===================================================================
// 8. TinyMCE 编辑器「标签用户可查看」菜单项
//    在 Zibll 原生 zib_hide 按钮的下拉菜单末尾追加，
//    插入 [hidecontent type="segtag"] shortcode
// ===================================================================

add_filter('mce_external_plugins', 'xingxy_register_segtag_tinymce_plugin');
function xingxy_register_segtag_tinymce_plugin($plugins) {
    $presets = xingxy_pz('post_segment_presets', []);
    if (!empty($presets)) {
        $plugins['xingxy_segtag'] = get_stylesheet_directory_uri() . '/xingxy/assets/js/tinymce-segtag.js';
    }
    return $plugins;
}

add_action('wp_enqueue_editor', 'xingxy_output_segtag_mce_flag', 20);
function xingxy_output_segtag_mce_flag() {
    $presets = xingxy_pz('post_segment_presets', []);
    if (empty($presets)) return;

    $js_presets = [];
    foreach ($presets as $idx => $preset) {
        if (empty($preset['name'])) continue;
        $js_presets[] = [
            'idx'  => $idx,
            'name' => $preset['name'],
            'icon' => !empty($preset['icon']) ? $preset['icon'] : '🏷️',
        ];
    }
    echo '<script>if(typeof mce!=="undefined"){mce.hide_segtag=true;mce.segtag_presets=' . wp_json_encode($js_presets) . ';}</script>';
}

// ===================================================================
// 9. [hidecontent type="segtag"] shortcode 渲染
//    拦截 Zibll 原生 hidecontent shortcode，处理 segtag 类型
//    复用 Zibll 原生 hidden-box 样式，bypass 逻辑与帖子级一致
// ===================================================================

add_filter('pre_do_shortcode_tag', 'xingxy_handle_segtag_hidecontent', 10, 4);
function xingxy_handle_segtag_hidecontent($output, $tag, $attr, $m) {
    if ($tag !== 'hidecontent') return $output;
    if (empty($attr['type']) || $attr['type'] !== 'segtag') return $output;

    $content = isset($m[5]) ? do_shortcode($m[5]) : '';

    // 管理员 bypass
    if (is_super_admin()) {
        return '<div class="hidden-box show"><div class="hidden-text">[标签用户可查看]隐藏内容 - 管理员可见</div>' . $content . '</div>';
    }

    global $post;
    $user_id = get_current_user_id();

    // 作者 bypass
    if ($user_id && $post && $user_id == $post->post_author) {
        return '<div class="hidden-box show"><div class="hidden-text">[标签用户可查看]隐藏内容 - 作者可见</div>' . $content . '</div>';
    }

    // 版主 bypass
    if ($user_id && $post && function_exists('zib_bbs_get_user_moderator_badge')) {
        $badge = zib_bbs_get_user_moderator_badge($user_id, $post);
        if ($badge) {
            return '<div class="hidden-box show"><div class="hidden-text">[标签用户可查看]隐藏内容 - 版主可见</div>' . $content . '</div>';
        }
    }

    // 从 shortcode 属性读取预设索引
    $preset_idx = isset($attr['preset']) ? intval($attr['preset']) : -1;
    $presets = xingxy_pz('post_segment_presets', []);
    $required = [];

    if ($preset_idx >= 0 && isset($presets[$preset_idx])) {
        $raw = $presets[$preset_idx]['segments'];
        $required = is_array($raw)
            ? array_values(array_unique(array_filter(array_map('trim', $raw))))
            : array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }

    // 检查用户标签
    $user_segments = [];
    if ($user_id) {
        $user_segments = get_user_meta($user_id, '_xingxy_segments', true);
        if (!is_array($user_segments)) $user_segments = [];
    }

    // 有特定标签要求 → 交集检查；无预设或预设无效 → 持有任意标签即可
    $has_access = empty($required)
        ? !empty($user_segments)
        : !empty(array_intersect($required, $user_segments));

    if ($has_access) {
        return '<div class="hidden-box show"><div class="hidden-text">本文隐藏内容 - 标签用户可查看</div>' . $content . '</div>';
    }

    // 无权限 → 显示隐藏提示（复用 Zibll 原生 hidden-box 样式）
    $denial_text = xingxy_pz('post_segment_denial_text', '该内容仅对特定用户开放，暂无查看权限');
    return '<div class="hidden-box"><span class="hidden-text"><i class="fa fa-exclamation-circle"></i>&nbsp;&nbsp;' . esc_html($denial_text) . '</span></div>';
}
