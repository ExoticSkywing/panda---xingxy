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
    if (is_int($new_user_id)) {
        $normalized_new_user_id = $new_user_id > 0 ? $new_user_id : null;
    } elseif (is_string($new_user_id) && preg_match('/\A[1-9][0-9]*\z/', $new_user_id) === 1) {
        $maximum_user_id = (string) PHP_INT_MAX;
        $normalized_new_user_id = (strlen($new_user_id) < strlen($maximum_user_id)
            || (strlen($new_user_id) === strlen($maximum_user_id)
                && strcmp($new_user_id, $maximum_user_id) <= 0))
            ? (int) $new_user_id : null;
    } else {
        $normalized_new_user_id = null;
    }
    if ($normalized_new_user_id === null) {
        return;
    }
    $referrer_id = get_user_meta($normalized_new_user_id, 'referrer_id', true);
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
    $new_user = get_userdata($normalized_new_user_id);
    $new_user_name = $new_user ? $new_user->display_name : '新用户';
    
    // 给推荐人增加积分
    $data = array(
        'value' => $points,
        'type'  => '邀请奖励',
        'desc'  => '推荐用户 ' . $new_user_name . ' 成功注册',
    );
    $enqueue_payload = array(
        'namespace' => 'site.referral',
        'event_id' => 'registration-' . $normalized_new_user_id,
        'value' => $data['value'],
        'type' => $data['type'],
        'desc' => $data['desc'],
    );
    try {
        $shadow_state = apply_filters('xingxy_ecosystem_referral_shadow_state', null);
    } catch (Throwable $exception) {
        return;
    }
    if ($shadow_state !== null && $shadow_state !== true) {
        return;
    }
    try {
        $enqueued = apply_filters(
            'xingxy_ecosystem_referral_enqueue',
            null,
            $normalized_new_user_id,
            $referrer_id,
            $enqueue_payload
        );
    } catch (Throwable $exception) {
        return;
    }

    if ($shadow_state !== null) {
        if ($enqueued === null) {
            return;
        }
        // An installed staging bridge is authoritative. Only its exact
        // pending/processing success shape proves that the event was queued;
        // every other result fails closed without calling a writer.
        if (!is_array($enqueued)
            || array_keys($enqueued) !== array('ok', 'status', 'replayed')
            || $enqueued['ok'] !== true
            || !in_array($enqueued['status'], array('pending', 'processing'), true)
            || !is_bool($enqueued['replayed'])) {
            return;
        }
        return;
    }

    // With no enqueue owner, retain the existing points writer managed/null
    // compatibility path. The legacy writer remains the null fallback.
    $writer_data = $data;
    $writer_data['order_num'] = '';
    $writer_data['event_namespace'] = 'site.referral';
    $writer_data['event_id'] = 'registration-' . $normalized_new_user_id;
    try {
        $managed = apply_filters(
            'xingxy_ecosystem_points_update',
            null,
            $referrer_id,
            $writer_data
        );
    } catch (Throwable $exception) {
        return;
    }

    if ($managed === null) {
        // A null filter result keeps the old theme writer and its payload.
        zibpay_update_user_points($referrer_id, $data);
    } elseif ($managed !== true) {
        return;
    } else {
        // The central ledger owns this event and its durable idempotency.
        // Legacy daily-summary fields wait for an atomic migration slice.
        return;
    }
    
    // 记录到每日明细（使"今日累计"正确显示）
    if (function_exists('zibpay_add_user_free_points_date_detail')) {
        zibpay_add_user_free_points_date_detail($referrer_id, $points);
    }
}
// 优先级设为 20，确保在 referrer_id 保存之后执行
add_action('user_register', 'xingxy_reward_referrer_on_registration', 20);
