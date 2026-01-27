<?php
/**
 * 星小雅高级定制 - 优惠功能扩展
 * 
 * ⚠️ 注意：由于 Zibll 商城模块没有提供钩子接口，
 * 数量限制功能需要直接修改主题文件才能实现。
 * 
 * 本文件记录修改位置，便于主题更新后恢复。
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ========================================
 * 数量限制功能 - 修改文件清单
 * ========================================
 * 
 * 1. term-option.php - 后台添加"数量限制"输入框
 *    路径：/inc/functions/shop/admin/options/term-option.php
 * 
 * 2. discount.php - 添加数量判断函数 + count_limit 字段
 *    路径：/inc/functions/shop/inc/discount.php
 * 
 * 3. order.php - 添加数量限制判断调用
 *    路径：/inc/functions/shop/inc/order.php
 * 
 * 4. main.js + main.min.js - 前端数量限制判断
 *    路径：/inc/functions/shop/assets/js/
 * 
 * 5. dis.php - 添加"满X件可用"标签
 *    路径：/inc/functions/shop/page/dis.php
 * 
 * ========================================
 * 主题更新后恢复方法
 * ========================================
 * 
 * 运行恢复脚本：
 * bash /wp-content/themes/panda/xingxy/scripts/restore-discount.sh
 * 
 * 或手动恢复，参考 /xingxy/patches/ 目录下的补丁文件
 */

// 后续可以添加一些辅助函数
// 例如：检测功能是否已安装、版本检查等
