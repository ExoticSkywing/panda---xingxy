
# 补丁：星盟前台商品发布系统 (Frontend Product Submission)

## 目标
实现合作方在前台直接发布和管理商品的能力，无需进入 WordPress 后台 /wp-admin。

## 变更文件清单

### 新增文件
1. **`xingxy/inc/product-capability.php`**
   - 功能：权限控制
   - 描述：检查用户是否有发布商品的权限（目前仅限管理员和特定角色，未来可扩展至购买了合作方权益的用户）。

2. **`xingxy/inc/action-newproduct.php`**
   - 功能：AJAX 处理
   - 描述：处理商品发布表单的提交，执行数据校验、保存 `shop_product` Post、存储 meta 数据（价格、库存、发货方式、图库等），并返回 JSON 响应。

3. **`xingxy/pages/newproduct.php`**
   - 功能：页面模板
   - 描述：前端商品发布页面的完整 HTML 模板。
   - 关键修复：
     - 去除 `<form>` 标签包裹，修复 Zibll 主题的 flex 两栏布局问题。
     - 强制使用 `add_filter('zib_is_show_sidebar', '__return_true')` 启用侧边栏。
     - 包含前端 JS 逻辑：TinyMCE 编辑器集成、WordPress Media Gallery 调用、自动发货选项切换。

4. **`xingxy/inc/user-products.php`**
   - 功能：用户中心入口
   - 描述：在用户中心侧边栏注入"商品管理"卡片，展示待审核/已发布商品列表及发布入口。

5. **`xingxy/assets/css/newproduct.css`**
   - 功能：页面样式
   - 描述：修复上传图片预览网格布局、调整价格输入框样式。

### 修改文件
1. **`xingxy/init.php`**
   - 变更：引入上述 3 个 PHP 核心文件。

2. **`xingxy/inc/assets.php`**
   - 变更：在 `xingxy_enqueue_assets` 中注册 `newproduct.css`，并仅在发布页面加载 `wp_enqueue_media()`。

## 验证结果
- **页面布局**：已修复侧边栏不显示问题，现在左右两栏布局正常。
- **功能测试**：
  - 商品标题、简介、详情（富文本）保存正常。
  - 封面图库（多图）上传与保存正常。
  - 价格、分类、标签保存正常。
  - 自动/手动发货模式切换及字段保存正常。
- **权限**：未登录用户重定向，无权限用户显示提示。

## 部署说明
所有文件均位于子主题 `xingxy` 目录下。更新代码后，需确保新建页面并指定模板为 `星盟-发布商品`。
