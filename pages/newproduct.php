<?php
/**
 * Template name: 星盟-发布商品
 * Description:   合作方前台发布商城商品的页面模板
 * 
 * @package Xingxy
 * @subpackage StarAlliance
 */

// 引入核心
require_once get_theme_file_path('/inc/code/require.php');
require_once get_theme_file_path('/inc/code/file.php');

$cuid = get_current_user_id();

// 未登录跳转
if (!$cuid) {
    wp_safe_redirect(home_url());
    exit;
}

// 权限检查
if (!xingxy_can_publish_product($cuid)) {
    get_header();
    echo '<main role="main" class="container"><div class="zib-widget" style="padding:60px 20px;text-align:center;">';
    echo '<div class="em12 mb20">🚫 暂无发布商品的权限</div>';
    echo '<p class="muted-2-color">请联系管理员开通合作方权限</p>';
    echo '</div></main>';
    get_footer();
    exit;
}

// 编辑模式
$edit_id   = !empty($_REQUEST['edit']) ? (int) $_REQUEST['edit'] : 0;
$edit_post = null;
$is_edit   = false;

if ($edit_id) {
    $edit_post = get_post($edit_id);
    if (empty($edit_post->ID) || $edit_post->post_type !== 'shop_product' || !xingxy_can_edit_product($edit_post, $cuid)) {
        wp_safe_redirect(home_url());
        exit;
    }
    $is_edit = true;
}

// 准备表单数据
$in = array(
    'ID'            => '',
    'post_title'    => '',
    'post_content'  => '',
    'desc'          => '',
    'price'         => '',
    'cover_ids'     => '',
    'shipping_type' => 'manual',
    'auto_type'     => 'fixed',      // 自动发货子类型：fixed / card_pass
    'fixed_content' => '',           // 固定内容
    'card_pass_key' => '',           // 卡密备注关键词
    'tags'          => '',
    'post_status'   => '',
);

if ($is_edit) {
    $in['ID']           = $edit_post->ID;
    $in['post_title']   = $edit_post->post_title;
    $in['post_content'] = $edit_post->post_content;
    $in['post_status']  = $edit_post->post_status;
    
    $config = get_post_meta($edit_post->ID, 'product_config', true);
    if (is_array($config)) {
        $in['desc']          = isset($config['desc']) ? $config['desc'] : '';
        $in['price']         = isset($config['start_price']) ? $config['start_price'] : '';
        $in['cover_ids']     = isset($config['cover_images']) ? $config['cover_images'] : '';
        $in['shipping_type'] = isset($config['shipping_type']) ? $config['shipping_type'] : 'manual';
        
        // 自动发货配置
        if (isset($config['auto_delivery']) && is_array($config['auto_delivery'])) {
            $ad = $config['auto_delivery'];
            $in['auto_type']     = isset($ad['type']) ? $ad['type'] : 'fixed';
            $in['fixed_content'] = isset($ad['fixed_content']) ? $ad['fixed_content'] : '';
            $in['card_pass_key'] = isset($ad['card_pass_key']) ? $ad['card_pass_key'] : '';
        }
        
        // 推广返佣配置
        if (isset($config['rebate']) && is_array($config['rebate'])) {
            $rb = $config['rebate'];
            $in['rebate_type']       = isset($rb['type']) ? $rb['type'] : '';
            $in['rebate_all_ratio']  = isset($rb['all_ratio']) ? $rb['all_ratio'] : 0;
            $in['rebate_vip1_ratio'] = isset($rb['vip_1_ratio']) ? $rb['vip_1_ratio'] : 0;
            $in['rebate_vip2_ratio'] = isset($rb['vip_2_ratio']) ? $rb['vip_2_ratio'] : 0;
            $in['rebate_all_fixed']  = isset($rb['all_fixed']) ? $rb['all_fixed'] : 0;
            $in['rebate_vip1_fixed'] = isset($rb['vip_1_fixed']) ? $rb['vip_1_fixed'] : 0;
            $in['rebate_vip2_fixed'] = isset($rb['vip_2_fixed']) ? $rb['vip_2_fixed'] : 0;
        }
    }
    
    // 标签
    $tags = get_the_terms($edit_post->ID, 'shop_tag');
    if ($tags && !is_wp_error($tags)) {
        $in['tags'] = implode(', ', array_column((array) $tags, 'name'));
    }
}

// 计算卡密库存（仅编辑时且有 card_pass_key）
$card_stock = 0;
if ($in['card_pass_key'] && class_exists('ZibCardPass')) {
    $card_stock = ZibCardPass::get_count(array(
        'other'  => $in['card_pass_key'],
        'status' => '0',
    ));
}

// 封面图片预览数据
$cover_preview_html = '';
if ($in['cover_ids']) {
    $ids = explode(',', $in['cover_ids']);
    foreach ($ids as $aid) {
        $aid = (int) trim($aid);
        if ($aid) {
            $img_url = wp_get_attachment_image_url($aid, 'medium');
            if ($img_url) {
                $cover_preview_html .= '<div class="xingxy-gallery-item" data-id="' . $aid . '">';
                $cover_preview_html .= '<img src="' . esc_url($img_url) . '" alt="">';
                $cover_preview_html .= '<span class="xingxy-gallery-remove" title="移除">&times;</span>';
                $cover_preview_html .= '</div>';
            }
        }
    }
}

// 编辑器按钮 —— 复用文章发布页的 TinyMCE 自定义工具栏
// 图片上传
if (zib_current_user_can('new_post_upload_img')) {
    add_filter('tinymce_upload_img', '__return_true');
}
// 视频上传
if (zib_current_user_can('new_post_upload_video')) {
    add_filter('tinymce_upload_video', '__return_true');
}
// 文件上传
if (zib_current_user_can('new_post_upload_file')) {
    add_filter('tinymce_upload_file', '__return_true');
}
// 嵌入视频
if (zib_current_user_can('new_post_iframe_video')) {
    add_filter('tinymce_iframe_video', '__return_true');
}
// 隐藏内容
if (zib_current_user_can('new_post_hide')) {
    add_filter('tinymce_hide', '__return_true');
}

// 不显示悬浮按钮
remove_action('wp_footer', 'zib_float_right');
remove_action('wp_footer', 'zib_footer_tabbar');

// 建议搜索引擎不抓取
add_filter('wp_robots', 'zib_robots_no_robots');

// 强制启用 sidebar 两栏布局（Zibll 默认根据 zib_is_show_sidebar 决定）
add_filter('zib_is_show_sidebar', '__return_true');

// 加载编辑文章的 CSS
add_filter('featured_image_edit', '__return_true');

// 修复暗色模式编辑器文字颜色
// Zibll 原生只对 editor_id='post_content' 注入暗色 body_class，
// 商品编辑器用的是 'product_content'，需要扩展支持
add_filter('tiny_mce_before_init', function($mceInit, $editor_id) {
    if ('product_content' === $editor_id) {
        $mceInit['body_class'] .= ' ' . zib_get_theme_mode();
    }
    return $mceInit;
}, 10, 2);


