<?php
/**
 * 用户画像无感采集系统 - 后端逻辑
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 核心打标算法：结合 API 兜底与问卷选项进行画像推断
 * 
 * @param int $user_id
 * @param array $selections 结构: ['dim1' => [..ids..], 'dim2' => [..ids..], 'dim3' => [..tags..]]
 */
function xingxy_calculate_user_profile($user_id, $selections) {
    $dim1_ids = isset($selections['dim1']) ? (array)$selections['dim1'] : [];
    $dim2_ids = isset($selections['dim2']) ? (array)$selections['dim2'] : [];
    $dim3_ids = isset($selections['dim3']) ? (array)$selections['dim3'] : []; // 传配置索引id

    $opts1 = (array)xingxy_pz('profile_dimension_1', []);
    $opts2 = (array)xingxy_pz('profile_dimension_2', []);
    $opts3 = (array)xingxy_pz('profile_dimension_3', []);

    $male_score = 0;
    $female_score = 0;
    $age_scores = ['85_before' => 0, '85_95' => 0, '95_after' => 0];

    $all_selected_opts = [];
    $dim3_tags = [];
    
    // 合并计分遍历
    $all_opts = [
        ['selections' => $dim1_ids, 'data' => $opts1, 'weight' => 10],
        ['selections' => $dim2_ids, 'data' => $opts2, 'weight' => 8],
    ];

    foreach ($all_opts as $dim) {
        foreach ($dim['data'] as $index => $opt) {
            // CSF 的 repeater 项如果没有显式指定 id，索引通常是从 0 开始的数字/字符串
            // 为了安全匹配，前端应回传此索引
            if (in_array((string)$index, $dim['selections'], true)) {
                $all_selected_opts[] = $opt['name'] ?? '';
                $gw = $opt['gender_weight'] ?? 'neutral';
                $aw = $opt['age_weight'] ?? 'neutral';

                if ($gw === 'male') $male_score += $dim['weight'];
                if ($gw === 'male_weak') $male_score += ($dim['weight'] / 2);
                if ($gw === 'female') $female_score += $dim['weight'];
                if ($gw === 'female_weak') $female_score += ($dim['weight'] / 2);
                
                if (isset($age_scores[$aw])) $age_scores[$aw] += $dim['weight'];
            }
        }
    }

    // 维度三（消费倾向）单独计分
    foreach ($opts3 as $index => $opt) {
        if (in_array((string)$index, $dim3_ids, true)) {
            $all_selected_opts[] = $opt['name'] ?? '';
            $gw = $opt['gender_weight'] ?? 'neutral';
            if ($gw === 'male') $male_score += 5;
            if ($gw === 'female') $female_score += 5;
            if (!empty($opt['tag'])) $dim3_tags[] = $opt['tag'];
        }
    }

    // --- 结合 API 的终极性别裁决 ---
    // 假设绑定时 OAuth 拿到的原始性别存在 zib_user_gender 或类似字段，或从第三方 meta 拿
    // 这里以 Zibll 获取或我们假设存的 'oauth_gender' 为准，比如 1男 2女
    $api_gender_raw = get_user_meta($user_id, 'gender', true); // Zibll 原生有些取这个
    if (empty($api_gender_raw)) {
        $api_gender_raw = get_user_meta($user_id, 'oauth_gender', true);
    }
    
    $survey_gender = 'unknown';
    // 问卷绝对清晰判定（有一票否决权）
    if ($male_score >= 10 && $female_score == 0) $survey_gender = '男';
    elseif ($female_score >= 10 && $male_score == 0) $survey_gender = '女';
    elseif ($male_score > $female_score + 10) $survey_gender = '男'; // 分差大说明倾向明显
    elseif ($female_score > $male_score + 10) $survey_gender = '女';
    
    $final_gender = '未知';
    if ($survey_gender !== 'unknown') {
        $final_gender = $survey_gender; // 问卷确切
    } else {
        // API 兜底
        if ($api_gender_raw == 1 || $api_gender_raw == '男' || $api_gender_raw == 'm') $final_gender = '男';
        if ($api_gender_raw == 2 || $api_gender_raw == '女' || $api_gender_raw == 'f') $final_gender = '女';
    }

    // --- 年代裁决 ---
    $final_age = '未知';
    $max_val = max($age_scores);
    if ($max_val > 0) {
        if ($max_val == $age_scores['85_before']) $final_age = '85前';
        elseif ($max_val == $age_scores['85_95']) $final_age = '85-95后';
        elseif ($max_val == $age_scores['95_after']) $final_age = '95后';
    }

    // 组合最终标签
    $result = [
        'gender' => $final_gender,
        'age'    => $final_age,
        'tags'   => $dim3_tags,
        'raw'    => implode(' | ', array_filter($all_selected_opts))
    ];

    update_user_meta($user_id, 'xingxy_profile_data', $result);
    // 可选：同步回传给 Zibll 基础字段
    // update_user_meta($user_id, 'gender', ($final_gender === '男' ? 1 : 2));

    return $result;
}

