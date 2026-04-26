<?php
/**
 * 后台看板与人工标注系统
 * 
 * 专门针对用户画像数据（xingxy_profile_data）构建的快速清洗与打标工作台
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. 注册核心菜单
add_action('admin_menu', 'xingxy_register_profile_dashboard_menu');
function xingxy_register_profile_dashboard_menu() {
    add_users_page(
        '用户画像数据中心',    // 页面 Title
        '画像打标数据',        // 菜单显示文字
        'manage_options',    // 需要的权限
        'xingxy-profile-dashboard', // 唯一 Slug
        'xingxy_render_profile_dashboard' // 渲染回调
    );
}

// 2. 渲染 UI 面板
function xingxy_render_profile_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // 获取有画像数据的用户列表（按注册时间倒序）
    // 用单条 SQL 替代多 EXISTS OR 的 meta_query，避免 4 个 LEFT JOIN 导致慢查询
    global $wpdb;
    $user_ids = $wpdb->get_col(
        "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
         WHERE meta_key IN ('xingxy_profile_data','_xingxy_welcome_rewarded','oauth_new','_xingxy_segments')
         ORDER BY user_id DESC
         LIMIT 150"
    );
    $users = [];
    if (!empty($user_ids)) {
        $args = array(
            'include' => $user_ids,
            'number'  => 150,
            'orderby' => 'ID',
            'order'   => 'DESC'
        );
        $user_query = new WP_User_Query($args);
        $users = $user_query->get_results();
    }

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🧑‍🔬 用户画像数据中心 (黄金数据集清洗)</h1>
        <hr class="wp-header-end">
        
        <div class="notice notice-info inline">
            <p>此面板展示了所有完成了“首次探索盲盒问卷”拦截测试的用户。</p>
            <p><strong>数据飞轮机制</strong>：您可以参考用户勾选的「原始选项词汇」，并依据您的判断，进行<strong>人工干预打标</strong>。人工打标的准确数据将作为最高优先级存储在 `xingxy_manual_gender` 中，未来可直接导出为极高纯净度的 AI 训练材料集。</p>
            <p><strong>优先级</strong>：人工打标 &gt; 盲盒问卷推断 &gt; OAuth 社交登录推断（<span style="color:#e65100;">橘色标记</span>）</p>
        </div>
        
        <table class="wp-list-table widefat fixed striped table-view-list users" style="margin-top: 15px;">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-username" style="width: 15%;">用户 (ID / 名称)</th>
                    <th scope="col" class="manage-column" style="width: 10%;">系统推测年龄</th>
                    <th scope="col" class="manage-column" style="width: 12%;">系统推测性别</th>
                    <th scope="col" class="manage-column" style="width: 35%;">原始问卷证据词汇 (打标依据)</th>
                    <th scope="col" class="manage-column" style="width: 12%;">🏷️ 分群标签</th>
                    <th scope="col" class="manage-column" style="width: 20%;">🚀 人工干预/降临打标</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($users)): ?>
                    <?php foreach ($users as $user): 
                        $profile_data = get_user_meta($user->ID, 'xingxy_profile_data', true);
                        $manual_gender = get_user_meta($user->ID, 'xingxy_manual_gender', true);
                        
                        // OAuth 降级推断
                        $oauth_gender_result = xingxy_get_oauth_gender($user->ID);
                        $is_oauth_only = empty($profile_data);
                        
                        $age = isset($profile_data['age']) ? $profile_data['age'] : '-';
                        $gender = isset($profile_data['gender']) ? $profile_data['gender'] : '未判断';
                        $raw = isset($profile_data['raw']) ? $profile_data['raw'] : '（暂无行为数据遗留）';
                        $raw_split = isset($profile_data['raw_split']) ? $profile_data['raw_split'] : [];
                        
                        // 问卷没采集到但 OAuth 有性别时，用 OAuth 降级填充
                        $gender_source = '';
                        if ($gender === '未判断' && $oauth_gender_result['gender']) {
                            $src_names = ['weixin' => '微信', 'qq' => 'QQ', 'weibo' => '微博', 'google' => 'Google', 'github' => 'GitHub', 'baidu' => '百度', 'alipay' => '支付宝', 'huawei' => '华为', 'xiaomi' => '小米', 'gitee' => 'Gitee'];
                            $src_label = $src_names[$oauth_gender_result['source']] ?? $oauth_gender_result['source'];
                            $gender = $oauth_gender_result['gender'];
                            $gender_source = $src_label;
                        }
                        
                        // 预判人工标记状态 HTML
                        $status_html = '<span style="color:#888;">🔘 尚无人工清洗记录</span>';
                        if ($manual_gender === '男') {
                            $status_html = '<span style="color:#0071a1; font-weight:bold;">👨 已由人工确认为男性</span>';
                        } elseif ($manual_gender === '女') {
                            $status_html = '<span style="color:#d63638; font-weight:bold;">👩 已由人工确认为女性</span>';
                        }
                        
                        // 生成炫彩的分维度证据结构
                        $evidence_html = '';
                        if (!empty($raw_split) && (count($raw_split['dim1'] ?? []) > 0 || count($raw_split['dim2'] ?? []) > 0 || count($raw_split['dim3'] ?? []) > 0)) {
                            // 维度1 (基础偶像/英雄) - 蓝色
                            if (!empty($raw_split['dim1'])) {
                                $evidence_html .= '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#e5f5fa; color:#007cba; border-radius:4px; margin-right:6px;">选项一</span> ' . implode(' | ', $raw_split['dim1']) . '</div>';
                            }
                            // 维度2 (娱乐/游戏) - 紫色
                            if (!empty($raw_split['dim2'])) {
                                $evidence_html .= '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#f0e6ff; color:#6d28d9; border-radius:4px; margin-right:6px;">选项二</span> ' . implode(' | ', $raw_split['dim2']) . '</div>';
                            }
                            // 维度3 (消费偏好) - 橘色
                            if (!empty($raw_split['dim3'])) {
                                $evidence_html .= '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#fff5eb; color:#d97706; border-radius:4px; margin-right:6px;">选项三</span> ' . implode(' | ', $raw_split['dim3']) . '</div>';
                            }
                        } else {
                            // 向后兼容：旧数据没有 raw_split，通过反向匹配配置选项自动着色
                            if (!empty($raw) && $raw !== '（暂无行为数据遗留）') {
                                $old_arr = array_filter(explode(' | ', $raw));
                                
                                // 读取后台配置的问卷选项，建立"选项名 => 维度"映射表
                                $opts1 = (array)xingxy_pz('profile_dimension_1', []);
                                $opts2 = (array)xingxy_pz('profile_dimension_2', []);
                                $opts3 = (array)xingxy_pz('profile_dimension_3', []);
                                
                                $name_to_dim = [];
                                foreach ($opts1 as $opt) { if (!empty($opt['name'])) $name_to_dim[$opt['name']] = 'dim1'; }
                                foreach ($opts2 as $opt) { if (!empty($opt['name'])) $name_to_dim[$opt['name']] = 'dim2'; }
                                foreach ($opts3 as $opt) { if (!empty($opt['name'])) $name_to_dim[$opt['name']] = 'dim3'; }
                                
                                // 反向归类
                                $rebuilt = ['dim1' => [], 'dim2' => [], 'dim3' => []];
                                $unmatched = [];
                                foreach ($old_arr as $word) {
                                    $word = trim($word);
                                    if (isset($name_to_dim[$word])) {
                                        $rebuilt[$name_to_dim[$word]][] = $word;
                                    } else {
                                        $unmatched[] = $word;
                                    }
                                }
                                
                                // 用反向匹配的结果渲染彩色标签
                                if (!empty($rebuilt['dim1'])) {
                                    $evidence_html .= '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#e5f5fa; color:#007cba; border-radius:4px; margin-right:6px;">选项一</span> ' . esc_html(implode(' | ', $rebuilt['dim1'])) . '</div>';
                                }
                                if (!empty($rebuilt['dim2'])) {
                                    $evidence_html .= '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#f0e6ff; color:#6d28d9; border-radius:4px; margin-right:6px;">选项二</span> ' . esc_html(implode(' | ', $rebuilt['dim2'])) . '</div>';
                                }
                                if (!empty($rebuilt['dim3'])) {
                                    $evidence_html .= '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#fff5eb; color:#d97706; border-radius:4px; margin-right:6px;">选项三</span> ' . esc_html(implode(' | ', $rebuilt['dim3'])) . '</div>';
                                }
                                // 无法归类的词汇用灰色兜底
                                if (!empty($unmatched)) {
                                    $evidence_html .= '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#f4f4f4; color:#666; border-radius:4px; margin-right:6px;">其他</span> ' . esc_html(implode(' | ', $unmatched)) . '</div>';
                                }
                                // 如果全部都无法匹配（配置被删了），展示原始数据
                                if (empty($evidence_html)) {
                                    $evidence_html = '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#f4f4f4; color:#666; border-radius:4px; margin-right:6px;">历史记录</span> ' . esc_html($raw) . '</div>';
                                }
                            } else {
                                if ($is_oauth_only) {
                                    $oauth_type = get_user_meta($user->ID, 'oauth_new', true);
                                    $type_names = ['weixin' => '微信', 'qq' => 'QQ', 'weibo' => '微博', 'google' => 'Google', 'github' => 'GitHub', 'baidu' => '百度', 'alipay' => '支付宝', 'huawei' => '华为', 'xiaomi' => '小米', 'gitee' => 'Gitee'];
                                    $type_label = $type_names[$oauth_type] ?? ($oauth_type ?: '未知');
                                    $evidence_html = '<div style="margin-bottom:6px;"><span style="display:inline-block; font-size:11px; padding:2px 6px; background:#fff3e0; color:#e65100; border-radius:4px; margin-right:6px;">OAuth</span> 数据来源：' . esc_html($type_label) . ' 社交登录授权（用户未填写问卷）</div>';
                                } else {
                                    $evidence_html = '<span style="color:#d63638;">暂无数据</span>';
                                }
                            }
                        }
                    ?>
                    <tr id="x-user-row-<?php echo esc_attr($user->ID); ?>">
                        <td class="username column-username has-row-actions column-primary data-title="用户名">
                            <?php echo get_avatar($user->ID, 32); ?> 
                            <strong style="margin-left: 10px;"><?php echo esc_html($user->display_name); ?></strong><br>
                            <small style="margin-left: 42px; color: #999;">ID: <?php echo esc_html($user->ID); ?></small>
                        </td>
                        <td data-title="推测年龄"><?php echo esc_html($age); ?></td>
                        <td data-title="推测性别" class="x-sys-gender-td">
                            <?php 
                            if ($manual_gender) {
                                $del_text = esc_html($gender);
                                if (!empty($gender_source)) $del_text .= ' ' . esc_html($gender_source);
                                $tag_color = $manual_gender === '男' ? '#0071a1' : '#d63638';
                                echo '<del style="color:#ccc;font-size:12px;">' . $del_text . '</del> <b style="color:' . $tag_color . ';">' . esc_html($manual_gender) . '</b> <span style="background:#e8f5e9;color:#2e7d32;padding:1px 5px;border-radius:10px;font-size:10px;vertical-align:middle;">✨人工</span>';
                            } else {
                                echo '<span>' . esc_html($gender) . '</span>';
                                if (!empty($gender_source)) {
                                    echo ' <span style="background:#fff3e0;color:#e65100;padding:1px 6px;border-radius:10px;font-size:10px;vertical-align:middle;">' . esc_html($gender_source) . '</span>';
                                }
                            }
                            ?>
                        </td>
                        <td data-title="原始证据">
                            <?php echo $evidence_html; ?>
                        </td>
                        <td data-title="分群标签">
                            <?php
                            $segments = get_user_meta($user->ID, '_xingxy_segments', true);
                            $source_sk = get_user_meta($user->ID, '_source_sk', true);
                            if (!empty($segments) && is_array($segments)) {
                                foreach ($segments as $seg) {
                                    echo '<span style="display:inline-block;background:#eef2ff;color:#4338ca;font-size:12px;font-weight:500;padding:3px 10px;border-radius:4px;border:1px solid #c7d2fe;margin:2px 4px 2px 0;">' . esc_html($seg) . '</span>';
                                }
                                if ($source_sk) {
                                    echo '<div style="margin-top:5px;font-size:11px;color:#9ca3af;"><span style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-family:monospace;font-size:10px;">' . esc_html($source_sk) . '</span></div>';
                                }
                            } else {
                                echo '<span style="color:#d1d5db;">—</span>';
                            }
                            ?>
                        </td>
                        <td data-title="干预动作">
                            <div class="x-override-actions" data-uid="<?php echo esc_attr($user->ID); ?>">
                                <div class="x-status-label" style="margin-bottom: 8px; font-size: 12px;"><?php echo $status_html; ?></div>
                                <div style="display: flex; gap: 8px;">
                                    <button type="button" class="button button-primary action-tag-gender" data-gender="男">一键打标：男</button>
                                    <button type="button" class="button action-tag-gender" style="color: #d63638; border-color: #d63638;" data-gender="女">一键打标：女</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="6">尚未收集到任何用户的问卷或奖励领取记录。</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 极简洗数据交互逻辑 -->
    <script>
    jQuery(document).ready(function($) {
        $('.action-tag-gender').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var wrap = btn.closest('.x-override-actions');
            var uid = wrap.data('uid');
            var targetGender = btn.data('gender');
            
            // 按钮保护
            btn.prop('disabled', true).text('洗算中...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'xingxy_manual_tag_gender',
                    user_id: uid,
                    gender: targetGender,
                    _ajax_nonce: '<?php echo wp_create_nonce("xingxy_tag_nonce"); ?>'
                },
                success: function(res) {
                    if (res.success) {
                        // 1. 无刷新替换当前人的标签状态
                        var labelWrap = wrap.find('.x-status-label');
                        if (targetGender === '男') {
                            labelWrap.html('<span style="color:#0071a1; font-weight:bold;">👨 已由人工确认为男性</span>');
                        } else {
                            labelWrap.html('<span style="color:#d63638; font-weight:bold;">👩 已由人工确认为女性</span>');
                        }
                        
                        // 2. 恢复本行所有按钮
                        wrap.find('.action-tag-gender[data-gender="男"]').text('已重设(男)').prop('disabled', false).removeClass('button-primary');
                        wrap.find('.action-tag-gender[data-gender="女"]').text('已重设(女)').prop('disabled', false).removeClass('button-primary');
                        
                        var row = $('#x-user-row-' + uid);
                        var guessTd = row.find('.x-sys-gender-td');
                        // 获取原始推测文本（可能包含徽章）
                        var oldText = guessTd.find('span').first().text().trim() || guessTd.text().trim().split('\n')[0];
                        var tagColor = targetGender === '男' ? '#0071a1' : '#d63638';
                        guessTd.html('<del style="color:#ccc;font-size:12px;">' + oldText + '</del> <b style="color:' + tagColor + ';">' + targetGender + '</b> <span style="background:#e8f5e9;color:#2e7d32;padding:1px 5px;border-radius:10px;font-size:10px;vertical-align:middle;">✨人工</span>');
                    } else {
                        alert('清洗打标失败: ' + (res.data || '发生未知拦截'));
                        btn.prop('disabled', false).text('重试打标');
                    }
                },
                error: function() {
                    alert('网络通讯断开，请检查网络后重试。');
                    btn.prop('disabled', false).text('重试打标');
                }
            });
        });
    });
    </script>
    <?php
}

// 3. 处理洗数据 / 强制打标 AJAX 回调
add_action('wp_ajax_xingxy_manual_tag_gender', 'xingxy_ajax_manual_tag_gender_handler');
function xingxy_ajax_manual_tag_gender_handler() {
    check_ajax_referer('xingxy_tag_nonce');
    
    // 超级管理员级别限制
    if (!current_user_can('manage_options')) {
        wp_send_json_error('安全警告：权限不足');
    }
    
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $gender  = isset($_POST['gender']) ? sanitize_text_field($_POST['gender']) : '';
    
    if (!$user_id || !in_array($gender, ['男', '女'], true)) {
        wp_send_json_error('参数丢失或格式不规范');
    }
    
    // 直接覆盖或更新洗得最纯净的数据集
    $updated = update_user_meta($user_id, 'xingxy_manual_gender', $gender);
    
    if ($updated !== false) {
        // 其实就算 $updated 是原值返回 false，也可以算成功。
        wp_send_json_success('手工打标数据集写入成功');
    } else {
        // update_user_meta 当值没变的时候会返回 false，因此加一次强制宽容判断
        $current_val = get_user_meta($user_id, 'xingxy_manual_gender', true);
        if ($current_val === $gender) {
            wp_send_json_success('该标签已存在相同标记，无需硬写');
        } else {
             wp_send_json_error('重写数据库键值对发生故障');
        }
    }
}

// ===================================================================
// WP 用户列表：添加「分群标签」列
// ===================================================================

add_filter('manage_users_columns', function ($columns) {
    $columns['xingxy_segments'] = '分群标签';
    return $columns;
});

add_filter('manage_users_custom_column', function ($output, $column_name, $user_id) {
    if ($column_name !== 'xingxy_segments') {
        return $output;
    }
    $segments = get_user_meta($user_id, '_xingxy_segments', true) ?: [];
    $json = esc_attr(json_encode(array_values($segments)));
    $tags_html = '';
    if (!empty($segments) && is_array($segments)) {
        foreach ($segments as $seg) {
            $tags_html .= '<span style="display:inline-block;background:#eef2ff;color:#4338ca;font-size:12px;font-weight:500;padding:2px 8px;border-radius:4px;border:1px solid #c7d2fe;margin:1px 3px 1px 0;">' . esc_html($seg) . '</span>';
        }
    } else {
        $tags_html = '<span style="color:#d1d5db;">—</span>';
    }
    return '<div class="xseg-wrap" data-uid="' . intval($user_id) . '" data-segs="' . $json . '" style="position:relative;">'
         . '<div class="xseg-display" style="cursor:pointer;min-height:24px;" title="点击编辑">' . $tags_html . '</div>'
         . '<div class="xseg-editor" style="display:none;position:absolute;z-index:999;left:0;top:100%;background:#fff;border:1px solid #c7d2fe;border-radius:6px;padding:10px;box-shadow:0 4px 12px rgba(0,0,0,.15);min-width:220px;">'
         . '<div class="xseg-tags" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px;"></div>'
         . '<div class="xseg-quick" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px;"></div>'
         . '<div style="display:flex;gap:4px;align-items:center;">'
         . '<input type="text" class="xseg-input" placeholder="新标签" style="width:90px;padding:2px 6px;font-size:12px;border:1px solid #d1d5db;border-radius:3px;">'
         . '<button type="button" class="xseg-save button button-small button-primary" style="font-size:11px;padding:0 8px;height:26px;">保存</button>'
         . '<button type="button" class="xseg-cancel button button-small" style="font-size:11px;padding:0 8px;height:26px;">取消</button>'
         . '</div>'
         . '<div class="xseg-msg" style="font-size:11px;margin-top:3px;"></div>'
         . '</div>'
         . '</div>';
}, 10, 3);

// 用户列表页注入行内编辑 JS + AJAX endpoint
add_action('admin_footer-users.php', function () {
    if (!current_user_can('manage_options')) return;
    $nonce = wp_create_nonce('xingxy_inline_seg');

    // 从数据库查询所有已使用的标签 slug
    global $wpdb;
    $all_meta = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_xingxy_segments' AND meta_value != ''");
    $known_tags = [];
    foreach ($all_meta as $val) {
        $arr = maybe_unserialize($val);
        if (is_string($arr)) $arr = json_decode($arr, true);
        if (is_array($arr)) {
            foreach ($arr as $s) {
                $s = trim($s);
                if ($s !== '' && !in_array($s, $known_tags)) $known_tags[] = $s;
            }
        }
    }
    sort($known_tags);
    ?>
    <script>
    (function(){
        var nonce = <?php echo json_encode($nonce); ?>;
        var allKnown = <?php echo json_encode(array_values($known_tags)); ?>;

        function renderTags(editor, segs) {
            var box = editor.querySelector('.xseg-tags');
            box.innerHTML = '';
            segs.forEach(function(s) {
                var tag = document.createElement('span');
                tag.style.cssText = 'display:inline-flex;align-items:center;background:#eef2ff;color:#4338ca;font-size:12px;font-weight:500;padding:2px 8px;border-radius:4px;border:1px solid #c7d2fe;';
                tag.textContent = s + ' ';
                var rm = document.createElement('a');
                rm.href = '#'; rm.textContent = '×';
                rm.style.cssText = 'margin-left:4px;color:#a5b4fc;text-decoration:none;font-weight:bold;';
                rm.onclick = function(e) { e.preventDefault(); segs.splice(segs.indexOf(s), 1); renderTags(editor, segs); renderQuick(editor, segs); };
                tag.appendChild(rm);
                box.appendChild(tag);
            });
            if (!segs.length) box.innerHTML = '<span style="color:#9ca3af;font-size:12px;">无标签</span>';
        }

        function renderQuick(editor, segs) {
            var qbox = editor.querySelector('.xseg-quick');
            qbox.innerHTML = '';
            var available = allKnown.filter(function(s){ return segs.indexOf(s) === -1; });
            if (!available.length) return;
            available.forEach(function(s) {
                var btn = document.createElement('span');
                btn.textContent = '+ ' + s;
                btn.style.cssText = 'display:inline-block;cursor:pointer;background:#f0fdf4;color:#16a34a;font-size:11px;font-weight:500;padding:2px 8px;border-radius:4px;border:1px dashed #86efac;';
                btn.onmouseenter = function(){ btn.style.background='#dcfce7'; };
                btn.onmouseleave = function(){ btn.style.background='#f0fdf4'; };
                btn.onclick = function() {
                    if (segs.indexOf(s) === -1) { segs.push(s); renderTags(editor, segs); renderQuick(editor, segs); }
                };
                qbox.appendChild(btn);
            });
        }

        document.addEventListener('click', function(e) {
            var display = e.target.closest('.xseg-display');
            if (!display) return;
            var wrap = display.closest('.xseg-wrap');
            var editor = wrap.querySelector('.xseg-editor');
            var segs = JSON.parse(wrap.dataset.segs || '[]').slice();
            display.style.display = 'none';
            editor.style.display = 'block';
            editor.querySelector('.xseg-msg').textContent = '';
            renderTags(editor, segs);
            renderQuick(editor, segs);
            var input = editor.querySelector('.xseg-input');
            input.value = '';
            input.focus();

            input.onkeydown = function(ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    var v = input.value.trim().replace(/[^a-zA-Z0-9_\-]/g, '');
                    if (v && segs.indexOf(v) === -1) {
                        segs.push(v);
                        if (allKnown.indexOf(v) === -1) allKnown.push(v);
                        renderTags(editor, segs);
                        renderQuick(editor, segs);
                    }
                    input.value = '';
                }
            };

            editor.querySelector('.xseg-cancel').onclick = function() {
                editor.style.display = 'none';
                display.style.display = 'block';
            };

            editor.querySelector('.xseg-save').onclick = function() {
                var btn = this;
                btn.disabled = true;
                btn.textContent = '...';
                var fd = new FormData();
                fd.append('action', 'xingxy_inline_save_segments');
                fd.append('_nonce', nonce);
                fd.append('user_id', wrap.dataset.uid);
                fd.append('segments', JSON.stringify(segs));
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        btn.disabled = false; btn.textContent = '保存';
                        if (r.success) {
                            wrap.dataset.segs = JSON.stringify(segs);
                            var html = '';
                            segs.forEach(function(s){
                                html += '<span style="display:inline-block;background:#eef2ff;color:#4338ca;font-size:12px;font-weight:500;padding:2px 8px;border-radius:4px;border:1px solid #c7d2fe;margin:1px 3px 1px 0;">' + s + '</span>';
                            });
                            if (!html) html = '<span style="color:#d1d5db;">—</span>';
                            display.innerHTML = html;
                            editor.style.display = 'none';
                            display.style.display = 'block';
                            var msg = editor.querySelector('.xseg-msg');
                            msg.style.color = '#22c55e'; msg.textContent = '✓ 已保存';
                        } else {
                            var msg = editor.querySelector('.xseg-msg');
                            msg.style.color = '#ef4444'; msg.textContent = r.data || '保存失败';
                        }
                    });
            };
        });
    })();
    </script>
    <?php
});

// AJAX endpoint：行内保存分群标签
add_action('wp_ajax_xingxy_inline_save_segments', function () {
    check_ajax_referer('xingxy_inline_seg', '_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('权限不足');

    $user_id  = intval($_POST['user_id'] ?? 0);
    $raw      = sanitize_text_field($_POST['segments'] ?? '[]');
    $segments = json_decode(stripslashes($raw), true);
    if (!$user_id || !is_array($segments)) wp_send_json_error('参数错误');

    $segments = array_values(array_unique(array_filter(array_map(function ($s) {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($s));
    }, $segments))));

    update_user_meta($user_id, '_xingxy_segments', $segments);
    wp_send_json_success();
});

// ===================================================================
// 用户列表：按分群标签筛选
// ===================================================================

add_action('restrict_manage_users', function () {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $all_meta = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_xingxy_segments' AND meta_value != ''");
    $known = [];
    foreach ($all_meta as $val) {
        $arr = maybe_unserialize($val);
        if (is_string($arr)) $arr = json_decode($arr, true);
        if (is_array($arr)) foreach ($arr as $s) { $s = trim($s); if ($s !== '' && !in_array($s, $known)) $known[] = $s; }
    }
    sort($known);
    $current = sanitize_text_field($_GET['xseg_filter'] ?? '');
    $no_tag  = isset($_GET['xseg_filter']) && $_GET['xseg_filter'] === '__none__';
    $base_url = admin_url('users.php');
    echo '<select onchange="if(this.value){location.href=\'' . esc_url($base_url) . '?xseg_filter=\'+encodeURIComponent(this.value);}else{location.href=\'' . esc_url($base_url) . '\';}" style="float:none;margin-left:6px;">';
    echo '<option value="">— 按标签筛选 —</option>';
    echo '<option value="__none__"' . ($no_tag ? ' selected' : '') . '>🚫 无标签用户</option>';
    foreach ($known as $tag) {
        echo '<option value="' . esc_attr($tag) . '"' . selected($current, $tag, false) . '>🏷 ' . esc_html($tag) . '</option>';
    }
    echo '</select>';
    if ($current !== '') {
        echo '<a href="' . esc_url($base_url) . '" class="button button-small" style="margin-left:4px;">清除筛选</a>';
    }
});

add_filter('pre_get_users', function ($query) {
    if (!is_admin() || !current_user_can('manage_options')) return;
    $filter = sanitize_text_field($_GET['xseg_filter'] ?? '');
    if ($filter === '') return;

    if ($filter === '__none__') {
        $meta_query = $query->get('meta_query') ?: [];
        $meta_query[] = array(
            'key'     => '_xingxy_segments',
            'compare' => 'NOT EXISTS'
        );
        $query->set('meta_query', $meta_query);
    } else {
        global $wpdb;
        $like = '%"' . $wpdb->esc_like($filter) . '"%';
        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_xingxy_segments' AND meta_value LIKE %s",
            $like
        ));
        if (!empty($user_ids)) {
            $query->set('include', $user_ids);
        } else {
            $query->set('include', [0]);
        }
    }
});

// ===================================================================
// 用户编辑页面：分群标签编辑器
// ===================================================================

add_action('edit_user_profile', 'xingxy_render_segment_editor');
add_action('show_user_profile', 'xingxy_render_segment_editor');

function xingxy_render_segment_editor($user) {
    if (!current_user_can('manage_options')) return;

    $segments  = get_user_meta($user->ID, '_xingxy_segments', true) ?: [];
    $source_sk = get_user_meta($user->ID, '_source_sk', true);
    wp_nonce_field('xingxy_save_segments', '_xingxy_seg_nonce');
    ?>
    <h2>🏷️ 分群标签</h2>
    <table class="form-table">
        <tr>
            <th><label>当前标签</label></th>
            <td>
                <div id="xingxy-seg-list" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
                    <?php if (!empty($segments)): ?>
                        <?php foreach ($segments as $seg): ?>
                            <span class="xingxy-seg-tag" style="display:inline-flex;align-items:center;background:#eef2ff;color:#4338ca;font-size:13px;font-weight:500;padding:4px 10px;border-radius:4px;border:1px solid #c7d2fe;">
                                <?php echo esc_html($seg); ?>
                                <a href="#" class="xingxy-seg-remove" data-seg="<?php echo esc_attr($seg); ?>" style="margin-left:6px;color:#a5b4fc;text-decoration:none;font-weight:bold;font-size:15px;">&times;</a>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span style="color:#9ca3af;">暂无标签</span>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="xingxy_segments_json" id="xingxy-segments-json" value="<?php echo esc_attr(json_encode($segments)); ?>">
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="xingxy-seg-input" placeholder="输入标签 slug，回车添加" style="width:220px;" class="regular-text">
                    <button type="button" id="xingxy-seg-add" class="button">添加</button>
                </div>
                <?php if ($source_sk): ?>
                    <p class="description" style="margin-top:8px;">来源短链：<code><?php echo esc_html($source_sk); ?></code></p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <script>
    (function(){
        var segs = <?php echo json_encode(array_values($segments)); ?>;
        var listEl = document.getElementById('xingxy-seg-list');
        var jsonEl = document.getElementById('xingxy-segments-json');
        var inputEl = document.getElementById('xingxy-seg-input');

        function render() {
            jsonEl.value = JSON.stringify(segs);
            listEl.innerHTML = '';
            if (!segs.length) {
                listEl.innerHTML = '<span style="color:#9ca3af;">暂无标签</span>';
                return;
            }
            segs.forEach(function(s) {
                var span = document.createElement('span');
                span.className = 'xingxy-seg-tag';
                span.style.cssText = 'display:inline-flex;align-items:center;background:#eef2ff;color:#4338ca;font-size:13px;font-weight:500;padding:4px 10px;border-radius:4px;border:1px solid #c7d2fe;';
                span.innerHTML = s + ' <a href="#" class="xingxy-seg-remove" style="margin-left:6px;color:#a5b4fc;text-decoration:none;font-weight:bold;font-size:15px;">&times;</a>';
                span.querySelector('a').addEventListener('click', function(e) {
                    e.preventDefault();
                    segs = segs.filter(function(x){ return x !== s; });
                    render();
                });
                listEl.appendChild(span);
            });
        }

        function addTag() {
            var v = inputEl.value.trim().replace(/[^a-zA-Z0-9_\-]/g, '');
            if (v && segs.indexOf(v) === -1) {
                segs.push(v);
                render();
            }
            inputEl.value = '';
        }

        document.getElementById('xingxy-seg-add').addEventListener('click', addTag);
        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); addTag(); }
        });
    })();
    </script>
    <?php
}

add_action('edit_user_profile_update', 'xingxy_save_segment_editor');
add_action('personal_options_update', 'xingxy_save_segment_editor');

function xingxy_save_segment_editor($user_id) {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['_xingxy_seg_nonce']) || !wp_verify_nonce($_POST['_xingxy_seg_nonce'], 'xingxy_save_segments')) return;

    $raw = sanitize_text_field($_POST['xingxy_segments_json'] ?? '[]');
    $segments = json_decode(stripslashes($raw), true);
    if (!is_array($segments)) $segments = [];

    $segments = array_values(array_unique(array_filter(array_map(function($s) {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($s));
    }, $segments))));

    update_user_meta($user_id, '_xingxy_segments', $segments);
}
