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
    // 为了简单高效，先一次性取最新 100 条（如数据量爆炸后续可升级无刷新分页或标准 WP_List_Table 分页）
    $args = array(
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key'     => 'xingxy_profile_data',
                'compare' => 'EXISTS'
            ),
            array(
                'key'     => '_xingxy_welcome_rewarded',
                'compare' => 'EXISTS'
            ),
            array(
                'key'     => 'oauth_new',
                'compare' => 'EXISTS'
            )
        ),
        'number'  => 150, 
        'orderby' => 'ID',
        'order'   => 'DESC'
    );
    $user_query = new WP_User_Query($args);
    $users = $user_query->get_results();

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
                    <th scope="col" class="manage-column" style="width: 28%;">🚀 人工干预/降临打标</th>
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
                        <td class="colspanchange" colspan="5">尚未收集到任何用户的问卷或奖励领取记录。</td>
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
