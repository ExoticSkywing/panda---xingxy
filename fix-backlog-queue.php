<?php
/**
 * 一次性修复脚本：将旧的 backlog 订单注册到补发队列
 * 运行后删除此文件
 */
require_once dirname(__FILE__) . '/../../../../wp-load.php';

global $wpdb;
$table = $wpdb->prefix . 'zibpay_ordermeta';

echo "<h2>扫描含 backlog 的订单...</h2>";

// 查询所有 order_data 中含有 "backlog" 关键字的记录
$results = $wpdb->get_results(
    "SELECT order_id, meta_value FROM {$table} WHERE meta_key = 'order_data' AND meta_value LIKE '%backlog%'"
);

echo "<p>查询表: {$table}，找到 " . count($results) . " 条记录</p>";

$registered = 0;
foreach ($results as $row) {
    $order_data = maybe_unserialize($row->meta_value);
    if (!is_array($order_data) || empty($order_data['backlog'])) {
        echo "<p>订单 #{$row->order_id} 反序列化失败或无 backlog 字段，跳过</p>";
        continue;
    }
    
    $backlog = $order_data['backlog'];
    echo "<p>订单 #{$row->order_id} backlog 状态: {$backlog['status']}，剩余: {$backlog['remaining_count']}</p>";

    if ($backlog['status'] !== 'pending') {
        echo "<p style='color:orange;'>⏭ 状态不是 pending，跳过</p>";
        continue;
    }
    
    // 获取 card_pass_key
    $order = zibpay::get_order($row->order_id);
    if (!$order) {
        echo "<p style='color:red;'>❌ 无法获取订单数据，跳过</p>";
        continue;
    }
    
    $product_id = $order['post_id'];
    $auto_delivery = zib_shop_get_product_config($product_id, 'auto_delivery');
    $card_pass_key = $auto_delivery['card_pass_key'] ?? '';
    
    echo "<p>商品ID: {$product_id}，card_pass_key: {$card_pass_key}</p>";
    
    if (!$card_pass_key) {
        echo "<p style='color:red;'>❌ 无法获取 card_pass_key，跳过</p>";
        continue;
    }
    
    // 注册到补发队列
    xingxy_register_pending_backlog($row->order_id, $card_pass_key, $backlog['remaining_count']);
    echo "<p style='color:green;'>✅ 订单 #{$row->order_id} 已注册（key={$card_pass_key}, 剩余={$backlog['remaining_count']}）</p>";
    $registered++;
}

echo "<h3>完成！注册了 {$registered} 笔订单。</h3>";
$queue = get_option('xingxy_pending_backlogs', array());
echo "<h3>当前补发队列：</h3><pre>" . print_r($queue, true) . "</pre>";
