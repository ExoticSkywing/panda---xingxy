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
    
    // 推广链接参数隐藏功能（对所有访客生效）
    if (xingxy_pz('hide_referral_param', true)) {
        wp_enqueue_script(
            'xingxy-referral-hide',
            XINGXY_URL . 'assets/js/referral-hide.js',
            array(),
            XINGXY_VERSION,
            false // 在 head 中加载，尽早执行
        );
    }
    
    // 邀请功能增强样式（仅登录用户）
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
    
    // VIP Promo 样式
    wp_enqueue_style(
        'xingxy-vip-promo',
        XINGXY_URL . 'assets/css/vip-promo.css',
        array(),
        XINGXY_VERSION
    );
    
    // 商城优惠码（在商品详情页和购物车页加载）
    if (is_singular('shop_product') || xingxy_is_shop_page()) {
        wp_enqueue_style(
            'xingxy-shop-coupon',
            XINGXY_URL . 'assets/css/shop-coupon.css',
            array(),
            XINGXY_VERSION
        );
        
        wp_enqueue_script(
            'xingxy-shop-coupon',
            XINGXY_URL . 'assets/js/shop-coupon.js',
            array('jquery'),
            XINGXY_VERSION,
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'xingxy_enqueue_assets');

/**
 * 判断是否在商城相关页面
 */
function xingxy_is_shop_page() {
    // 购物车页面或商城页面
    if (function_exists('zib_shop_is_page')) {
        return zib_shop_is_page();
    }
    return false;
}