/**
 * 监听用户绑定邮箱动作，进行画像运算并存储
 */
add_action('zib_user_bind_email', 'xingxy_capture_profile_on_bind', 10, 3);
function xingxy_capture_profile_on_bind($user_id, $captcha_val, $email) {
    if (!$user_id) return;

    // 从 POST 数据中提取隐藏域挂载的问卷记录
    $dim1_str = isset($_POST['profile_dim1']) ? sanitize_text_field($_POST['profile_dim1']) : '';
    $dim2_str = isset($_POST['profile_dim2']) ? sanitize_text_field($_POST['profile_dim2']) : '';
    $dim3_str = isset($_POST['profile_dim3']) ? sanitize_text_field($_POST['profile_dim3']) : '';

    if (!empty($dim1_str) || !empty($dim2_str) || !empty($dim3_str)) {
        $selections = [
            'dim1' => array_filter(explode(',', $dim1_str)),
            'dim2' => array_filter(explode(',', $dim2_str)),
            'dim3' => array_filter(explode(',', $dim3_str))
        ];
        
        // 传递给核心算法处理
        xingxy_calculate_user_profile($user_id, $selections);

        // --- 开始盲盒奖励发放机制 ---
        if (!empty($selections['dim3'])) { // 只认填满了整套问卷到达终点的人
            // 防刷判定点：当前用户是否已领取过迎新盲盒
            $has_rewarded = get_user_meta($user_id, '_xingxy_welcome_rewarded', true);
            if (!$has_rewarded) {
                // 确保依赖函数可用
                if (function_exists('zibpay_update_user_points')) {
                    $reward_points = 150; // 你定的积分总额，盲盒直接发放固定额也可改随机数 rand(100, 200)

                    $points_data = array(
                        'value' => $reward_points, // 加分
                        'type' => '盲盒开启', // 账单类型
                        'desc' => '🎁 星星球首次探索漫游奖励！(神秘盲盒)', // 明细备注，消除打标签顾虑
                    );

                    // 调用原生接口发放金币
                    zibpay_update_user_points($user_id, $points_data);

                    // 防羊毛党：打上死锁戳子，永不能再领
                    update_user_meta($user_id, '_xingxy_welcome_rewarded', true);
                }
            }
        }
    }
}

/**
 * AJAX 接口：暴露问卷选项数据给前端 JS 渲染
 */