/**
 * 递归渲染分类复选框
 */
if (!function_exists('xingxy_render_term_checkboxes')) {
function xingxy_render_term_checkboxes($terms, $checked_ids, $taxonomy, $depth = 0) {
    foreach ($terms as $term) {
        $indent = $depth > 0 ? ' style="margin-left:' . ($depth * 20) . 'px;"' : '';
        $checked = in_array($term->term_id, $checked_ids) ? ' checked="checked"' : '';
        echo '<div' . $indent . '><label class="muted-color font-normal pointer">';
        echo '<input value="' . $term->term_id . '"' . $checked . ' type="checkbox" name="shop_cat[]">';
        echo '<span class="ml6">' . esc_html($term->name) . '</span>';
        echo '</label></div>';
        
        // 递归子分类
        $children = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'parent'     => $term->term_id,
        ));
        if ($children && !is_wp_error($children)) {
            xingxy_render_term_checkboxes($children, $checked_ids, $taxonomy, $depth + 1);
        }
    }
}
} // end function_exists check

get_header();
?>
<main role="main" class="container">
        <div class="content-wrap newposts-wrap">
            <div class="content-layout">
                <div class="zib-widget full-widget-sm editor-main-box" style="min-height:60vh;">
                    
                    <?php
                    // 非编辑模式时展示"我的商品"完整管理列表
                    if (!$is_edit):
                        $my_products = new WP_Query(array(
                            'post_type'      => 'shop_product',
                            'post_status'    => array('publish', 'pending', 'draft'),
                            'author'         => $cuid,
                            'posts_per_page' => -1,
                            'orderby'        => 'modified',
                            'order'          => 'DESC',
                        ));
                        
                        // 保存发布页 URL（循环中 $post 会被覆盖）
                        $page_url = get_permalink();
                        
                        if ($my_products->have_posts()):
                    ?>
                    <div class="mb20" id="xingxy-my-products">
                        <div class="flex ac jsb mb10">
                            <span class="title-theme">我的商品 <span class="muted-3-color em09">(<?php echo $my_products->found_posts; ?>个)</span></span>
                        </div>
                        <?php while ($my_products->have_posts()): $my_products->the_post();
                            $p_id = get_the_ID();
                            $p_status = get_post_status();
                            $p_edit_url = add_query_arg('edit', $p_id, $page_url);
                            
                            // 状态标签
                            $s_text = '';
                            $s_class = '';
                            switch ($p_status) {
                                case 'pending':  $s_text = '待审核'; $s_class = 'c-yellow'; break;
                                case 'draft':    $s_text = '草稿';   $s_class = 'muted-2-color'; break;
                                case 'publish':  $s_text = '已上架'; $s_class = 'c-green'; break;
                            }
                            
                            // 销量
                            $sales = (int) get_post_meta($p_id, 'sales_volume', true);
                        ?>
                        <div class="flex ac jsb padding-h8 border-bottom">
                            <div class="flex1 text-ellipsis mr10">
                                <a href="<?php echo esc_url($p_edit_url); ?>" class="muted-color"><?php the_title(); ?></a>
                            </div>
                            <div class="flex ac flex0">
                                <?php if ($sales > 0): ?>
                                <span class="muted-3-color em09 mr10"><?php echo $sales; ?>售</span>
                                <?php endif; ?>
                                <span class="badg badg-sm mr6 <?php echo $s_class; ?>"><?php echo $s_text; ?></span>
                                <a href="<?php echo esc_url($p_edit_url); ?>" class="em09 c-blue" title="编辑"><i class="fa fa-pencil"></i></a>
                                <?php if ($p_status === 'publish'): ?>
                                <a href="<?php echo get_permalink($p_id); ?>" class="em09 ml6 muted-2-color" target="_blank" title="查看"><i class="fa fa-external-link"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; wp_reset_postdata(); ?>
                    </div>
                    
                    <div class="text-center mt10 mb20" style="padding:12px 0;border-top:2px dashed var(--muted-3-color,#555);">
                        <span class="em12" style="color:var(--color-blue,#2193f7);"><i class="fa fa-plus-circle mr6"></i>发布新商品</span>
                    </div>
                    <?php
                        endif; // have_posts
                    endif; // !$is_edit
                    ?>
                    
                    <!-- 商品名称 -->
                    <div class="relative newposts-title">
                        <textarea type="text" class="line-form-input input-lg new-title" name="product_title" tabindex="1" rows="1" autoHeight="true" maxHeight="78" placeholder="请输入商品名称"><?php echo esc_attr($in['post_title']); ?></textarea>
                        <i class="line-form-line"></i>
                    </div>
                    
                    <!-- 商品简介 -->
                    <div class="mt10 mb20">
                        <textarea class="form-control" name="product_desc" rows="2" placeholder="一句话介绍商品（选填）" tabindex="2"><?php echo esc_textarea($in['desc']); ?></textarea>
                    </div>
                    
                    <!-- 商品详情（TinyMCE 编辑器） -->
                    <?php
                    $editor_settings = array(
                        'textarea_rows'  => 20,
                        'editor_height'  => (wp_is_mobile() ? 400 : 500),
                        'media_buttons'  => false,
                        'default_editor' => 'tinymce',
                        'quicktags'      => false,
                        'editor_css'     => '<link rel="stylesheet" href="' . ZIB_TEMPLATE_DIRECTORY_URI . '/css/new-posts.min.css?ver=' . THEME_VERSION . '" type="text/css">',
                        'teeny'          => false,
                        'tinymce'        => array(
                            'placeholder' => '请输入商品详情描述',
                        ),
                    );
                    wp_editor($in['post_content'], 'product_content', $editor_settings);
                    ?>
                    
                    <?php if ($is_edit): ?>
                    <div class="em09 flex ac hh mt10">
                        <span class="view-btn mr6 mt6">
                            <a class="but c-blue" href="<?php echo get_permalink($edit_post); ?>"><i class="fa fa-eye"></i> 预览商品</a>
                        </span>
                        <span class="modified-time mt6">
                            <span class="badg">最后保存：<?php echo $edit_post->post_modified; ?></span>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- 侧边栏 -->
        <div class="sidebar show-sidebar" style="align-self:flex-start;">
            
            <!-- 封面图片 -->
            <div class="zib-widget mb10-sm">
                <div class="title-theme mb10">商品封面 <span class="muted-3-color em09">（必填）</span></div>
                <div id="xingxy-gallery-container">
                    <div id="xingxy-gallery-preview" class="xingxy-gallery-grid">
                        <?php echo $cover_preview_html; ?>
                    </div>
                    <input type="hidden" name="cover_image_ids" id="cover_image_ids" value="<?php echo esc_attr($in['cover_ids']); ?>">
                    <button type="button" id="xingxy-gallery-btn" class="but hollow c-blue mt10" style="width:100%;">
                        <i class="fa fa-plus mr6"></i>选择/上传图片
                    </button>
                    <p class="muted-3-color em09 mt6">正方形图片效果最佳，可多选</p>
                </div>
            </div>
            
            <!-- 商品分类 -->
            <div class="zib-widget mb10-sm">
                <div class="title-theme mb10">商品分类 <span class="muted-3-color em09">（必填）</span></div>
                <div class="mini-scrollbar" style="max-height:200px;overflow-y:auto;">
                    <?php
                    $checked_cat_ids = array();
                    if ($is_edit) {
                        $terms = get_the_terms($edit_post->ID, 'shop_cat');
                        if ($terms && !is_wp_error($terms)) {
                            $checked_cat_ids = array_column((array) $terms, 'term_id');
                        }
                    }
                    $all_cats = get_terms(array(
                        'taxonomy'   => 'shop_cat',
                        'hide_empty' => false,
                        'parent'     => 0,
                    ));
                    if ($all_cats && !is_wp_error($all_cats)) {
                        xingxy_render_term_checkboxes($all_cats, $checked_cat_ids, 'shop_cat');
                    } else {
                        echo '<p class="muted-3-color em09">暂无可选分类</p>';
                    }
                    ?>
                </div>
            </div>
            
            <!-- 商品标签 -->
            <div class="zib-widget mb10-sm">
                <div class="title-theme mb10">商品标签</div>
                <textarea class="form-control" rows="2" name="product_tags" placeholder="每个标签用逗号隔开" tabindex="5"><?php echo esc_textarea($in['tags']); ?></textarea>
            </div>
            
            <!-- 价格设置 -->
            <div class="zib-widget mb10-sm">
                <div class="title-theme mb10">价格设置 <span class="muted-3-color em09">（必填）</span></div>
                <div class="flex ab">
                    <div class="muted-color mb6 flex0">
                        <i class="fa fa-rmb mr6"></i>商品价格
                    </div>
                    <input type="number" name="product_price" value="<?php echo esc_attr($in['price']); ?>" step="0.01" min="0" style="padding: 0;" class="line-form-input em2x key-color text-right" placeholder="0.00" tabindex="3">
                    <i class="line-form-line"></i>
                </div>
                <p class="muted-3-color em09 mt6">实际售价，管理员可在后台调整 VIP 价格等</p>
            </div>
            
            
            <input type="hidden" name="product_id" value="<?php echo (int) $in['ID']; ?>">
        </div>
    </div>

    <!-- 发货设置（独立全宽区块，脱离 sidebar 麻痹范围） -->
    <div class="zib-widget dependency-box" style="margin-top:15px;">
        <div class="title-theme mb10">发货设置</div>
        
        <style>
            .shipping-option-label {
                border: 1px solid var(--muted-border-color);
                border-radius: 4px;
                padding: 6px 15px;
                margin-right: 10px;
                cursor: pointer;
                transition: all 0.3s;
                opacity: 0.8;
                display: inline-block;
            }
            .shipping-option-label:hover {
                border-color: var(--theme-color);
                opacity: 1;
            }
            .shipping-option-label:has(input:checked) {
                border-color: var(--theme-color);
                background: rgba(var(--theme-color-rgb), 0.1);
                color: var(--theme-color);
                font-weight: bold;
                opacity: 1;
            }
            .shipping-option-label input[type="radio"] {
                display: none;
            }
            @media (max-width: 768px) {
                .shipping-option-label {
                    padding: 5px 10px;
                    margin-right: 6px;
                    margin-bottom: 6px;
                    font-size: 13px;
                }
            }
        </style>

        <!-- 发货类型 -->
        <div class="mb10">
            <label class="shipping-option-label">
                <input type="radio" name="shipping_type" value="auto" <?php checked($in['shipping_type'], 'auto'); ?>> 自动发货
            </label>
            <label class="shipping-option-label">
                <input type="radio" name="shipping_type" value="manual" <?php checked($in['shipping_type'], 'manual'); ?>> 手动发货
            </label>
        </div>
        
        <!-- 自动发货配置 -->
        <div id="xingxy-auto-delivery-box" style="<?php echo $in['shipping_type'] !== 'auto' ? 'display:none;' : ''; ?>">
            
            <div class="mb10" style="border-bottom:1px dashed var(--muted-border-color);padding-bottom:10px;">
                <label class="shipping-option-label" style="padding: 4px 12px; margin-right: 6px;">
                    <input type="radio" name="auto_type" value="fixed" <?php checked($in['auto_type'], 'fixed'); ?>> 固定内容
                </label>
                <label class="shipping-option-label" style="padding: 4px 12px; margin-right: 6px;">
                    <input type="radio" name="auto_type" value="card_pass" <?php checked($in['auto_type'], 'card_pass'); ?>> 卡密
                </label>
            </div>
            
            <!-- 固定内容区 -->
            <div id="xingxy-fixed-content-box" style="<?php echo $in['auto_type'] !== 'fixed' ? 'display:none;' : ''; ?>">
                <p class="muted-color em09 mb6"><i class="fa fa-info-circle mr3"></i>所有买家将收到相同内容，支持HTML</p>
                <textarea class="form-control" name="fixed_content" rows="5" placeholder="输入发送给用户的内容，例如网盘链接、教程地址等"><?php echo esc_textarea($in['fixed_content']); ?></textarea>
            </div>
            
            <!-- 卡密区 -->
            <div id="xingxy-cardpass-box" style="<?php echo $in['auto_type'] !== 'card_pass' ? 'display:none;' : ''; ?>">
                
                <div class="mb10">
                    <p class="muted-color em09 mb6"><i class="fa fa-tag mr3"></i>卡密备注 <span style="color:var(--color-red);">*</span></p>
                    <input type="text" class="form-control" name="card_pass_key" id="xingxy-card-pass-key" value="<?php echo esc_attr($in['card_pass_key']); ?>" placeholder="例如：谷歌账号、苹果ID、VPN月卡">
                    <p class="muted-3-color em09 mt3">用于区分不同商品的卡密，发货时按此备注匹配</p>
                </div>
                
                <!-- 库存 + 导入：优化比例 4:6 -->
                <style>
                    /* 发货区域分栏响应式 */
                    .xingxy-delivery-row {
                        display: flex;
                        gap: 20px;
                        flex-wrap: wrap;
                    }
                    .xingxy-delivery-col-left {
                        flex: 1 1 350px;
                        border: 1px solid var(--muted-border-color);
                        border-radius: 8px;
                        padding: 15px;
                        background: rgba(0,0,0,0.01);
                        min-width: 0;
                        max-width: 100%;
                    }
                    .xingxy-delivery-col-right {
                        flex: 1.5 1 450px; /* 权重1.5，实现近似 2:3 的视觉比例 */
                        padding: 15px;
                        border: 1px solid var(--muted-border-color);
                        border-radius: 8px;
                        min-width: 0;
                        max-width: 100%;
                    }
                    @media (max-width: 768px) {
                        .xingxy-delivery-col-left, .xingxy-delivery-col-right {
                            flex: 1 1 100%;
                            border-left: 1px solid var(--muted-border-color) !important;
                            padding-left: 15px !important;
                            max-width: 100%;
                        }
                    }
                </style>
                <!-- 库存 + 导入：优化比例 4:6 -->
                <div class="xingxy-delivery-row">
                    <!-- 左侧导入区 -->
                    <div class="xingxy-delivery-col-left">
                        <p class="muted-color em09 mb10"><i class="fa fa-info-circle mr3"></i>支持自由拼接形式（如：<code class="c-blue">长串账号信息作为卡号</code>，<code class="c-blue">兑换/登录说明作为卡密</code>），两者间用<code class="c-red">单个空格</code>分隔即可</p>
                        <textarea id="xingxy-cardpass-data" class="form-control" rows="12" placeholder="粘贴卡密数据，一行一条。支持长信息自由组合配对，中间用空格隔开。&#10;&#10;示例 1（常规）：&#10;account01@mail.com P@ssw0rd123&#10;&#10;示例 2（超级组合：极长字符整体作卡号，网址作卡密）：&#10;AnastasiaParmar@gmail.com----ek8ondgru9----AnastasiaParmar657689@neiar.xyz----jyhjhtumwudslm6fz4uxoigtalmn 2fa.cn" style="resize:vertical;font-size:13px;border:none; border-bottom: 2px solid var(--muted-3-color); border-radius: 6px; padding: 12px; transition: border 0.3s;"></textarea>
                        <style>
                            #xingxy-cardpass-data:focus {
                                border-bottom-color: var(--theme-color);
                                box-shadow: 0 0 10px rgba(var(--theme-color-rgb), 0.1);
                            }
                            .xingxy-mobile-scroll-hint { display: none; }
                            @media (max-width: 768px) {
                                .xingxy-mobile-scroll-hint.is-show {
                                    display: inline-block !important;
                                    animation: xingxy-scroll-pulse 2s infinite;
                                }
                            }
                            @keyframes xingxy-scroll-pulse {
                                0% { opacity: 0.4; transform: translateX(0); }
                                50% { opacity: 1; transform: translateX(-3px); color: var(--color-blue); }
                                100% { opacity: 0.4; transform: translateX(0); }
                            }
                        </style>
                        <div class="flex ac mt10">
                            <span class="flex1 muted-3-color em09"><i class="fa fa-info-circle mr3"></i>导入后立即生效，无需另外保存</span>
                            <button type="button" id="xingxy-import-cardpass-btn" class="but jb-blue padding-lg">
                                <i class="fa fa-cloud-upload mr6"></i>确认导入
                            </button>
                        </div>
                        <div id="xingxy-import-result" class="mt6 em09" style="display:none;"></div>
                    </div>
                    
                    <!-- 右侧库存与列表 -->
                    <div class="xingxy-delivery-col-right">
                        <div class="flex ac jc mb10" style="padding:15px;border-radius:8px;background:var(--muted-border-color);box-shadow:inset 0 0 10px rgba(0,0,0,0.02);">
                            <span class="muted-color font-bold"><i class="fa fa-database mr6"></i>当前库存总数：</span>
                            <?php
                            $init_stock_color = $card_stock > 0 ? '#67C23A' : 'var(--muted-3-color)';
                            $init_stock_shadow = $card_stock > 0 ? 'text-shadow: 0 0 10px rgba(103,194,58,0.3);' : '';
                            ?>
                            <span id="xingxy-card-stock" class="ml10" style="font-size:22px;font-weight:bold;color:<?php echo $init_stock_color; ?>;<?php echo $init_stock_shadow; ?>"><?php echo (int) $card_stock; ?></span>
                            <span class="muted-3-color ml6" style="font-size:16px;">张</span>
                        </div>
                        
                        <?php if ($in['ID'] && $in['card_pass_key']) : ?>
                        <div class="mt20">
                            <div class="flex ac mb10 pb10" style="border-bottom:1px solid var(--muted-border-color); flex-wrap: wrap; gap: 10px;">
                                <span class="muted-color font-bold"><i class="fa fa-list-alt mr6"></i>卡密库存明细</span>
                                <span class="flex1"></span>
                                <span class="xingxy-mobile-scroll-hint muted-3-color em09 mr10">
                                    <i class="fa fa-angle-double-left mr3"></i>向左滑动查看更多
                                </span>
                                <button type="button" id="xingxy-load-cardlist-btn" class="but but-sm jb-cyan" style="white-space:nowrap;">
                                    <i class="fa fa-refresh mr3"></i>刷新列表
                                </button>
                            </div>
                            <style>
                                /* 卡密列表移动端自适应优化 */
                                .xingxy-card-table-wrapper {
                                    width: 100%;
                                    max-width: 100%;
                                    overflow-x: auto;
                                    -webkit-overflow-scrolling: touch;
                                }
                                .xingxy-card-table-wrapper table {
                                    width: 100%;
                                    min-width: 500px;
                                    table-layout: auto;
                                }
                                .xingxy-card-table-wrapper td {
                                    word-wrap: break-word;
                                    word-break: break-all;
                                }
                            </style>
                            <div id="xingxy-cardlist-wrap" style="display:none;background:var(--main-bg-color);border-radius:6px;padding:10px;width:100%;max-width:100%;box-sizing:border-box;">
                                <div id="xingxy-cardlist-actions" class="flex ac mb10" style="display:none;padding:6px;background:var(--muted-border-color);border-radius:4px;">
                                    <label class="muted-color em09 pointer mb0 ml6" style="white-space:nowrap;">
                                        <input type="checkbox" id="xingxy-select-all-cards"> 全选未使用
                                    </label>
                                    <span class="flex1"></span>
                                    <button type="button" id="xingxy-delete-cards-btn" class="but but-sm hollow c-red mr6" style="white-space:nowrap;">
                                        <i class="fa fa-trash mr3"></i>批量删除
                                    </button>
                                </div>
                                <div id="xingxy-cardlist-table" style="max-height:350px;overflow-y:auto;" class="scroll-y mini-scrollbar"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 推广返佣设置 -->
    <?php
    $vip1_name = function_exists('_pz') ? _pz('pay_user_vip_1_name', 'VIP1') : 'VIP1';
    $vip2_name = function_exists('_pz') ? _pz('pay_user_vip_2_name', 'VIP2') : 'VIP2';
    $rb_type = isset($in['rebate_type']) ? $in['rebate_type'] : '';
    ?>
    <div class="zib-widget" style="margin-top:15px;">
        <div class="title-theme mb10">推广返佣</div>
        <p class="muted-2-color em09 mb10">有人通过推广链接购买此商品时，推广者可获得佣金。选"默认"即跟随平台统一规则，无需单独配置。</p>
        
        <div class="mb10">
            <label class="shipping-option-label">
                <input type="radio" name="rebate_type" value="" <?php checked($rb_type, ''); ?>> 默认
            </label>
            <label class="shipping-option-label">
                <input type="radio" name="rebate_type" value="off" <?php checked($rb_type, 'off'); ?>> 不参与
            </label>
            <label class="shipping-option-label">
                <input type="radio" name="rebate_type" value="ratio" <?php checked($rb_type, 'ratio'); ?>> 按比例返佣
            </label>
            <label class="shipping-option-label">
                <input type="radio" name="rebate_type" value="fixed" <?php checked($rb_type, 'fixed'); ?>> 固定金额返佣
            </label>
        </div>
        
        <!-- 按比例返佣 -->
        <div id="xingxy-rebate-ratio-box" style="<?php echo $rb_type !== 'ratio' ? 'display:none;' : ''; ?>">
            <div class="flex ac" style="gap:15px;flex-wrap:wrap;">
                <div style="flex:1;min-width:120px;">
                    <label class="muted-color em09 mb3" style="display:block;">普通用户</label>
                    <div class="flex ac">
                        <input type="number" class="form-control" name="rebate_all_ratio" value="<?php echo esc_attr($in['rebate_all_ratio'] ?? 0); ?>" min="0" max="100" step="1" style="width:80px;">
                        <span class="muted-2-color ml6">%</span>
                    </div>
                </div>
                <div style="flex:1;min-width:120px;">
                    <label class="muted-color em09 mb3" style="display:block;"><?php echo esc_html($vip1_name); ?></label>
                    <div class="flex ac">
                        <input type="number" class="form-control" name="rebate_vip1_ratio" value="<?php echo esc_attr($in['rebate_vip1_ratio'] ?? 0); ?>" min="0" max="100" step="1" style="width:80px;">
                        <span class="muted-2-color ml6">%</span>
                    </div>
                </div>
                <div style="flex:1;min-width:120px;">
                    <label class="muted-color em09 mb3" style="display:block;"><?php echo esc_html($vip2_name); ?></label>
                    <div class="flex ac">
                        <input type="number" class="form-control" name="rebate_vip2_ratio" value="<?php echo esc_attr($in['rebate_vip2_ratio'] ?? 0); ?>" min="0" max="100" step="1" style="width:80px;">
                        <span class="muted-2-color ml6">%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 固定金额返佣 -->
        <div id="xingxy-rebate-fixed-box" style="<?php echo $rb_type !== 'fixed' ? 'display:none;' : ''; ?>">
            <div class="flex ac" style="gap:15px;flex-wrap:wrap;">
                <div style="flex:1;min-width:120px;">
                    <label class="muted-color em09 mb3" style="display:block;">普通用户</label>
                    <div class="flex ac">
                        <input type="number" class="form-control" name="rebate_all_fixed" value="<?php echo esc_attr($in['rebate_all_fixed'] ?? 0); ?>" min="0" step="0.01" style="width:80px;">
                        <span class="muted-2-color ml6">元</span>
                    </div>
                </div>
                <div style="flex:1;min-width:120px;">
                    <label class="muted-color em09 mb3" style="display:block;"><?php echo esc_html($vip1_name); ?></label>
                    <div class="flex ac">
                        <input type="number" class="form-control" name="rebate_vip1_fixed" value="<?php echo esc_attr($in['rebate_vip1_fixed'] ?? 0); ?>" min="0" step="0.01" style="width:80px;">
                        <span class="muted-2-color ml6">元</span>
                    </div>
                </div>
                <div style="flex:1;min-width:120px;">
                    <label class="muted-color em09 mb3" style="display:block;"><?php echo esc_html($vip2_name); ?></label>
                    <div class="flex ac">
                        <input type="number" class="form-control" name="rebate_vip2_fixed" value="<?php echo esc_attr($in['rebate_vip2_fixed'] ?? 0); ?>" min="0" step="0.01" style="width:80px;">
                        <span class="muted-2-color ml6">元</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 底部固定操作栏 -->
    <div class="xingxy-sticky-bar">
        <div class="flex ac jsb" style="max-width:1200px;margin:0 auto;padding:0 15px;">
            <div class="muted-2-color em09">
                <?php if ($in['post_status'] === 'publish'): ?>
                    <i class="fa fa-check-circle c-green mr3"></i>已发布 &middot; 修改后点保存生效（卡密导入除外）
                <?php elseif ($in['post_status'] === 'pending'): ?>
                    <i class="fa fa-clock-o c-yellow mr3"></i>审核中 &middot; 请等待管理员通过
                <?php elseif ($in['ID']): ?>
                    <i class="fa fa-pencil mr3"></i>编辑完成后请点右侧提交
                <?php else: ?>
                    <i class="fa fa-plus mr3"></i>填写商品信息 &middot; 点右侧提交审核
                <?php endif; ?>
            </div>
            <div class="flex ac">
                <?php if ($in['ID']): ?>
                <a href="<?php echo esc_url(get_permalink($in['ID'])); ?>" target="_blank" class="but hollow" style="padding:8px 16px;">
                    <i class="fa fa-fw fa-eye"></i>预览商品
                </a>
                <?php endif; ?>
                <?php if ($in['post_status'] !== 'publish' && $in['post_status'] !== 'pending'): ?>
                <button type="button" class="but jb-green xingxy-product-submit ml10" data-action="product_draft" style="padding:8px 20px;">
                    <i class="fa fa-fw fa-dot-circle-o"></i>保存草稿
                </button>
                <?php endif; ?>
                <button type="button" class="but jb-blue xingxy-product-submit ml10" data-action="product_save" style="padding:8px 24px;">
                    <i class="fa fa-fw fa-check-square-o"></i><?php echo ($in['post_status'] === 'publish') ? '保存' : '提交审核'; ?>
                </button>
            </div>
        </div>
    </div>
    <style>
        .xingxy-sticky-bar {
            position: sticky;
            bottom: 0;
            z-index: 100;
            background: var(--main-bg-color, #fff);
            border-top: 1px solid var(--muted-border-color);
            padding: 12px 0;
            margin-top: 20px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.06);
        }
        .xingxy-product-submit.is-saving {
            opacity: 0.7;
            pointer-events: none;
        }
        .xingxy-sticky-bar .but {
            white-space: nowrap;
        }
        @media (max-width: 768px) {
            .xingxy-sticky-bar .flex.ac.jsb {
                flex-direction: column;
                gap: 8px;
            }
            .xingxy-sticky-bar .muted-2-color {
                text-align: center;
                font-size: 12px;
            }
        }
    </style>

