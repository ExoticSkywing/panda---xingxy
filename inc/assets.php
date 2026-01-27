<?php
/**
 * Xingxy 资源加载
 * 
 * 注册和加载 CSS/JS 资源
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 加载前端资源
 */
function xingxy_enqueue_assets() {
    // 只在前台加载
    if (is_admin()) {
        return;
    }
    
    // 邀请功能增强样式
    if (xingxy_pz('enable_referral_points', true)) {
        wp_enqueue_style(
            'xingxy-referral',
            XINGXY_URL . 'assets/css/referral.css',
            array(),
            XINGXY_VERSION
        );
        
        wp_enqueue_script(
            'xingxy-referral',
            XINGXY_URL . 'assets/js/referral.js',
            array('jquery'),
            XINGXY_VERSION,
            true
        );
        
        // 传递用户ID到前端
        $user_id = get_current_user_id();
        if ($user_id) {
            wp_localize_script('xingxy-referral', 'xingxy_referral', array(
                'user_id' => $user_id,
                'referral_url' => add_query_arg('ref', $user_id, home_url('/')),
                'points' => xingxy_pz('referral_points_amount', 10),
            ));
        }
    }
}
add_action('wp_enqueue_scripts', 'xingxy_enqueue_assets');