add_action('wp_ajax_nopriv_xingxy_get_profile_options', 'xingxy_ajax_get_profile_options');
add_action('wp_ajax_xingxy_get_profile_options', 'xingxy_ajax_get_profile_options');
function xingxy_ajax_get_profile_options() {
    $opts1 = (array)xingxy_pz('profile_dimension_1', []);
    $opts2 = (array)xingxy_pz('profile_dimension_2', []);
    $opts3 = (array)xingxy_pz('profile_dimension_3', []);
    
    // 清洗数据，去掉管理敏感字段（权重等），仅吐给前端展现需要的内容
    $clean = function($opts, $type = 'image') {
        $res = [];
        foreach ($opts as $index => $o) {
            if (empty($o['name'])) continue; // 过滤掉后台CSF默认的空元素字段
            
            if ($type === 'image') {
                $res[] = [
                    'id'    => (string)$index,
                    'name'  => $o['name'],
                    'image' => $o['image'] ?? ''
                ];
            } else {
                $icon_val = 'fas fa-check-circle'; // 缺省值
                if (!empty($o['icon'])) {
                    if (is_array($o['icon']) && !empty($o['icon']['icon'])) {
                        $icon_val = $o['icon']['icon']; // 新版 CSF icon 类型是一个 array
                    } elseif (is_string($o['icon'])) {
                        $icon_val = $o['icon']; // 或者是老版本的直接字符串
                    }
                }
                $res[] = [
                    'id'    => (string)$index,
                    'name'  => $o['name'],
                    'icon'  => $icon_val
                ];
            }
        }
        return $res;
    };

    // 获取自定义标题（有防空回退）
    $title1 = xingxy_pz('profile_dimension_1_title') ?: '1. 说实话，看到谁让你心动过？';
    $title2 = xingxy_pz('profile_dimension_2_title') ?: '2. 如果有台时光机，你想穿越回哪儿？';
    $title3 = xingxy_pz('profile_dimension_3_title') ?: '3. 回忆一下，第一次充钱给了谁？';

    wp_send_json_success([
        'titles' => [
            'dim1' => $title1,
            'dim2' => $title2,
            'dim3' => $title3,
        ],
        'dim1' => $clean($opts1, 'image'),
        'dim2' => $clean($opts2, 'image'),
        'dim3' => $clean($opts3, 'icon')
    ]);
}


/**
 * 在后台用户列表新增【隐形画像】列
 */
add_filter('manage_users_columns', function($columns) {
    // 插入到 "注册日期" 前面
    $new_columns = [];
    foreach ($columns as $key => $value) {
        if ($key == 'registered') {
            $new_columns['xingxy_profile'] = '隐形画像';
        }
        $new_columns[$key] = $value;
    }
    return $new_columns;
});

add_filter('manage_users_custom_column', function($val, $column_name, $user_id) {
    if ($column_name == 'xingxy_profile') {
        $profile = get_user_meta($user_id, 'xingxy_profile_data', true);
        if (empty($profile)) {
            return '<span style="color:#999;font-size:12px;">未采集</span>';
        }

        $gender_icon = '';
        if ($profile['gender'] === '男') $gender_icon = '<span style="color:#2271b1;" title="男"><i class="dashicons dashicons-businessman"></i></span>';
        elseif ($profile['gender'] === '女') $gender_icon = '<span style="color:#d63638;" title="女"><i class="dashicons dashicons-businesswoman"></i></span>';
        
        $age_badge = '<span style="background:#f0f0f1;padding:2px 6px;border-radius:3px;font-size:12px;margin-right:4px;">' . esc_html($profile['age']) . '</span>';
        
        $tags_html = '';
        if (!empty($profile['tags']) && is_array($profile['tags'])) {
            foreach ($profile['tags'] as $t) {
                $tags_html .= '<span style="background:#e5f5fa;color:#007cba;padding:2px 6px;border-radius:3px;font-size:12px;margin-right:2px;">' . esc_html($t) . '</span>';
            }
        }

        $raw_tooltip = esc_attr($profile['raw_selections'] ?? '');
        
        $html = '<div style="margin-bottom:4px;" title="' . $raw_tooltip . '">' . $gender_icon . $age_badge . '</div>';
        $html .= '<div>' . $tags_html . '</div>';
        
        return $html;
    }
    return $val;
}, 10, 3);
