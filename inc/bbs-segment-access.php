<?php
/**
 * BBS 星域分群标签查看限制
 *
 * 在论坛星域的查看权限设置中注入分群标签选择器，
 * 实现按用户标签控制星域访问权限。
 *
 * 存储: post_meta `_xingxy_view_segments` (array)
 * 检查: bbs_plate_page_content 优先级 1
 */

if (!defined('ABSPATH')) exit;

// ===================================================================
// 1. 前台访问控制：检查用户标签是否匹配星域限制
// ===================================================================

// 步骤一（priority 1）：判断权限
// 标签限制仅在 allow_view=roles 时生效（和 VIP/等级/认证 是 OR 关系）
add_action('bbs_plate_page_content', function () {
    global $post, $_xingxy_seg_denied, $_xingxy_seg_granted;
    $_xingxy_seg_denied = false;
    $_xingxy_seg_granted = false;
    if (!$post || $post->post_type !== 'plate') return;

    // 只有 allow_view=roles 时才做标签检查
    $allow_view = get_post_meta($post->ID, 'allow_view', true);
    if ($allow_view !== 'roles') return;

    $required_segments = get_post_meta($post->ID, '_xingxy_view_segments', true);
    if (empty($required_segments) || !is_array($required_segments)) return;

    // 管理员 bypass
    if (is_super_admin()) return;
    if (function_exists('zib_bbs_user_is_forum_admin') && zib_bbs_user_is_forum_admin()) return;

    $user_id = get_current_user_id();

    // 版主 bypass
    if ($user_id && function_exists('zib_bbs_get_user_moderator_badge')) {
        $badge = zib_bbs_get_user_moderator_badge($user_id, $post);
        if ($badge) return;
    }

    $user_segments = [];
    if ($user_id) {
        $user_segments = get_user_meta($user_id, '_xingxy_segments', true);
        if (!is_array($user_segments)) $user_segments = [];
    }

    // 任一标签匹配即可访问（绕过 Zibll 的 VIP/等级检查）
    if (array_intersect($required_segments, $user_segments)) {
        $_xingxy_seg_granted = true;
        // 移除 Zibll 原内容函数，用我们自己的替代（跳过角色检查）
        remove_action('bbs_plate_page_content', 'zib_bbs_plate_page_content');
        return;
    }

    // 标签不匹配 → 检查 Zibll 原生角色是否能通过
    $allow_roles = get_post_meta($post->ID, 'allow_view_roles', true);
    $real_roles = is_array($allow_roles) ? array_filter($allow_roles) : [];
    // 移除我们注入的虚拟标记
    unset($real_roles['xingxy_seg']);
    if (!empty($real_roles)) {
        // 有真实 VIP/等级/认证限制，让 Zibll 继续检查（用户可能满足）
        return;
    }

    // 没有真实角色限制、标签也不匹配 → 拒绝
    $_xingxy_seg_denied = true;
    remove_action('bbs_plate_page_content', 'zib_bbs_plate_page_content');
}, 1);

// 步骤二A（priority 11）：标签匹配成功 → 直接显示内容（跳过 Zibll 角色检查）
add_action('bbs_plate_page_content', function () {
    global $_xingxy_seg_granted;
    if (empty($_xingxy_seg_granted)) return;
    // 直接渲染帖子列表，绕过 zib_bbs_get_plate_not_allow_view 检查
    if (function_exists('zib_bbs_plate_page_tab_content')) {
        zib_bbs_plate_page_tab_content();
    }
}, 11);

