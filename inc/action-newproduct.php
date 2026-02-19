<?php
/**
 * 星盟 - 前台商品发布 AJAX 处理
 * 
 * 处理合作方前台提交商品的保存逻辑
 * 
 * @package Xingxy
 * @subpackage StarAlliance
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 处理商品保存/草稿 AJAX 请求
 */
function xingxy_ajax_save_product() {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        zib_send_json_error('请先登录');
    }
    
    if (!xingxy_can_publish_product($user_id)) {
        zib_send_json_error('您没有发布商品的权限');
    }
    
    // 获取表单数据
    $product_id = !empty($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $action     = !empty($_POST['action']) ? $_POST['action'] : '';
    $title      = !empty($_POST['product_title']) ? sanitize_text_field($_POST['product_title']) : '';
    $desc       = !empty($_POST['product_desc']) ? sanitize_textarea_field($_POST['product_desc']) : '';
    $content    = !empty($_POST['product_content']) ? $_POST['product_content'] : '';
    $price      = isset($_POST['product_price']) ? round((float) $_POST['product_price'], 2) : 0;
    $shop_cats  = !empty($_POST['shop_cat']) ? array_map('intval', (array) $_POST['shop_cat']) : array();
    $shop_tags  = !empty($_POST['product_tags']) ? sanitize_text_field($_POST['product_tags']) : '';
    $cover_ids  = !empty($_POST['cover_image_ids']) ? sanitize_text_field($_POST['cover_image_ids']) : '';
    
    // 发货相关
    $shipping_type  = !empty($_POST['shipping_type']) ? sanitize_text_field($_POST['shipping_type']) : 'manual';
    $card_pass_key  = !empty($_POST['card_pass_key']) ? sanitize_text_field($_POST['card_pass_key']) : '';
    
    // 编辑权限检查
    if ($product_id) {
        if (!xingxy_can_edit_product($product_id, $user_id)) {
            zib_send_json_error('您没有编辑此商品的权限');
        }
    }
    
    // 提交发布时的校验（草稿模式跳过）
    if ($action === 'product_save') {
        if (empty($title)) {
            zib_send_json_error('请填写商品名称');
        }
        if (mb_strlen($title) < 2) {
            zib_send_json_error('商品名称太短');
        }
        if (mb_strlen($title) > 50) {
            zib_send_json_error('商品名称不能超过50个字');
        }
        if ($price <= 0) {
            zib_send_json_error('请设置一个大于0的价格');
        }
        if (empty($shop_cats)) {
            zib_send_json_error('请选择商品分类');
        }
        if (empty($cover_ids)) {
            zib_send_json_error('请上传至少一张商品封面图');
        }
    }
    
    // 构建 post 数组
    $post_status = 'draft';
    if ($action === 'product_save') {
        // 非管理员提交后进入待审核
        $post_status = is_super_admin($user_id) ? 'publish' : 'pending';
    }
    
    $postarr = array(
        'post_type'    => 'shop_product',
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => $post_status,
        'post_author'  => $user_id,
    );
    
    if ($product_id) {
        $postarr['ID'] = $product_id;
        // 编辑时保留原 author
        $existing = get_post($product_id);
        if ($existing) {
            $postarr['post_author'] = $existing->post_author;
        }
    }
    
    // 保存商品
    $result_id = wp_insert_post($postarr, true);
    
    if (is_wp_error($result_id)) {
        zib_send_json_error('保存失败：' . $result_id->get_error_message());
    }
    if (!$result_id) {
        zib_send_json_error('商品保存失败，请稍后再试');
    }
    
    // 保存商品 meta（product_config 格式，兼容后台 CSF）
    $product_config = get_post_meta($result_id, 'product_config', true);
    if (!is_array($product_config)) {
        $product_config = array();
    }
    
    // 简介
    $product_config['desc'] = $desc;
    
    // 起始价格
    $product_config['start_price'] = $price;
    
    // 发货类型
    $allowed_shipping = array('auto', 'manual');
    if (in_array($shipping_type, $allowed_shipping)) {
        $product_config['shipping_type'] = $shipping_type;
    }
    
    // 卡密备注（自动发货时）
    if ($shipping_type === 'auto') {
        if (!isset($product_config['auto_delivery'])) {
            $product_config['auto_delivery'] = array();
        }
        $product_config['auto_delivery']['type'] = 'card_pass';
        $product_config['auto_delivery']['card_pass_key'] = $card_pass_key;
    }
    
    // 封面图片（gallery 格式，逗号分隔的 attachment IDs）
    if ($cover_ids) {
        $product_config['cover_images'] = $cover_ids;
    }
    
    // 默认值补充（确保后台 CSF 不报错）
    $defaults = array(
        'pay_modo'        => '0',
        'stock_type'      => 'all',
        'stock_all'       => -1,
        'product_options' => array(),
        'params'          => array(),
        'cover_videos'    => array(),
        'main_image'      => '',
        'user_required'   => array(),
    );
    foreach ($defaults as $key => $val) {
        if (!isset($product_config[$key])) {
            $product_config[$key] = $val;
        }
    }
    
    update_post_meta($result_id, 'product_config', $product_config);
    
    // 保存 zibpay_price（创作分成系统依赖此字段）
    update_post_meta($result_id, 'zibpay_price', $price);
    
    // 保存分类
    if (!empty($shop_cats)) {
        wp_set_post_terms($result_id, $shop_cats, 'shop_cat', false);
    }
    
    // 保存标签
    if ($shop_tags) {
        $tags = preg_split("/,|，|\n/", $shop_tags);
        $tags = array_map('trim', $tags);
        $tags = array_filter($tags);
        if ($tags) {
            wp_set_post_terms($result_id, $tags, 'shop_tag', false);
        }
    }
    
    // 构建返回数据
    $send = array('product_id' => $result_id);
    
    switch ($post_status) {
        case 'pending':
            $send['msg']    = '商品已提交，等待管理员审核';
            $send['reload'] = true;
            $send['goto']   = zib_get_user_home_url($user_id);
            break;
        case 'draft':
            $send['msg']  = '草稿已保存';
            $send['time'] = current_time('mysql');
            break;
        default:
            $send['msg']    = '商品已发布';
            $send['reload'] = true;
            $send['goto']   = get_permalink($result_id);
    }
    
    zib_send_json_success($send);
}
add_action('wp_ajax_product_save', 'xingxy_ajax_save_product');
add_action('wp_ajax_product_draft', 'xingxy_ajax_save_product');

/**
 * 处理商品删除请求
 */
function xingxy_ajax_delete_product() {
    $user_id    = get_current_user_id();
    $product_id = !empty($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
    
    if (!$user_id || !$product_id) {
        zib_send_json_error('参数错误');
    }
    
    if (!xingxy_can_edit_product($product_id, $user_id)) {
        zib_send_json_error('您没有删除此商品的权限');
    }
    
    $post = get_post($product_id);
    if ($post->post_status === 'trash') {
        zib_send_json_success(array('msg' => '商品已删除', 'reload' => true));
    }
    
    wp_trash_post($product_id);
    zib_send_json_success(array('msg' => '商品已删除', 'reload' => true));
}
add_action('wp_ajax_product_delete', 'xingxy_ajax_delete_product');