</main>

<style>
@keyframes xingxy-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}
</style>
<script>
jQuery(function($) {
    // Gallery 上传
    $('#xingxy-gallery-btn').on('click', function(e) {
        e.preventDefault();
        var frame = wp.media({
            title: '选择商品封面图片',
            button: { text: '使用选中的图片' },
            multiple: true,
            library: { type: 'image' }
        });
        frame.on('select', function() {
            var selection = frame.state().get('selection');
            var ids = $('#cover_image_ids').val() ? $('#cover_image_ids').val().split(',') : [];
            selection.each(function(attachment) {
                var data = attachment.toJSON();
                if (ids.indexOf(String(data.id)) === -1) {
                    ids.push(data.id);
                    var src = data.sizes && data.sizes.medium ? data.sizes.medium.url : data.url;
                    var html = '<div class="xingxy-gallery-item" data-id="' + data.id + '">';
                    html += '<img src="' + src + '" alt="">';
                    html += '<span class="xingxy-gallery-remove" title="移除">&times;</span>';
                    html += '</div>';
                    $('#xingxy-gallery-preview').append(html);
                }
            });
            $('#cover_image_ids').val(ids.join(','));
        });
        frame.open();
    });

    // 移除图片
    $(document).on('click', '.xingxy-gallery-remove', function() {
        var item = $(this).closest('.xingxy-gallery-item');
        var removeId = String(item.data('id'));
        item.remove();
        var ids = $('#cover_image_ids').val().split(',').filter(function(id) {
            return id !== removeId;
        });
        $('#cover_image_ids').val(ids.join(','));
    });

    // 发货类型切换（自动/手动）
    $('input[name="shipping_type"]').on('change', function() {
        if ($(this).val() === 'auto') {
            $('#xingxy-auto-delivery-box').slideDown(200);
        } else {
            $('#xingxy-auto-delivery-box').slideUp(200);
        }
    });

    // 自动发货子类型切换（固定内容/卡密）
    $('input[name="auto_type"]').on('change', function() {
        var type = $(this).val();
        if (type === 'fixed') {
            $('#xingxy-fixed-content-box').slideDown(200);
            $('#xingxy-cardpass-box').slideUp(200);
        } else {
            $('#xingxy-fixed-content-box').slideUp(200);
            $('#xingxy-cardpass-box').slideDown(200);
        }
    });

    // 推广返佣类型切换
    $('input[name="rebate_type"]').on('change', function() {
        var type = $(this).val();
        $('#xingxy-rebate-ratio-box')[type === 'ratio' ? 'slideDown' : 'slideUp'](200);
        $('#xingxy-rebate-fixed-box')[type === 'fixed' ? 'slideDown' : 'slideUp'](200);
    });

    // 卡密输入时实时引导
    $('#xingxy-cardpass-data').on('input', function() {
        var hasData = $(this).val() && $(this).val().trim();
        if (hasData) {
            if (!$('#xingxy-import-hint').length) {
                $(this).after('<div id="xingxy-import-hint" style="margin-top:6px;padding:6px 10px;border-radius:4px;border:1px dashed var(--muted-2-color);background:var(--main-bg-color);font-size:12px;color:var(--color-blue);animation:xingxy-pulse 1.5s infinite;"><i class="fa fa-hand-pointer-o mr3"></i>数据已就绪，请点击右下方「导入」按钮完成导入</div>');
            }
        } else {
            $('#xingxy-import-hint').remove();
        }
    });

    // 卡密导入
    $('#xingxy-import-cardpass-btn').on('click', function() {
        var $btn = $(this);
        var data = $('#xingxy-cardpass-data').val();
        var productId = $('input[name="product_id"]').val();
        var cardPassKey = $('input[name="card_pass_key"]').val();
        
        if (!data || !data.trim()) {
            if (typeof notyf_top !== 'undefined') {
                notyf_top('请先粘贴卡密数据', 'danger');
            } else {
                alert('请先粘贴卡密数据');
            }
            return;
        }
        
        if (!cardPassKey || !cardPassKey.trim()) {
            if (typeof notyf_top !== 'undefined') {
                notyf_top('请先填写卡密备注', 'danger');
            } else {
                alert('请先填写卡密备注');
            }
            $('#xingxy-card-pass-key').focus();
            return;
        }
        
        if (!productId || productId === '0') {
            if (typeof notyf_top !== 'undefined') {
                notyf_top('请先保存商品后再导入卡密', 'danger');
            } else {
                alert('请先保存商品后再导入卡密');
            }
            return;
        }
        
        $btn.prop('disabled', true);
        
        $.ajax({
            url: ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'xingxy_import_cardpass',
                product_id: productId,
                import_data: data,
                card_pass_key: cardPassKey
            },
            dataType: 'json',
            success: function(res) {
                $btn.prop('disabled', false);
                var $result = $('#xingxy-import-result');
                if (res.success || res.error == 0) {
                    var d = res.data || res;
                    var resultMsg = '成功导入 ' + d.success_count + ' 条卡密';
                    if (d.error_count > 0) {
                        resultMsg += '，' + d.error_count + ' 条失败';
                    }
                    $result.html('<span style="color:#52c41a;font-weight:bold;"><i class="fa fa-check-circle mr3"></i>' + resultMsg + '</span>').show();
                    // 更新库存数
                    var newStock = d.stock !== undefined ? d.stock : d.success_count;
                    $('#xingxy-card-stock').text(newStock).css('color', newStock > 0 ? 'var(--color-green)' : 'var(--color-red)');
                    // 更新 card_pass_key
                    if (d.card_pass_key) {
                        $('input[name="card_pass_key"]').val(d.card_pass_key);
                    }
                    // 清空输入框
                    $('#xingxy-cardpass-data').val('');
                    $('#xingxy-import-hint').remove();
                    // 触发列表刷新
                    $(document).trigger('xingxy_cardpass_imported');
                    if (typeof notyf_top !== 'undefined') {
                        notyf_top(resultMsg, 'success');
                    }
                } else {
                    var msg = res.data || res.msg || '导入失败';
                    $result.html('<span style="color:var(--color-red);"><i class="fa fa-times-circle mr3"></i>' + msg + '</span>').show();
                    if (typeof notyf_top !== 'undefined') {
                        notyf_top(msg, 'danger');
                    }
                }
            },
            error: function() {
                $btn.removeClass('loading').prop('disabled', false);
                alert('网络错误，请稍后重试');
            }
        });
    });

    // === 卡密管理列表 ===
    
    // 渲染卡密表格
    function renderCardList(list) {
        var $wrap = $('#xingxy-cardlist-wrap');
        var $table = $('#xingxy-cardlist-table');
        var $actions = $('#xingxy-cardlist-actions');
        
        if (!list || list.length === 0) {
            $table.html('<p class="muted-3-color em09 text-center" style="padding:10px;">暂无卡密数据</p>');
            $actions.hide();
            $wrap.show();
            $('.xingxy-mobile-scroll-hint').removeClass('is-show');
            return;
        }
        
        var usedCount = 0, unusedCount = 0;
        var html = '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
        html += '<thead><tr style="background:var(--muted-border-color);">';
        html += '<th style="padding:6px 8px;width:28px;text-align:center;">#</th>';
        html += '<th style="padding:6px 8px;width:28px;"></th>';
        html += '<th style="padding:6px 8px;text-align:left;">卡号</th>';
        html += '<th style="padding:6px 8px;text-align:left;">密码</th>';
        html += '<th style="padding:6px 8px;width:55px;text-align:center;">状态</th>';
        html += '<th style="padding:6px 8px;width:110px;text-align:center;">时间</th>';
        html += '<th style="padding:6px 8px;width:50px;text-align:center;">操作</th>';
        html += '</tr></thead><tbody>';
        
        var hasUnused = false;
        for (var i = 0; i < list.length; i++) {
            var item = list[i];
            if (item.used) { usedCount++; } else { unusedCount++; }
            var statusColor = item.used ? '#ff4d4f' : '#52c41a';
            var checkbox = item.used ? '<span class="muted-3-color">—</span>' : '<input type="checkbox" class="xingxy-card-check" value="' + item.id + '">';
            if (!item.used) hasUnused = true;
            
            // 编辑按钮：仅未使用的可编辑
            var editBtn = item.used
                ? '<span class="muted-3-color">—</span>'
                : '<a href="javascript:;" class="xingxy-edit-card" data-id="' + item.id + '" data-card="' + $('<span>').text(item.card).html() + '" data-pass="' + $('<span>').text(item.password).html() + '" style="color:var(--color-blue);"><i class="fa fa-pencil"></i></a>';
            
            html += '<tr data-row-id="' + item.id + '" style="border-bottom:1px solid var(--muted-border-color);">';
            html += '<td style="padding:5px 8px;text-align:center;color:var(--muted-3-color);">' + (i + 1) + '</td>';
            html += '<td style="padding:5px 8px;text-align:center;">' + checkbox + '</td>';
            html += '<td class="td-card" style="padding:5px 8px;word-break:break-all;">' + $('<span>').text(item.card).html() + '</td>';
            html += '<td class="td-pass" style="padding:5px 8px;word-break:break-all;">' + $('<span>').text(item.password).html() + '</td>';
            html += '<td style="padding:5px 8px;text-align:center;color:' + statusColor + ';font-weight:bold;">' + item.status + '</td>';
            html += '<td style="padding:5px 8px;text-align:center;white-space:nowrap;">' + item.time + '</td>';
            html += '<td style="padding:5px 8px;text-align:center;">' + editBtn + '</td>';
            html += '</tr>';
        }
        
        // 统计行
        html += '</tbody><tfoot><tr style="background:var(--muted-border-color);">';
        html += '<td colspan="5" style="padding:6px 8px;font-weight:bold;">共 ' + list.length + ' 条</td>';
        html += '<td style="padding:6px 8px;text-align:center;"><span style="color:#52c41a;">' + unusedCount + '</span>/<span style="color:#ff4d4f;">' + usedCount + '</span></td>';
        html += '<td></td>';
        html += '</tr></tfoot></table>';
        
        $table.html('<div class="xingxy-card-table-wrapper">' + html + '</div>');
        $actions.toggle(hasUnused);
        $wrap.show();
        $('.xingxy-mobile-scroll-hint').addClass('is-show');
        $('#xingxy-select-all-cards').prop('checked', false);
        
        // 选中计数
        $(document).off('change.cardcheck').on('change.cardcheck', '.xingxy-card-check, #xingxy-select-all-cards', function() {
            var count = $('.xingxy-card-check:checked').length;
            var $btn = $('#xingxy-delete-cards-btn');
            $btn.html('<i class="fa fa-trash mr3"></i>批量删除' + (count > 0 ? ' (' + count + ')' : ''));
            $btn.prop('disabled', count === 0);
        });
        
        // 初始状态重置删除按钮
        $('#xingxy-delete-cards-btn').html('<i class="fa fa-trash mr3"></i>批量删除').prop('disabled', true);
    }
    
    // 行内编辑
    $(document).on('click', '.xingxy-edit-card', function() {
        var $a = $(this);
        var id = $a.data('id');
        var card = $a.data('card');
        var pass = $a.data('pass');
        var $tr = $('tr[data-row-id="' + id + '"]');
        var $tdCard = $tr.find('.td-card');
        var $tdPass = $tr.find('.td-pass');
        
        // 替换为 input
        $tdCard.html('<input type="text" class="form-control" value="' + card + '" style="font-size:12px;padding:2px 6px;height:auto;">');
        $tdPass.html('<input type="text" class="form-control" value="' + pass + '" style="font-size:12px;padding:2px 6px;height:auto;">');
        
        // 按钮变为 保存/取消
        $a.closest('td').html(
            '<a href="javascript:;" class="xingxy-save-card" data-id="' + id + '" style="color:#52c41a;margin-right:6px;" title="保存"><i class="fa fa-check"></i></a>' +
            '<a href="javascript:;" class="xingxy-cancel-card" style="color:var(--muted-3-color);" title="取消"><i class="fa fa-times"></i></a>'
        );
    });
    
    // 保存编辑
    $(document).on('click', '.xingxy-save-card', function() {
        var id = $(this).data('id');
        var $tr = $('tr[data-row-id="' + id + '"]');
        var newCard = $tr.find('.td-card input').val();
        var newPass = $tr.find('.td-pass input').val();
        
        if (!newCard || !newPass) {
            if (typeof notyf_top !== 'undefined') notyf_top('卡号和密码不能为空', 'danger');
            return;
        }
        
        $.ajax({
            url: ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'xingxy_edit_cardpass',
                product_id: $('input[name="product_id"]').val(),
                card_id: id,
                new_card: newCard,
                new_password: newPass
            },
            dataType: 'json',
            success: function(res) {
                if (res.success || res.error == 0) {
                    if (typeof notyf_top !== 'undefined') notyf_top('编辑成功', 'success');
                    loadCardList();
                } else {
                    if (typeof notyf_top !== 'undefined') notyf_top(res.data || res.msg || '编辑失败', 'danger');
                }
            }
        });
    });
    
    // 取消编辑
    $(document).on('click', '.xingxy-cancel-card', function() {
        loadCardList();
    });
    
    // 加载卡密列表
    function loadCardList() {
        var productId = $('input[name="product_id"]').val();
        var cardPassKey = $('input[name="card_pass_key"]').val();
        if (!productId || !cardPassKey) return;
        
        var $btn = $('#xingxy-load-cardlist-btn');
        var originalHtml = $btn.html();
        $btn.html('<i class="fa fa-refresh fa-spin mr3"></i>加载中...').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'xingxy_list_cardpass',
                product_id: productId,
                card_pass_key: cardPassKey
            },
            dataType: 'json',
            success: function(res) {
                $btn.html(originalHtml).prop('disabled', false);
                if (res.success || res.error == 0) {
                    var d = res.data || res;
                    renderCardList(d.list || []);
                    if (d.stock !== undefined) {
                        var stock = parseInt(d.stock) || 0;
                        var stockColor = stock > 0 ? '#67C23A' : 'var(--muted-3-color)';
                        var textShadow = stock > 0 ? 'text-shadow: 0 0 10px rgba(103,194,58,0.3);' : '';
                        $('#xingxy-card-stock').text(stock).attr('style', 'font-size:22px;font-weight:bold;color:' + stockColor + ';' + textShadow);
                    }
                } else {
                    $('#xingxy-cardlist-table').html('<p class="muted-3-color em09 text-center" style="padding:20px;">加载失败：' + (res.data || res.msg) + '</p>');
                    $('#xingxy-cardlist-wrap').show();
                }
            },
            error: function() {
                $btn.html(originalHtml).prop('disabled', false);
                $('#xingxy-cardlist-table').html('<p class="muted-3-color em09 text-center" style="padding:20px;">网络错误，加载失败</p>');
                $('#xingxy-cardlist-wrap').show();
            }
        });
    }
    
    // 点击加载列表
    $('#xingxy-load-cardlist-btn').on('click', loadCardList);
    
    // 全选未使用
    $('#xingxy-select-all-cards').on('change', function() {
        var checked = $(this).is(':checked');
        $('.xingxy-card-check').prop('checked', checked);
    });
    
    // 删除选中
    $('#xingxy-delete-cards-btn').on('click', function() {
        var ids = [];
        $('.xingxy-card-check:checked').each(function() {
            ids.push($(this).val());
        });
        if (ids.length === 0) {
            if (typeof notyf_top !== 'undefined') {
                notyf_top('请先勾选要删除的卡密', 'danger');
            }
            return;
        }
        if (!confirm('确定删除选中的 ' + ids.length + ' 条卡密？此操作不可撤销。')) return;
        
        var $btn = $(this);
        var originalHtml = $btn.html();
        $btn.html('<i class="fa fa-trash fa-spin mr3"></i>删除中...').prop('disabled', true);
        $.ajax({
            url: ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'xingxy_delete_cardpass',
                product_id: $('input[name="product_id"]').val(),
                card_pass_key: $('input[name="card_pass_key"]').val(),
                'delete_ids[]': ids
            },
            dataType: 'json',
            success: function(res) {
                // 如果成功，loadCardList() 里的 renderCardList() 会重置按钮 html
                $btn.prop('disabled', false);
                if (res.success || res.error == 0) {
                    var d = res.data || res;
                    if (typeof notyf_top !== 'undefined') {
                        notyf_top(d.msg || '删除成功', 'success');
                    }
                    if (d.stock !== undefined) {
                        var stock = parseInt(d.stock) || 0;
                        var stockColor = stock > 0 ? '#ff4d4f' : 'var(--muted-3-color)';
                        var textShadow = stock > 0 ? 'text-shadow: 0 0 10px rgba(255,77,79,0.3);' : '';
                        $('#xingxy-card-stock').text(stock).attr('style', 'font-size:22px;font-weight:bold;color:' + stockColor + ';' + textShadow);
                    }
                    loadCardList();
                } else {
                    var msg = res.data || res.msg || '删除失败';
                    if (typeof notyf_top !== 'undefined') {
                        notyf_top(msg, 'danger');
                    }
                }
            },
            error: function() {
                $btn.removeClass('loading').prop('disabled', false);
            }
        });
    });

    // 导入成功后自动刷新列表
    $(document).on('xingxy_cardpass_imported', loadCardList);

    // 提交表单
    $('.xingxy-product-submit').on('click', function() {
        var $btn = $(this);
        var action = $btn.data('action');
        
        if ($btn.hasClass('is-saving')) return;
        
        // 检测未导入的卡密数据
        var pendingCardData = $('#xingxy-cardpass-data').val();
        var isCardPassMode = $('input[name="auto_type"]:checked').val() === 'card_pass';
        var isAutoShipping = $('input[name="shipping_type"]:checked').val() === 'auto';
        if (isAutoShipping && isCardPassMode && pendingCardData && pendingCardData.trim()) {
            if (!confirm('检测到卡密输入框中还有未导入的数据，请先点击「导入」按钮导入卡密。\n\n点击「确定」忽略并继续提交，点击「取消」返回导入。')) {
                return;
            }
        }
        
        // 自定义 loading（不用 Zibll 的 .loading 类，避免文案转圈 Bug）
        var origHtml = $btn.html();
        var allBtns = $('.xingxy-product-submit');
        allBtns.addClass('is-saving').prop('disabled', true);
        $btn.html('<i class="fa fa-spinner fa-spin mr6"></i>保存中...');
        
        // 获取 TinyMCE 内容
        var content = '';
        if (typeof tinymce !== 'undefined' && tinymce.get('product_content')) {
            content = tinymce.get('product_content').getContent();
        } else {
            content = $('#product_content').val();
        }
        
        var shippingType = $('input[name="shipping_type"]:checked').val();
        var autoType = $('input[name="auto_type"]:checked').val();
        
        var formData = {
            action: action,
            product_id: $('input[name="product_id"]').val(),
            product_title: $('textarea[name="product_title"]').val(),
            product_desc: $('textarea[name="product_desc"]').val(),
            product_content: content,
            product_price: $('input[name="product_price"]').val(),
            'shop_cat[]': [],
            product_tags: $('textarea[name="product_tags"]').val(),
            cover_image_ids: $('#cover_image_ids').val(),
            shipping_type: shippingType,
            auto_type: shippingType === 'auto' ? autoType : '',
            fixed_content: (shippingType === 'auto' && autoType === 'fixed') ? $('textarea[name="fixed_content"]').val() : '',
            card_pass_key: $('input[name="card_pass_key"]').val(),
            rebate_type: $('input[name="rebate_type"]:checked').val() || '',
            rebate_all_ratio: $('input[name="rebate_all_ratio"]').val() || 0,
            rebate_vip1_ratio: $('input[name="rebate_vip1_ratio"]').val() || 0,
            rebate_vip2_ratio: $('input[name="rebate_vip2_ratio"]').val() || 0,
            rebate_all_fixed: $('input[name="rebate_all_fixed"]').val() || 0,
            rebate_vip1_fixed: $('input[name="rebate_vip1_fixed"]').val() || 0,
            rebate_vip2_fixed: $('input[name="rebate_vip2_fixed"]').val() || 0
        };
        
        // 收集分类
        var cats = [];
        $('input[name="shop_cat[]"]:checked').each(function() {
            cats.push($(this).val());
        });
        
        $.ajax({
            url: ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: $.param(formData) + '&' + $.param({'shop_cat': cats}),
            dataType: 'json',
            success: function(res) {
                $btn.html(origHtml);
                allBtns.removeClass('is-saving').prop('disabled', false);
                if (res.success || res.error == 0) {
                    var data = res.data || res;
                    if (data.msg) {
                        if (typeof notyf_top !== 'undefined') {
                            notyf_top(data.msg, 'success');
                        } else {
                            alert(data.msg);
                        }
                    }
                    if (data.reload && data.goto) {
                        setTimeout(function() { window.location.href = data.goto; }, 1000);
                    } else if (data.product_id) {
                        $('input[name="product_id"]').val(data.product_id);
                    }
                } else {
                    var msg = res.data || res.msg || '保存失败';
                    if (typeof notyf_top !== 'undefined') {
                        notyf_top(msg, 'danger');
                    } else {
                        alert(msg);
                    }
                }
            },
            error: function() {
                $btn.html(origHtml);
                allBtns.removeClass('is-saving').prop('disabled', false);
                alert('网络错误，请稍后重试');
            }
        });
    });
});
</script>

<?php get_footer(); ?>

