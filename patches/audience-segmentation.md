# 用户分群标签系统（Audience Segmentation）

## 修改日期
2026-04-26

## 概述
实现完整的用户分群标签基础设施：通过追踪短链自动给注册用户打标，后台可视化管理短链和标签，为后续内容可见性控制奠定基础。

## 核心机制

### 追踪短链
管理员在全站分享按钮中配置「标签 slug + 最大使用次数 + 有效期」，生成短链（`/go/{key}`）。用户通过短链访问站点时，系统自动设置追踪 Cookie，注册后根据短链配置打上对应标签。

### 三层追踪（混淆版）
1. **PHP 短链重定向**：`/go/{key}` → 查 `xingxy_share_links` 表 → 设 Cookie `_xsk` → 302 到目标页
2. **JS Fragment 桥接**：Cookie→Fragment（内置浏览器）；Fragment→Cookie（系统浏览器着陆恢复）
3. **注册表单隐藏字段**：读 Cookie → `<input type="hidden" name="_sk">`

### 注册时自动打标（优先级链）
```
_sk（短链标签）> _gate（口令标签，预留）> _s（推荐人继承，预留）> general（兜底）
```

## 新增文件

### 1. `xingxy/inc/share-links.php`
管理员增强分享面板，挂载到 Zibll 主题的分享模态框。

| 功能 | 说明 |
|------|------|
| `xingxy_share_links` 表自动建表 | `share_key, user_id, tag_slug, content_url, max_uses, used_count, expires_at, clicks` |
| 管理员面板注入 | 分享模态框底部显示标签/次数/有效期配置 + 已生成链接列表 |
| AJAX: `xingxy_create_share_link` | 创建追踪短链 |
| 过期时间 | 支持秒级精度，使用 `current_time('timestamp')` 保持时区一致 |

### 2. `xingxy/inc/gate-tracker.php`
追踪核心，处理短链重定向、Cookie 设置、Fragment 桥接、注册打标。

| 功能 | 说明 |
|------|------|
| Rewrite Rule | `^go/([A-Za-z0-9]+)/?$` → `index.php?xingxy_sk=$1` |
| `template_redirect` | 短链重定向 + 点击计数 + 设 `_xsk` Cookie |
| `wp_footer` JS | Fragment ↔ Cookie 双向桥接 |
| `wp_footer` 隐藏字段 | 注册表单注入 `_sk` hidden input |
| `user_register` 优先级 4 | 打标优先级链：`_sk > _gate > _s > general` |
| `xingxy_add_segment()` | 辅助函数：追加标签到 `_xingxy_segments` JSON 数组 |

### 3. `xingxy/inc/admin-share-links.php`
后台追踪短链管理页面（独立子菜单）。

| 功能 | 说明 |
|------|------|
| 子菜单注册 | `xingxy-options` 下的 `xingxy-share-links`，优先级 99 |
| 列表展示 | 分页、状态筛选（活跃/已过期/已耗尽）、剩余时间显示 |
| 行内编辑 | AJAX 修改 `tag_slug`、`max_uses`、`expires_at` |
| 单条删除 | AJAX 删除 |
| 批量清理 | 一键清除已过期和已耗尽的短链 |

## 修改文件

### 4. `xingxy/inc/admin-profile-dashboard.php`
用户画像数据中心 + WP 用户列表增强。

| 新增功能 | 说明 |
|----------|------|
| 画像面板分群标签列 | 显示 `_xingxy_segments` 标签 + 来源短链 Key |
| 画像面板查询优化 | 替换多 EXISTS OR meta_query 为单条 SQL + include，避免慢查询 |
| WP 用户列表标签列 | `manage_users_columns` + `manage_users_custom_column` |
| 行内标签编辑器 | 点击展开浮层，AJAX 保存，支持快选已有标签 |
| 标签筛选下拉框 | `restrict_manage_users` + `pre_get_users`，选中自动跳转筛选 |
| 用户编辑页编辑器 | `edit_user_profile` hook，表单保存标签 |

### 5. `xingxy/init.php`
- 新增加载 `share-links.php`、`gate-tracker.php`、`admin-share-links.php`

### 6. `xingxy/inc/options.php`
- ~~CSF 面板中的追踪短链入口~~（已移除，避免与侧边栏子菜单重复）

## 数据存储

| 存储位置 | Key | 格式 | 说明 |
|----------|-----|------|------|
| `xingxy_share_links` 表 | — | MySQL | 短链配置（key、标签、次数、过期时间、点击数） |
| `wp_usermeta` | `_xingxy_segments` | JSON 数组 `["general","x_social"]` | 用户分群标签 |
| `wp_usermeta` | `_source_sk` | 字符串 `"G3V9eCjf"` | 用户来源短链 Key |
| Cookie | `_xsk` | 字符串 | 追踪 Cookie，注册后清除 |

## 时区修复
所有过期时间计算统一使用 `current_time('timestamp')` 替代 `time()`，与 MySQL `DATETIME` 字段保持时区一致。涉及文件：
- `share-links.php`（创建时计算 + 模态框剩余时间）
- `gate-tracker.php`（注册打标过期校验）
- `admin-share-links.php`（状态判断 + 剩余时间显示）

## 恢复方法
```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/panda/xingxy
git log --oneline -5
git revert <commit>
```

手动恢复：
1. 删除 `inc/share-links.php`、`inc/gate-tracker.php`、`inc/admin-share-links.php`
2. 撤销 `init.php` 中对应的 `require_once` 行
3. 撤销 `admin-profile-dashboard.php` 中标签相关代码
4. 可选：删除 `xingxy_share_links` 数据表
