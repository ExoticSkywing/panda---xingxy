<?php
/**
 * Xingxy 邀请注册送积分功能
 * 
 * 当新用户通过推荐链接注册成功后，给推荐人奖励积分
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 用户注册时给推荐人送积分
 * 
 * @param int $new_user_id 新注册用户的 ID
 */
function xingxy_reward_referrer_on_registration($new_user_id) {
    // 检查功能是否启用
    if (!xingxy_pz('enable_referral_points')) {
        return;
    }
    
    // 获取推荐人 ID（由 zibpay-rebate.php 在注册时保存）
    $referrer_id = get_user_meta($new_user_id, 'referrer_id', true);
    if (empty($referrer_id)) {
        return;
    }
    
    // 确保推荐人存在
    $referrer = get_userdata($referrer_id);
    if (!$referrer) {
        return;
    }
    
    // 获取奖励积分数量
    $points = (int) xingxy_pz('referral_points_amount', 10);
    if ($points <= 0) {
        return;
    }
    
    // 检查积分函数是否存在
    if (!function_exists('zibpay_update_user_points')) {
        return;
    }
    
    // 获取新用户信息
    $new_user = get_userdata($new_user_id);
    $new_user_name = $new_user ? $new_user->display_name : '新用户';
    
    // 给推荐人增加积分
    $data = array(
        'value' => $points,
        'type'  => '邀请奖励',
        'desc'  => '推荐用户 ' . $new_user_name . ' 成功注册',
    );
    zibpay_update_user_points($referrer_id, $data);
}
// 优先级设为 20，确保在 referrer_id 保存之后执行
add_action('user_register', 'xingxy_reward_referrer_on_registration', 20);
