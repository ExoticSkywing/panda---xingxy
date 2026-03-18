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
                'content' => '推广链接已启用伪装模式，链接形如 <code>?utm_source=share&v=2&_s=xxx&_t=xxx</code>，访客无法判断推广参数位置。用户打开链接后，系统会静默写入追踪 Cookie，即使删除 URL 参数也不影响追踪。',
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
            array(
                'id'         => 'referral_cookie_hours',
                'type'       => 'number',
                'title'      => '推广追踪窗口（小时）',
                'desc'       => '用户打开推广链接后，在此时间内完成注册才算有效推荐。设为 0 表示仅当次浏览有效（与 Session 等同）。',
                'default'    => 24,
                'min'        => 0,
                'max'        => 720,
                'dependency' => array('enable_referral_points', '==', 'true'),
            ),
            array(
                'type'    => 'heading',
                'content' => 'TG Bot 引流',
            ),
            array(
                'id'      => 'enable_tg_points_card',
                'type'    => 'switcher',
                'title'   => '积分任务页显示 TG 引导卡片',
                'desc'    => '开启后在用户中心的"积分任务"页面显示 TG Bot 引流入口',
                'default' => true,
            ),
            array(
                'id'         => 'tg_bot_url',
                'type'       => 'text',
                'title'      => 'TG Bot 链接',
                'desc'       => 'Telegram Bot 的直达链接',
                'default'    => 'https://t.me/moemoji_bot',
                'dependency' => array('enable_tg_points_card', '==', 'true'),
            ),
        ),
    ));
    
    // ==================== 画像采集（小芽精灵） ====================
    CSF::createSection('xingxy_options', array(
        'id'    => 'profile_capture_settings',
        'title' => '画像采集 (免感知)',
        'icon'  => 'fas fa-id-card',
        'fields' => array(
            array(
                'type'    => 'notice',
                'style'   => 'info',
                'content' => '这里配置的选项将无缝注入到用户的「绑定邮箱」软键盘弹窗中。通过视觉化选项，在不干扰转化率的情况下采集用户画像标签（用于 TG Bot 资源定向推送）。<br>最终判定规则：API 微信/QQ 性别做基础兜底，本套问卷的极端选项结果拥有一票否决（覆盖）权。',
            ),
            array(
                'type'    => 'heading',
                'content' => '独立画像弹窗（兜底直注册用户）',
            ),
            array(
                'type'    => 'notice',
                'style'   => 'warning',
                'content' => '直接通过邮箱注册的用户不会触发「绑定邮箱」流程，因此不会看到画像问卷。开启此项后，系统将在登录后自动弹窗引导这类用户完成画像采集。',
            ),
            array(
                'id'      => 'profile_popup_enabled',
                'type'    => 'switcher',
                'title'   => '启用独立画像弹窗',
                'desc'    => '对没有画像数据的已登录用户，弹窗提醒完成问卷（不影响邮箱绑定流程中的问卷注入）',
                'default' => true,
            ),
            array(
                'dependency' => array('profile_popup_enabled', '==', 'true'),
                'title'      => ' ',
                'subtitle'   => '弹窗提示文案',
                'desc'       => '弹窗顶部的引导文案，支持 HTML',
                'class'      => 'compact',
                'id'         => 'profile_popup_text',
                'default'    => '完成下方探索问卷，即可解锁 <b>150 积分盲盒</b> 奖励！',
                'sanitize'   => false,
                'type'       => 'textarea',
            ),
            array(
                'dependency' => array('profile_popup_enabled', '==', 'true'),
                'title'      => ' ',
                'subtitle'   => '提醒周期',
                'id'         => 'profile_popup_expires',
                'class'      => 'compact',
                'desc'       => '多少小时内不重复弹窗提醒（0 = 每次刷新都弹）。用户完成问卷后永不再弹。',
                'default'    => 24,
                'max'        => 2000,
                'min'        => 0,
                'step'       => 2,
                'unit'       => '小时',
                'type'       => 'spinner',
            ),
            array(
                'type'    => 'heading',
                'content' => '问卷维度配置',
            ),
            // 维度一：明星/偶像
            array(
                'id'      => 'profile_dimension_1_title',
                'type'    => 'text',
                'title'   => '维度一问题文案',
                'default' => '1. 说实话，看到谁让你心动过？',
                'desc'    => '不填则显示默认文案',
            ),
            array(
                'id'           => 'profile_dimension_1',
                'type'         => 'group',
                'title'        => '维度一：星标人物（强制选 4 个）',
                'subtitle'     => '用于定大基调（年代界限 + 核心性别）。例如：刘德华、三上悠亚。',
                'button_title' => '添加人物',
                'fields'       => array(
                    array(
                        'id'    => 'name',
                        'type'  => 'text',
                        'title' => '名称',
                    ),
                    array(
                        'id'      => 'image',
                        'type'    => 'upload',
                        'title'   => '正方形头像',
                        'library' => 'image',
                    ),
                    array(
                        'id'      => 'gender_weight',
                        'type'    => 'select',
                        'title'   => '性别倾向',
                        'options' => array(
                            'male'        => '绝对男性 (覆写API)',
                            'male_weak'   => '偏男性',
                            'female'      => '绝对女性 (覆写API)',
                            'female_weak' => '偏女性',
                            'neutral'     => '中性/无法判断',
                        ),
                        'default' => 'neutral',
                    ),
                    array(
                        'id'      => 'age_weight',
                        'type'    => 'select',
                        'title'   => '年代倾向',
                        'options' => array(
                            '85_before' => '85前老哥专属',
                            '85_95'     => '85-95中生代',
                            '95_after'  => '95后新生代',
                            'neutral'   => '全年龄通杀',
                        ),
                        'default' => 'neutral',
                    ),
                ),
            ),
            // 维度二：游戏/IP
            array(
                'id'      => 'profile_dimension_2_title',
                'type'    => 'text',
                'title'   => '维度二问题文案',
                'default' => '你记忆中的经典游戏/IP是？',
                'desc'    => '不填则显示默认文案',
            ),
            array(
                'id'           => 'profile_dimension_2',
                'type'         => 'group',
                'title'        => '维度二：游戏/IP记忆（限选 2-3 个）',
                'subtitle'     => '用于交叉验证年代与消费倾向。例如：中国象棋、泡泡玛特。',
                'button_title' => '添加 IP',
                'fields'       => array(
                    array(
                        'id'    => 'name',
                        'type'  => 'text',
                        'title' => '名称',
                    ),
                    array(
                        'id'      => 'image',
                        'type'    => 'upload',
                        'title'   => '代表图标',
                        'library' => 'image',
                    ),
                    array(
                        'id'      => 'gender_weight',
                        'type'    => 'select',
                        'title'   => '性别倾向',
                        'options' => array(
                            'male'        => '绝对男性 (覆写API)',
                            'male_weak'   => '偏男性',
                            'female'      => '绝对女性 (覆写API)',
                            'female_weak' => '偏女性',
                            'neutral'     => '中性/无法判断',
                        ),
                        'default' => 'neutral',
                    ),
                    array(
                        'id'      => 'age_weight',
                        'type'    => 'select',
                        'title'   => '年代倾向',
                        'options' => array(
                            '85_before' => '85前老哥专属',
                            '85_95'     => '85-95中生代',
                            '95_after'  => '95后新生代',
                            'neutral'   => '全年龄通杀',
                        ),
                        'default' => 'neutral',
                    ),
                ),
            ),
            // 维度三：消费破冰回忆
            array(
                'id'      => 'profile_dimension_3_title',
                'type'    => 'text',
                'title'   => '维度三问题文案',
                'default' => '3. 回忆一下，第一次充钱给了谁？',
                'desc'    => '不填则显示默认文案',
            ),
            array(
                'id'           => 'profile_dimension_3',
                'type'         => 'group',
                'title'        => '维度三：消费破冰库（限选 1-2 个）',
                'subtitle'     => '用于判定未来的引流/变现赛道方向。例如：网吧买点卡、买爱奇艺会员。',
                'button_title' => '添加消费事件',
                'fields'       => array(
                    array(
                        'id'    => 'name',
                        'type'  => 'text',
                        'title' => '文案描述',
                    ),
                    array(
                        'id'      => 'icon',
                        'type'    => 'icon',
                        'title'   => '图标 (FontAwesome)',
                        'default' => 'fas fa-shopping-cart',
                    ),
                    array(
                        'id'      => 'tag',
                        'type'    => 'text',
                        'title'   => '系统内部分组 Tag',
                        'desc'    => '英文标识，如: `high_value`, `tg_nsfw`, `fans`, `video_vip`，用于业务逻辑分流。',
                    ),
                    array(
                        'id'      => 'gender_weight',
                        'type'    => 'select',
                        'title'   => '性别辅助',
                        'options' => array(
                            'male'        => '偏男性',
                            'male_weak'   => '微偏男',
                            'female'      => '偏女性',
                            'female_weak' => '微偏女',
                            'neutral'     => '中性',
                        ),
                        'default' => 'neutral',
                    ),
                ),
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
                'type'    => 'notice',
                'style'   => 'success',
                'content' => '推广链接已内置伪装保护，无需额外配置。系统自动将推广码混入 UTM 等正常参数中，且写入持久化 Cookie，用户无法通过删除 URL 参数来绕过。',
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