// 步骤二B（priority 11）：在板块头之后输出拒绝信息
add_action('bbs_plate_page_content', function () {
    global $_xingxy_seg_denied, $zib_bbs;
    if (empty($_xingxy_seg_denied)) return;

    $plate_name = isset($zib_bbs->plate_name) ? $zib_bbs->plate_name : '星域';
    $user_id = get_current_user_id();

    $null_html = function_exists('zib_get_null')
        ? zib_get_null('该' . $plate_name . '仅对受邀用户开放', 10, 'null-cap.svg', '')
        : '<div style="text-align:center;padding:40px 20px;color:#999;">该' . $plate_name . '仅对受邀用户开放</div>';

    $sign_btns = '';
    if (!$user_id) {
        $sign_btns .= '<p class="separator muted-3-color mb20 mt20">登录后查看我的权限</p>';
        $sign_btns .= '<p><a href="javascript:;" class="signin-loader but jb-blue padding-lg"><i class="fa fa-fw fa-sign-in" aria-hidden="true"></i>登录</a>';
        if (!function_exists('zib_is_close_signup') || !zib_is_close_signup()) {
            $sign_btns .= '<a href="javascript:;" class="signup-loader ml10 but jb-yellow padding-lg">' . (function_exists('zib_get_svg') ? zib_get_svg('signup') : '') . '注册</a>';
        }
        $sign_btns .= '</p>';
    } else {
        $sign_btns .= '<p class="muted-3-color mt20">您的账号暂无访问权限，如需获取请联系管理员</p>';
    }

    echo '<div class="plate-tab zib-widget">';
    echo $null_html;
    echo '<div class="hide-post mt6">';
    echo '<div><i class="fa fa-lock mr6"></i>该' . $plate_name . '内容已隐藏</div>';
    echo '<div class="text-center em09 mt20">';
    echo '<p class="separator muted-3-color mb20">该' . $plate_name . '仅对受邀用户开放</p>';
    echo $sign_btns;
    echo '</div></div></div>';
}, 11);

// ===================================================================
// 2. 保存标签限制
// ===================================================================

function xingxy_save_plate_view_segments($plate_id) {
    if (!$plate_id) return;
    if (!current_user_can('manage_options') && !(function_exists('zib_bbs_current_user_can') && zib_bbs_current_user_can('plate_set_allow_view', $plate_id))) {
        return;
    }

    $raw = $_REQUEST['xingxy_view_segments'] ?? '';
    if (is_string($raw)) {
        $segments = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    } elseif (is_array($raw)) {
        $segments = array_values(array_unique(array_filter(array_map('trim', $raw))));
    } else {
        $segments = [];
    }

    $segments = array_values(array_filter(array_map(function ($s) {
        return preg_replace('/[^a-zA-Z0-9_\->]/', '', $s);
    }, $segments)));

    update_post_meta($plate_id, '_xingxy_view_segments', $segments);
}

// 模态框保存（设置查看权限 modal）
add_action('wp_ajax_plate_edit_allow_view', function () {
    $plate_id = intval($_REQUEST['plate_id'] ?? 0);
    if ($plate_id && isset($_REQUEST['xingxy_seg_present'])) {
        xingxy_save_plate_view_segments($plate_id);
    }
    // 只选了标签、没选 VIP/等级/认证 → 注入虚拟标记绕过 Zibll 验证
    // 前端检查已正确处理：标签匹配时绕过 Zibll 角色检查
    if (isset($_REQUEST['allow_view']) && $_REQUEST['allow_view'] === 'roles') {
        $segments = isset($_REQUEST['xingxy_view_segments']) ? (array) $_REQUEST['xingxy_view_segments'] : [];
        $segments = array_filter($segments);
        $roles = isset($_REQUEST['allow_view_roles']) ? array_filter((array) $_REQUEST['allow_view_roles']) : [];
        if (!empty($segments) && empty($roles)) {
            $_REQUEST['allow_view_roles']['xingxy_seg'] = '1';
            if (!isset($_POST['allow_view_roles'])) $_POST['allow_view_roles'] = [];
            $_POST['allow_view_roles']['xingxy_seg'] = '1';
        }
    }
}, 1);

// 编辑表单保存（编辑此星域 → save_plate → wp_insert_post 触发）
add_action('save_post_plate', function ($post_id) {
    if (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['xingxy_seg_present'])) {
        xingxy_save_plate_view_segments($post_id);
    }
}, 20);

// ===================================================================
// 3. AJAX：获取星域当前标签限制
// ===================================================================

add_action('wp_ajax_xingxy_get_plate_segments', function () {
    $plate_id = intval($_POST['plate_id'] ?? 0);
    if (!$plate_id) wp_send_json_success([]);
    $segments = get_post_meta($plate_id, '_xingxy_view_segments', true);
    if (!is_array($segments)) $segments = [];
    wp_send_json_success($segments);
});

// ===================================================================
// 4. PHP 拦截 AJAX 响应：在查看权限表单中直接注入标签选择器
//    学习 Zibll 原生做法——所有 UI 都在 PHP 端渲染，随 AJAX 响应一起返回
// ===================================================================

/**
 * 渲染分群标签选择器 HTML（匹配 Zibll roles_lists 风格）
 */
