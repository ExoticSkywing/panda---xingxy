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

// 加载邀请注册送积分功能
require_once XINGXY_PATH . 'inc/referral.php';

// 加载资源（CSS/JS）
require_once XINGXY_PATH . 'inc/assets.php';
