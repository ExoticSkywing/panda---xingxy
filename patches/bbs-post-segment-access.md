# BBS 帖子标签访问限制（Post Segment Access Control）

## 修改日期
2026-05-13

## 概述
为论坛帖子实现两层标签访问限制：帖子级（整篇限制）和内容级（局部隐藏作为钩子），支持三种编辑入口的同步，以及 TinyMCE 编辑器原生集成。

## 核心架构

### 帖子级限制（整篇限制）
通过侧边栏/弹窗/后台 CSF meta box 设置，整篇帖子仅对持有对应标签的用户可见。

三个编辑入口：
1. **前台弹窗**（阅读权限面板 `wp_ajax_edit_allow_view`）
2. **前台侧边栏**（编辑帖子页 `save_post_forum_post`）
3. **后台 CSF meta box**（`csf_xingxy_post_segment_save_after`）

所有入口共用统一保存函数 `xingxy_save_post_segment()`，确保双 key 同步：
- `_xingxy_post_seg_preset` — 内部预设索引
- `_xingxy_post_segments` — 实际标签 slug 数组
- `xingxy_post_seg_preset` — CSF 兼容 key

### 内容级限制（钩子模式）
作者在 TinyMCE 编辑器中使用 `[hidecontent type="segtag" preset="N"]` 包裹核心内容，未包裹内容作为"钩子"对外可见，吸引用户获取标签。

- 每个隐藏块可选择不同预设，互不影响
- 从 shortcode 的 `preset` 属性直接读取预设配置
- 帖子级与内容级**互斥**：帖子已设整篇限制时，编辑器阻止插入内容级 shortcode

### 互斥规则

| 帖子状态 | 无标签用户 | 有标签用户 | UI 行为 |
|----------|-----------|-----------|---------|
| 有帖子级限制 + 无 shortcode | 🔒 全文隐藏 | ✅ 全文可见 | — |
| 有帖子级限制 + 有 shortcode | 🔒 全文隐藏（帖子级优先） | ✅ 全文可见 | 编辑器阻止插入 |
| 无帖子级限制 + 有 shortcode | 钩子可见 + 包裹部分 🔒 | ✅ 全文可见 | ✅ 允许插入 |

## 新增/修改文件

### 1. `xingxy/inc/bbs-post-segment-access.php`（主文件）

| Section | 功能 |
|---------|------|
| 0 | 统一保存函数 `xingxy_save_post_segment()` — 双 key 原子写入 |
| 1 | 帖子内容拦截 — OB + the_content filter 替换正文 |
| 3 | 前台弹窗入口保存（`wp_ajax_edit_allow_view` 优先级 1） |
| 4 | 前台编辑/新建保存（`save_post_forum_post`） |
| 5 | 前台 JS 注入 — 新建页 |
| 6 | 前台 JS 注入 — 编辑页（预选已保存值） |
| 7 | 后台 CSF meta box 注册 + 保存后同步 |
| 8 | TinyMCE 插件注册 + `mce.segtag_presets` 输出 |
| 9 | `[hidecontent type="segtag"]` shortcode 渲染（`pre_do_shortcode_tag`） |

### 2. `xingxy/assets/js/tinymce-segtag.js`（新增）

TinyMCE 插件，在 Zibll 原生 `zib_hide` 按钮下拉菜单中追加标签限制选项：
- 1 个预设 → 直接作为菜单项
- 多个预设 → 「标签用户可查看」子菜单
- 点击时检测帖子级标签限制是否已设，已设则弹窗阻止

### 3. `xingxy/inc/options.php`（已有，添加预设配置）

CSF 后台配置面板中的 `post_segment_presets` 字段，管理员配置标签预设模板。

## Bypass 规则
按优先级依次检查：
1. 管理员 `is_super_admin()` → 直接可见
2. 帖子作者 → 直接可见
3. 板块版主 `zib_bbs_get_user_moderator_badge()` → 直接可见
4. 用户标签交集 → 匹配则可见

## 数据存储

| Meta Key | 类型 | 说明 |
|----------|------|------|
| `_xingxy_post_seg_preset` | int | 预设索引（内部） |
| `_xingxy_post_segments` | array | 标签 slug 数组（内部） |
| `xingxy_post_seg_preset` | string | 预设索引（CSF 兼容） |

## 依赖
- 用户标签 `_xingxy_segments`（user_meta，由 `gate-tracker.php` 注册时打标）
- `xingxy_pz('post_segment_presets')` 后台预设配置
- Zibll BBS 论坛模块（`forum_post` post type）
- Zibll TinyMCE 扩展（`editextend.js` 中的 `zib_hide` 按钮）
