<?php
/**
 * 星盟 - 用户中心"我的商品"管理入口
 * 
 * 在用户中心侧边栏添加商品管理入口
 * 
 * @package Xingxy
 * @subpackage StarAlliance
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 在用户中心侧边栏添加"商品管理"卡片
 * 
 * 使用 user_center_page_sidebar filter 注入
 */
function xingxy_user_center_product_sidebar($con) {
    $cuid = get_current_user_id();
    if (!$cuid || !xingxy_can_publish_product($cuid)) {
        return $con;
    }
    
    $newproduct_url = xingxy_get_newproduct_url();
    if (!$newproduct_url) {
        return $con;
    }
    
    // 统计用户自己的待审核商品
    $q = new WP_Query(array(
        'post_type'      => 'shop_product',
        'post_status'    => 'pending',
        'author'         => $cuid,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ));
    $user_pending = $q->found_posts;
    wp_reset_postdata();
    
    $badge = $user_pending ? '<span class="badg c-yellow ml6">' . $user_pending . '个待审核</span>' : '';
    
    $html = '<div class="zib-widget mb10-sm">';
    $html .= '<div class="flex ac jsb mb10">';
    $html .= '<span class="title-theme">商品管理' . $badge . '</span>';
    $html .= '<a href="' . esc_url($newproduct_url) . '" class="but hollow c-blue px12 p2-10"><i class="fa fa-plus mr3"></i>发布</a>';
    $html .= '</div>';
    
    // 最近5个商品列表
    $products = new WP_Query(array(
        'post_type'      => 'shop_product',
        'post_status'    => array('publish', 'pending', 'draft'),
        'author'         => $cuid,
        'posts_per_page' => 5,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ));
    
    if ($products->have_posts()) {
        while ($products->have_posts()) {
            $products->the_post();
            $status_text = '';
            $status_class = '';
            switch (get_post_status()) {
                case 'pending':
                    $status_text = '待审核';
                    $status_class = 'c-yellow';
                    break;
                case 'draft':
                    $status_text = '草稿';
                    $status_class = 'muted-2-color';
                    break;
                case 'publish':
                    $status_text = '已上架';
                    $status_class = 'c-green';
                    break;
            }
            
            $edit_url = add_query_arg('edit', get_the_ID(), $newproduct_url);
            
            $html .= '<div class="flex ac jsb padding-h6 border-bottom em09">';
            $html .= '<a href="' . esc_url($edit_url) . '" class="flex1 text-ellipsis muted-color">' . get_the_title() . '</a>';
            $html .= '<span class="badg badg-sm ml10 ' . $status_class . '">' . $status_text . '</span>';
            $html .= '</div>';
        }
        wp_reset_postdata();
    } else {
        $html .= '<p class="muted-3-color em09 text-center">还没有商品，快去发布吧</p>';
    }
    
    $html .= '</div>';
    
    return $con . $html;
}
// 优先级 45，在"我的服务"（50）之前
add_filter('user_center_page_sidebar', 'xingxy_user_center_product_sidebar', 45);

/**
 * 获取发布商品页面 URL
 * 
 * 查找使用 "星盟-发布商品" 模板的页面
 * 
 * @return string|false
 */
function xingxy_get_newproduct_url() {
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    
    // 查找使用此页面模板的页面
    $pages = get_pages(array(
        'meta_key'   => '_wp_page_template',
        'meta_value' => 'xingxy/pages/newproduct.php',
        'number'     => 1,
    ));
    
    if ($pages) {
        $url = get_permalink($pages[0]->ID);
    } else {
        $url = false;
    }
    
    return $url;
}
