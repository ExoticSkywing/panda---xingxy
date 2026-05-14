# TG 绑定信息集成（TG Bind Integration）

## 概述

将 Telegram 绑定信息完整回写至 WordPress，并在后台用户列表展示。同时实现管理员审批制解绑流程。

## 涉及仓库

| 仓库 | 改动文件 |
|------|---------|
| **xingxy** (子主题) | `inc/admin-profile-dashboard.php` |
| **zibll-oauth-main** (插件) | `includes/rest-usermeta.php`, `includes/rest.php` |
| **tgbot-verify** (精灵) | `oauth_server.py`, `database_mysql.py`, `bot.py`, `handlers/unbind_command.py`（新） |

## 功能详情

### 1. 绑定信息回写 WP

精灵完成 `/bind` OAuth 流程后，除原有 `_xingxy_telegram_uid` 外，新增回写：

| usermeta key | 来源 | 说明 |
|---|---|---|
| `_xingxy_telegram_username` | 精灵 DB `users.username` | TG @用户名 |
| `_xingxy_telegram_display_name` | 精灵 DB `users.full_name` | TG 显示名 |
| `_xingxy_telegram_bound_at` | WP `current_time('mysql')` | 绑定时间 |

- **WP 端点**：`POST /user/bindtg` 新增 `tg_username`、`tg_display_name` 可选参数
- **精灵侧**：`_write_tg_uid_via_api()` 从 DB 读取 username/full_name 一并传递
- **签名不变**：`md5(appid + openid + tg_uid + appkey)`，新字段不参与签名（向后兼容）

### 2. 后台用户列表 TG 绑定列

通过 `manage_users_columns` / `manage_users_custom_column` 钩子添加「TG 绑定」列：

- **主行**：`@username`（可点击跳转 `https://t.me/{username}` 私聊）> 显示名 > UID
- **副行**：显示名（有 username 时）、UID 小字、绑定日期
- **溢出处理**：`max-width:120px` + `text-overflow:ellipsis`，悬停 `title` 显示完整
- **未绑定**：灰色 `—`

### 3. TG 绑定状态筛选

后台用户列表新增下拉筛选：
- ✅ 已绑定 — `meta_query EXISTS`
- ❌ 未绑定 — `meta_query NOT EXISTS`

### 4. 解绑功能（/unbind 双模式）

| 使用者 | 输入 | 行为 |
|--------|------|------|
| 普通用户 | `/unbind` | 提示需审批 → [📮 申请解绑] → 管理员收到通知 + [✅批准] [❌拒绝] |
| 管理员 | `/unbind <tg_uid>` | 直接双向解绑 + 通知被解绑用户 |

**解绑清理范围**：
- 精灵 DB：`SET wp_openid = NULL`
- WP usermeta：删除全部 `_xingxy_telegram_*` 四个 key
- WP 端点：`POST /user/unbindtg`（签名 = `md5(appid + tg_uid + appkey)`）

### 5. 数据流

```
绑定：
  用户 /bind → 精灵 OAuth → WP 授权 → 精灵回调
    → 精灵 DB: wp_openid
    → WP API: _xingxy_telegram_uid + _username + _display_name + _bound_at

解绑（用户申请）：
  用户 /unbind → [📮 申请] → 管理员 [✅ 批准]
    → WP API: DELETE _xingxy_telegram_*
    → 精灵 DB: wp_openid = NULL
    → 通知用户

解绑（管理员直接）：
  管理员 /unbind <tg_uid> → 同上清理 → 通知用户
```

## 更新日期

2026-05-13
