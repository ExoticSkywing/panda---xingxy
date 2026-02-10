<?php
/**
 * Xingxy VIP 引导功能
 * 
 * 为 Shop 商城和 Post 文章提供统一的 VIP 优惠计算逻辑
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 获取最佳 VIP 优惠信息
 * 
 * 计算用户购买此商品时，升级 VIP 能获得的最高优惠。
 * 
 * @param int $post_id 商品 ID
 * @param int $user_id 用户 ID
 * @return array|false 优惠信息数组或 false
 */
function xingxy_get_vip_promo_data($post_id, $user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    // 获取商品配置
    $pay_mate = get_post_meta($post_id, 'posts_zibpay', true); // Post 模式
    if (!$pay_mate) {
        // Shop 模式，尝试获取 Shop 配置
        $product_config = zib_shop_get_product_config($post_id);
        if ($product_config) {
            $pay_mate = $product_config;
            // Shop 模式特定的字段映射
            $pay_mate['pay_price'] = $product_config['start_price'] ?? 0;
            // Shop 的 VIP 价格通常存储在 vip_1_price, vip_2_price 中，或通过折扣计算
            // 注意：Shop 的 VIP 价格逻辑比较复杂，这里简化处理，直接读取配置中的 VIP 价格
            // 如果 Shop 使用的是折扣百分比，这里可能无法直接获取精确价格，需要额外处理
        }
    }

    if (!$pay_mate) {
        return false;
    }

    // 检查是否开启 VIP
    $vip1_enabled = _pz('pay_user_vip_1_s', true);
    $vip2_enabled = _pz('pay_user_vip_2_s', true);

    if (!$vip1_enabled && !$vip2_enabled) {
        return false;
    }

    // 获取当前价格和 VIP 状态
    $current_price = isset($pay_mate['pay_price']) ? (float)$pay_mate['pay_price'] : 0;
    
    // 如果是 Shop 模式，重新获取显示价格（考虑了其他折扣）
    if (get_post_type($post_id) === 'shop_product') {
        $current_price = zib_shop_get_product_display_price($post_id);
    }

    if ($current_price <= 0) {
        return false;
    }

    $user_vip_level = zib_get_user_vip_level($user_id);

    // 寻找最佳优惠
    $best_offer = null;
    $max_savings = 0;

    for ($vi = 1; $vi <= 2; $vi++) {
        if (!_pz('pay_user_vip_' . $vi . '_s', true)) {
            continue;
        }

        // 如果用户已经是该等级或更高，则不推荐
        // (策略：总是推荐用户没拥有的更高等级，即便他已经是 VIP1，也可以推荐 VIP2)
        if ($user_vip_level >= $vi) {
            continue;
        }

        $vip_price = 0;
        
        if (get_post_type($post_id) !== 'shop_product') {
             $vip_price = isset($pay_mate['vip_' . $vi . '_price']) ? (float)$pay_mate['vip_' . $vi . '_price'] : $current_price;
        } else {
             // Shop 模式获取 VIP 价格
             // 1. 尝试获取固定 VIP 价格
             $vip_price = isset($pay_mate['vip_' . $vi . '_price']) ? (float)$pay_mate['vip_' . $vi . '_price'] : 0;
             
             // 2. 如果没有固定价格，尝试查找 VIP 折扣
             if ($vip_price <= 0 && function_exists('zib_shop_get_product_discount')) {
                 $discounts = zib_shop_get_product_discount($post_id);
                 foreach ($discounts as $discount) {
                     // 检查折扣是否有 VIP 限制
                     $user_limit = $discount['user_limit'] ?? '';
                     $target_vip = '';
                     if ($user_limit === 'vip') $target_vip = 1;
                     if ($user_limit === 'vip_2') $target_vip = 2;
                     
                     // 如果该折扣对应当前循环的 VIP 等级 (VIP2 可以享受 VIP1 折扣吗？通常不能叠加，或者有优先级)
                     // Zibll 逻辑：VIP2 可以享受 VIP2 专属，也可以享受 VIP (如果 user_limit check 通过)
                     // 这里简化：寻找匹配当前等级的专属折扣
                     if ($target_vip == $vi) {
                         // 计算优惠后的价格
                         // 基础逻辑：在当前价格基础上应用此折扣
                         // 注意：这只是估算，因为可能存在其他叠加规则
                         if ($discount['discount_type'] === 'reduction') {
                             $vip_price = $current_price - (float)$discount['reduction_amount'];
                         } elseif ($discount['discount_type'] === 'discount') {
                             $vip_price = $current_price * ((float)$discount['discount_amount'] / 10);
                         }
                         
                         // 只要找到一个符合的 VIP 折扣即可 (Zibll 通常只取最优或排序后的)
                         if ($vip_price > 0) break;
                     }
                 }
             }
             
             // 如果仍然没有 VIP 价格，则认为该等级无优惠
             if ($vip_price <= 0) {
                 continue;
             }
        }

        if ($vip_price > 0 && $vip_price < $current_price) {
            $savings = $current_price - $vip_price;
            if ($savings > $max_savings) {
                $max_savings = $savings;
                $best_offer = [
                    'vip_level' => $vi,
                    'vip_name'  => _pz('pay_user_vip_' . $vi . '_name'),
                    'vip_price' => $vip_price,
                    'savings'   => round($savings, 2),
                    'original_price' => $current_price
                ];
            }
        }
    }

    return $best_offer;
}
