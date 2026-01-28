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
    
    // ==================== 推广设置 ====================
    CSF::createSection('xingxy_options', array(
        'id'    => 'referral_settings',
        'title' => '推广设置',
        'icon'  => 'fas fa-users',
        'fields' => array(
            array(
                'type'    => 'heading',
                'content' => '邀请注册送积分',
            ),
            array(
                'type'    => 'notice',
                'style'   => 'info',
                'content' => '当新用户通过推荐链接（带 <code>?ref=用户ID</code>）注册成功后，推荐人将获得积分奖励。',
            ),
            array(
                'id'      => 'enable_referral_points',
                'type'    => 'switcher',
                'title'   => '启用邀请送积分',
                'desc'    => '开启后，推荐新用户注册成功后给推荐人奖励积分',
                'default' => true,
            ),
            array(
                'id'         => 'referral_points_amount',
                'type'       => 'number',
                'title'      => '奖励积分数量',
                'desc'       => '每成功推荐一个新用户注册，推荐人获得的积分数量',
                'default'    => 10,
                'min'        => 1,
                'max'        => 1000,
                'dependency' => array('enable_referral_points', '==', 'true'),
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
                'content' => '邮件通知控制',
            ),
            array(
                'id'      => 'disable_virtual_shipping_email',
                'type'    => 'switcher',
                'title'   => '禁用虚拟商品发货邮件',
                'desc'    => '开启后，非物流快递发货（自动发货/手动发货）的订单将不发送发货邮件给用户',
                'default' => true,
            ),
            array(
                'type'    => 'heading',
                'content' => '推广链接保护',
            ),
            array(
                'id'      => 'hide_referral_param',
                'type'    => 'switcher',
                'title'   => '隐藏推广链接参数',
                'desc'    => '开启后，访问推广链接时地址栏将自动隐藏 ?ref=xxx 参数，防止用户删除参数绕过推荐关系',
                'default' => true,
            ),
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
