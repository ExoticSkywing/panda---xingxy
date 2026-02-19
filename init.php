<?php
/**
 * Xingxy 功能模块
 * 
 * 基于 Panda 子主题架构的自定义功能扩展
 * 
 * @package Xingxy
 * @author  Xingxy Team
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// 模块版本
define('XINGXY_VERSION', '1.0.0');

// 模块目录路径
define('XINGXY_PATH', dirname(__FILE__) . '/');

// 模块目录 URL
define('XINGXY_URL', get_theme_file_uri('/xingxy/'));

/**
 * 获取 Xingxy 配置参数
 * 
 * @param string $name    配置名称
 * @param mixed  $default 默认值
 * @return mixed
 */
function xingxy_pz($name, $default = false) {
    static $options = null;
    if ($options === null) {
        $options = get_option('xingxy_options', array());
    }
    return isset($options[$name]) ? $options[$name] : $default;
}

// 加载配置面板
require_once XINGXY_PATH . 'inc/options.php';

// 加载优惠功能扩展
require_once XINGXY_PATH . 'inc/discount.php';

// 加载推广链接伪装追踪模块（必须在 referral.php 之前）
require_once XINGXY_PATH . 'inc/referral-tracker.php';

// 加载邀请注册送积分功能
require_once XINGXY_PATH . 'inc/referral.php';

// 加载资源（CSS/JS）
require_once XINGXY_PATH . 'inc/assets.php';

// 加载邮件通知修复模块
require_once XINGXY_PATH . 'inc/email-fix.php';

// 加载卡密编辑功能
require_once XINGXY_PATH . 'inc/card-edit.php';

// 加载 VIP 引导双按钮功能
require_once XINGXY_PATH . 'inc/vip-promo.php';

// 加载控制台日志净化功能
require_once XINGXY_PATH . 'inc/console-cleaner.php';

// 加载商城优惠码集成功能
require_once XINGXY_PATH . 'inc/shop-coupon.php';

// === 星盟：前台商品发布系统 ===
// 加载商品发布权限检查
require_once XINGXY_PATH . 'inc/product-capability.php';
// 加载商品发布 AJAX 处理
require_once XINGXY_PATH . 'inc/action-newproduct.php';
// 加载用户中心商品管理入口
require_once XINGXY_PATH . 'inc/user-products.php';