function xingxy_render_segment_selector_html($plate_id = 0) {
    global $wpdb;
    $raw_tags = $wpdb->get_col(
        "SELECT DISTINCT meta_value FROM {$wpdb->usermeta}
         WHERE meta_key = '_xingxy_segments' AND meta_value != '' AND meta_value != 'a:0:{}'"
    );
    $all_tags = [];
    foreach ($raw_tags as $v) {
        $arr = maybe_unserialize($v);
        if (is_array($arr)) {
            $all_tags = array_merge($all_tags, $arr);
        }
    }
    $all_tags = array_values(array_unique(array_filter($all_tags)));
    if (empty($all_tags)) return '';

    $current = [];
    if ($plate_id) {
        $current = get_post_meta($plate_id, '_xingxy_view_segments', true);
        if (!is_array($current)) $current = [];
    }

    $html = '<div class="mb20"><div class="muted-color mb6">标签限制</div><div>';
    foreach ($all_tags as $tag) {
        $checked = in_array($tag, $current) ? ' checked="checked"' : '';
        $html .= '<label class="badg p2-10 mr10 mb6 pointer"><input type="checkbox" name="xingxy_view_segments[]" value="' . esc_attr($tag) . '"' . $checked . ' style="margin-right:4px;">' . esc_html($tag) . '</label>';
    }
    $html .= '</div><div class="muted-3-color em09">勾选后仅拥有对应标签的用户可查看（不勾选则不限制）</div></div>';
    // 哨兵字段：确保提交时即使无勾选也能清空
    $html .= '<input type="hidden" name="xingxy_seg_present" value="1">';

    return $html;
}

/**
 * 启动 output buffering 拦截 AJAX 响应
 * 使用 ob_start 回调——exit 时 PHP 自动 flush 并调用回调
 */
function xingxy_ob_start_for_plate_modal() {
    if (!current_user_can('manage_options')) return;
    ob_start('xingxy_ob_inject_segment_selector');
}

/**
 * ob_start 回调：接收原始输出，注入标签选择器后返回
 */
function xingxy_ob_inject_segment_selector($html) {
    if (empty($html) || strpos($html, 'name="allow_view"') === false) {
        return $html;
    }

    // 从请求参数获取 plate_id
    $plate_id = 0;
    if (isset($_REQUEST['id'])) {
        $plate_id = intval($_REQUEST['id']);
    }
    if (!$plate_id && isset($_REQUEST['plate_id'])) {
        $plate_id = intval($_REQUEST['plate_id']);
    }
    // 从 HTML 中兜底提取
    if (!$plate_id && preg_match('/name="plate_id"\s+value="(\d+)"/', $html, $m)) {
        $plate_id = intval($m[1]);
    }

    $inject = xingxy_render_segment_selector_html($plate_id);
    if (!$inject) {
        return $html;
    }

    // 目标：在认证开关之前插入（等级限制下方、认证上方）
    $auth_marker = 'allow_view_roles[auth]';
    $auth_pos = strpos($html, $auth_marker);
    if ($auth_pos !== false) {
        // 回退找到 <label 标签的起始位置
        $label_start = strrpos(substr($html, 0, $auth_pos), '<label');
        if ($label_start !== false) {
            return substr($html, 0, $label_start) . $inject . substr($html, $label_start);
        }
    }

    // 兜底：插入到 roles 容器末尾
    $roles_marker = 'data-controller="allow_view"';
    $roles_pos = strpos($html, $roles_marker);
    if ($roles_pos !== false) {
        $open_pos = strpos($html, '>', $roles_pos);
        if ($open_pos !== false) {
            $open_pos++;
            $depth = 1;
            $search_pos = $open_pos;
            $len = strlen($html);
            while ($search_pos < $len && $depth > 0) {
                $next_open = strpos($html, '<div', $search_pos);
                $next_close = strpos($html, '</div>', $search_pos);
                if ($next_close === false) break;
                if ($next_open !== false && $next_open < $next_close) {
                    $depth++;
                    $search_pos = $next_open + 4;
                } else {
                    $depth--;
                    if ($depth === 0) {
                        return substr($html, 0, $next_close) . $inject . substr($html, $next_close);
                    }
                    $search_pos = $next_close + 6;
                }
            }
        }
    }

    // 兜底：在 </form> 前插入
    return str_replace('</form>', $inject . '</form>', $html);
}

// 拦截「设置查看权限」模态框
add_action('wp_ajax_plate_allow_view_set_modal', 'xingxy_ob_start_for_plate_modal', 1);

// 拦截「编辑此星域」模态框
add_action('wp_ajax_plate_edit_modal', 'xingxy_ob_start_for_plate_modal', 1);
