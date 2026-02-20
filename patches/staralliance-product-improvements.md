# 星盟：商品管理体验优化

## 修改日期
2026-02-21

## 概述
优化商品管理的三个体验问题：商品列表分页、编辑器工具栏、暗色模式文字颜色。

## 问题一：商品管理列表不完整

### 问题描述
用户中心侧边栏"商品管理"只显示最近 5 个商品，无法查看和管理更多商品。

### 修复方式

#### `xingxy/inc/user-products.php`
- 商品总数超过 5 个时，底部显示"查看全部 N 个商品 →"链接

#### `xingxy/pages/newproduct.php`
- 非编辑模式时，在发布表单上方展示完整商品管理列表
- 列表包含：商品名称、状态标签（已上架/待审核/草稿）、销量、编辑/预览链接

## 问题二：商品编辑器工具栏缺失

### 问题描述
商品编辑器的 TinyMCE 工具栏比文章编辑器简陋，缺少图片上传、视频上传、隐藏内容等按钮。

### 根因
文章发布页通过 `tinymce_upload_img`、`tinymce_upload_video` 等 filter 启用自定义按钮，商品发布页缺失这些 filter。

### 修复方式
在 `newproduct.php` 中复用文章发布页的 filter 链，按 `zib_current_user_can` 权限检查启用：

| Filter | 功能 |
|---|---|
| `tinymce_upload_img` | 图片上传 |
| `tinymce_upload_video` | 视频上传 |
| `tinymce_upload_file` | 文件上传 |
| `tinymce_iframe_video` | 嵌入视频 |
| `tinymce_hide` | 隐藏内容 |

## 问题三：暗色模式编辑器文字不可见

### 问题描述
暗色模式下，商品编辑器正文文字颜色为灰色，与深色背景接近，几乎看不清。文章编辑器则正常显示白色。

### 根因
Zibll 的 `zib_tiny_mce_before_init_filter` 只对 `editor_id === 'post_content'` 注入暗色模式的 `body_class`。商品编辑器的 `editor_id` 是 `product_content`，导致 iframe body 没有暗色模式 class。

### 修复方式
在 `newproduct.php` 中添加 `tiny_mce_before_init` filter，对 `product_content` 也注入 `zib_get_theme_mode()` class。

## 变更文件

| 文件 | 修改 |
|---|---|
| `xingxy/inc/user-products.php` | 添加"查看全部"链接 |
| `xingxy/pages/newproduct.php` | 完整商品列表 + 编辑器按钮 + 暗色模式修复 |

## 恢复方法

```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/panda/xingxy
git log --oneline -5
git revert <commit>
```
