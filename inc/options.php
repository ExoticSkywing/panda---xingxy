<?php
/**
 * Xingxy 配置面板
 * 
 * 使用 CSF 框架创建可视化配置界面
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 注册 Xingxy 配置面板
 * 在 Zibll 主题加载完成后执行，确保 CSF 框架可用
 */
add_action('zib_require_end', function () {
    // 确保 CSF 框架已加载
    if (!class_exists('CSF')) {
        return;
    }
    
    // 创建配置面板
    CSF::createOptions('xingxy_options', array(
        'menu_title'      => '星小雅高级定制',
        'menu_slug'       => 'xingxy-options',
        'menu_type'       => 'menu',  // 独立顶级菜单
        'menu_capability' => 'manage_options',
        'menu_icon'       => 'dashicons-star-filled',
        'menu_position'   => 99,
        'framework_title' => '星小雅高级定制 <small>v' . XINGXY_VERSION . '</small>',
        'footer_text'     => '星小雅高级定制功能模块',
        'footer_credit'   => '基于 Panda 子主题架构',
        'show_bar_menu'   => false,
        'save_defaults'   => true,
        'ajax_save'       => true,
        'theme'           => 'light',
    ));
    
    // ==================== 优惠设置 ====================
    CSF::createSection('xingxy_options', array(
        'id'    => 'discount_settings',
        'title' => '优惠设置',
        'icon'  => 'fas fa-tags',
        'fields' => array(
            array(
                'type'    => 'heading',
                'content' => '数量限制功能',
            ),
            array(
                'id'      => 'enable_count_limit',
                'type'    => 'switcher',
                'title'   => '启用数量限制',
                'desc'    => '开启后，优惠仅在购买数量达到设定值时生效',
                'default' => true,
            ),
            array(
                'id'         => 'count_limit_default',
                'type'       => 'number',
                'title'      => '默认数量限制',
                'desc'       => '如果优惠未单独设置数量限制，则使用此默认值（0 表示不限制）',
                'default'    => 0,
                'min'        => 0,
                'dependency' => array('enable_count_limit', '==', 'true'),
            ),
        ),
    ));
    
    // ==================== 高级设置 ====================
    CSF::createSection('xingxy_options', array(
        'id'    => 'advanced_settings',
        'title' => '高级设置',
        'icon'  => 'fas fa-cog',
        'fields' => array(
            array(
                'type'    => 'heading',
                'content' => '开发者选项',
            ),
            array(
                'id'      => 'debug_mode',
                'type'    => 'switcher',
                'title'   => '调试模式',
                'desc'    => '开启后将在控制台输出调试信息',
                'default' => false,
            ),
        ),
    ));
    
    // ==================== 关于 ====================
    CSF::createSection('xingxy_options', array(
        'id'    => 'about',
        'title' => '关于',
        'icon'  => 'fas fa-info-circle',
        'fields' => array(
            array(
                'type'    => 'content',
                'content' => '
                    <div style="text-align: center; padding: 20px;">
                        <h2>星小雅高级定制 v' . XINGXY_VERSION . '</h2>
                        <p>基于 Panda 子主题架构的自定义功能模块</p>
                        <p style="color: #999;">专为星小雅网站定制开发</p>
                    </div>
                ',
            ),
        ),
    ));
});
