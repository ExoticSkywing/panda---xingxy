<?php
/**
 * 星盟 - 前台卡密导入 AJAX 处理
 * 
 * 合作商通过前台导入卡密数据，type = partner_custom
 * 后台"全部"列表不显示，需通过"合作商卡密"tab 查看
 * 
 * @package Xingxy
 * @subpackage StarAlliance
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 前台卡密导入 AJAX handler
 */
function xingxy_ajax_import_cardpass() {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        zib_send_json_error('请先登录');
    }
    
    $product_id    = !empty($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $import_data   = !empty($_POST['import_data']) ? $_POST['import_data'] : '';
    $card_pass_key = !empty($_POST['card_pass_key']) ? sanitize_text_field($_POST['card_pass_key']) : '';
    
    // 校验商品存在且属于当前用户
    if (!$product_id) {
        zib_send_json_error('商品ID无效');
    }
    
    $product = get_post($product_id);
    if (!$product || $product->post_type !== 'shop_product') {
        zib_send_json_error('商品不存在');
    }
    
    // 权限检查：必须是商品作者或管理员
    if (!is_super_admin($user_id) && (int) $product->post_author !== $user_id) {
        zib_send_json_error('您没有操作此商品的权限');
    }
    
    if (empty($import_data)) {
        zib_send_json_error('请粘贴需要导入的卡密数据');
    }
    
    // 备注必填
    if (!$card_pass_key) {
        zib_send_json_error('请填写卡密备注');
    }
    
    // 解析数据：按行分割
    $lines = explode("\n", $import_data);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines); // 移除空行
    
    if (empty($lines)) {
        zib_send_json_error('导入数据为空或格式异常');
    }
    
    // 限制单次导入数量
    if (count($lines) > 500) {
        zib_send_json_error('单次最多导入500条，请分批操作');
    }
    
    $success_count = 0;
    $error_count   = 0;
    
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line, 2); // 按空白字符分割为两段：卡号 密码
        
        $_card = !empty($parts[0]) ? trim($parts[0]) : '';
        $_pass = !empty($parts[1]) ? trim($parts[1]) : '';
        
        if (empty($_pass)) {
            $error_count++;
            continue;
        }
        
        // 写入数据库，type = partner_custom（后台全部列表不显示）
        ZibCardPass::add(array(
            'card'     => $_card,
            'password' => $_pass,
            'type'     => 'partner_custom',
            'status'   => '0',
            'meta'     => array('author_id' => $user_id, 'product_id' => $product_id),
            'other'    => $card_pass_key,
        ));
        
        $success_count++;
    }
    
    if ($success_count === 0) {
        zib_send_json_error('导入失败，数据格式错误（共 ' . $error_count . ' 条）');
    }
    
    // 同步更新商品配置的 card_pass_key
    $product_config = get_post_meta($product_id, 'product_config', true);
    if (!is_array($product_config)) {
        $product_config = array();
    }
    if (!isset($product_config['auto_delivery'])) {
        $product_config['auto_delivery'] = array();
    }
    $product_config['auto_delivery']['type']          = 'card_pass';
    $product_config['auto_delivery']['card_pass_key'] = $card_pass_key;
    $product_config['shipping_type'] = 'auto';
    update_post_meta($product_id, 'product_config', $product_config);
    
    // 查询更新后的库存
    $stock = ZibCardPass::get_count(array(
        'other'  => $card_pass_key,
        'status' => '0',
    ));
    
    zib_send_json_success(array(
        'msg'           => '成功导入 ' . $success_count . ' 条卡密',
        'success_count' => $success_count,
        'error_count'   => $error_count,
        'stock'         => (int) $stock,
        'card_pass_key' => $card_pass_key,
    ));
}
add_action('wp_ajax_xingxy_import_cardpass', 'xingxy_ajax_import_cardpass');
