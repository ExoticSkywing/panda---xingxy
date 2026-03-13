# Nebuluxe 生态系统架构文档

> 更新时间：2026-03-13 — v4 架构优化：精灵为 TG 侧身份权威，兄弟服务查绑定问精灵，不直连 WP

---

## 一、系统全景图

```
┌─────────────────────────────────────────────────────────────────┐
│                        用户的浏览器                              │
│  center.manyuzo.com (Nebuluxe Center 前端)                      │
│  Vue3 + Vite + Element Plus (web-ele)                           │
└──────────────┬──────────────────────────────┬───────────────────┘
               │ HTTPS /api/*                 │ OAuth 授权跳转
               ▼                              ▼
┌──────────────────────────┐    ┌──────────────────────────────────┐
│      Nginx 反向代理       │    │   星小芽 WordPress (Zibll 主题)   │
│  center.manyuzo.com      │    │   xingxy.manyuzo.com             │
│  /api/* → 127.0.0.1:8555 │    │   用户身份唯一源头                │
└──────────┬───────────────┘    │   zibll-oauth 插件提供 OAuth2     │
           │                    └──────────────────────────────────┘
           ▼                              ▲         ▲
┌──────────────────────────┐              │         │
│    FastAPI Gateway        │    OAuth     │         │ OAuth
│    127.0.0.1:8555         │    回调      │         │ 绑定
│    JWT 签发 / 路由分发     │◄────────────┘         │
│    业务 API (无状态)      │                        │
└──────┬───────────────┬───┘              ┌─────────┴──────────┡
       │               │                  │  小芽精灵 TG Bot     │
       │               │                  │  (tgbot-verify)     │
       ▼               ▼                  │  python-telegram-bot│
   zibll-oauth    ┌──────┐                │  port 8443 (OAuth)  │
   REST API       │空投机│                │  签到/积分/绑定/验证  │
   (/userinfo     │ DB   │                │  绑定时调 /user/bindtg│
    含 tg_uid)     │      │                └─────────────────────┘
                  │xiaoya│                        ▲
                  │airdop│              ┌─────────┴──────────┡
                  └──────┘              │  小芽空投机 TG Bot   │
                                        │  (File-Sharing-Bot) │
v4: 精灵 = TG 侧身份权威。           │  Pyrogram           │
TG Bot 查绑定 → 问精灵（本地 DB）。  │  port 18688         │
Gateway 查 tg_uid → /userinfo API。  │  资源包存储/分发      │
                                        │  查绑定调精灵 /api/    │
                                        │  check-bind           │
                                        └─────────────────────┘
```

---

## 二、五大系统角色

| # | 系统名 | 域名/端口 | 技术栈 | 核心职责 |
|---|--------|-----------|--------|----------|
| 1 | **星小芽主站** | xingxy.manyuzo.com | WordPress + Zibll 主题 | 用户注册/登录、积分体系、OAuth2 身份提供方 |
| 2 | **Nebuluxe Center** | center.manyuzo.com | Vue3 + Element Plus + FastAPI | 管理后台前端 + API 网关 |
| 3 | **小芽精灵** | TG Bot @moemoji_bot | python-telegram-bot + aiohttp | TG 侧签到、积分、绑定 WP 账号、验证 |
| 4 | **小芽空投机** | TG Bot (File-Sharing-Bot) | Pyrogram | 资源包创建、存储、口令分发 |
| 5 | **zibll-oauth 插件** | WP REST API 端点 | PHP (WP 插件) | OAuth2 授权码模式、openid 发放、unionid 桥接 |

---

## 三、三个数据库

### 3.1 `xingxy_manyuzo` — WordPress 主站数据库（身份数据中心）

| 关键表 | 关键 meta_key | 用途 |
|--------|---------------|------|
| `wp_users` | — | WordPress 用户表 (user_id 是全局唯一身份) |
| `wp_usermeta` | `zibll_oauth_openid_` + md5(appid) | 各 OAuth 应用的 openid（per-appid） |
| `wp_usermeta` | **`_xingxy_telegram_uid`** | **TG user ID（v2 新增，精灵绑定时写入）** |
| `wp_usermeta` | `xingxy_profile_data` | 用户画像数据（盲盒问卷） |
| `wp_usermeta` | `_xingxy_welcome_rewarded` | 迎新盲盒领取标记 |

**设计哲学**：WordPress usermeta 是用户全维度数据的唯一源头。所有外部系统（TG Bot、Gateway）的身份最终都归结到这里。

### 3.2 `xiaoyajl_bot` (别名 tgbot_verify) — 小芽精灵数据库

| 关键表 | 关键字段 | 用途 |
|--------|----------|------|
| `users` | `user_id` (TG ID), `wp_openid`, `balance`, `last_checkin` | TG 用户 ↔ WP 账号绑定关系 |

**注意**：这里的 `wp_openid` 是精灵侧 OAuth 应用 (`zo_ww0qctfpokxa1g`) 生成的 openid，**不是** Center 侧的。

### 3.3 `xiaoyaairdrop` — 小芽空投机数据库

| 关键表 | 关键字段 | 用途 |
|--------|----------|------|
| `resource_packs` | `pack_id`, `admin_id` (TG ID), `name`, `tags`, `item_count`, `status` | 空投包元数据 |
| `pack_items` | `pack_id`, `message_id`, `media_group_id`, `sort_order` | 包内的文件条目 |
| `pack_codes` | `pack_id`, `code`, `code_type`, `is_active`, `use_count`, `max_uses` | 空投口令 |

---

## 四、两个 OAuth 应用

| 应用 | AppID | 使用方 | 用途 |
|------|-------|--------|------|
| **Nebuluxe Center** | `zo_mtyp66yphlz55g` | FastAPI Gateway | 用户登录管理后台 |
| **小芽精灵** | `zo_ww0qctfpokxa1g` | tgbot-verify | TG 用户绑定 WP 账号 |

两个应用为同一个 WP 用户生成的 openid **完全不同**：
```
WP 用户 "小芽妹" (user_id=1):
  Center openid: oid_xj60tgwyhcolpdodtzttfxpbae09
  精灵 openid:   oid_zkykr90w8nhkei5ucc5v5lqqycg5
```

---

## 五、核心数据流详解

### 5.1 流程 A：用户在 TG 绑定 WP 账号（/bind）

```
用户 TG                小芽精灵 Bot              星小芽 WP
  │                        │                        │
  │  /bind                 │                        │
  │──────────────────────►│                        │
  │                        │                        │
  │  返回授权链接            │                        │
  │◄──────────────────────│                        │
  │                        │                        │
  │  点击链接，浏览器跳转     │                        │
  │─────────────────────────────────────────────►  │
  │                        │                        │
  │  WP 授权页面登录确认     │                        │
  │◄─────────────────────────────────────────────  │
  │                        │                        │
  │  回调到精灵 OAuth 服务    │   code → token        │
  │  (port 8443)           │──────────────────────►│
  │                        │                        │
  │                        │   token → userinfo     │
  │                        │   获取精灵侧 openid      │
  │                        │◄──────────────────────│
  │                        │                        │
  │                        │                        │
  │                        │   token → unionid      │
  │                        │   获取 WP user ID       │
  │                        │──────────────────────►│
  │                        │◄──────────────────────│
  │                        │                        │
  │                        │  写入 tgbot_verify:     │
  │                        │  users.wp_openid =     │
  │                        │  精灵侧 openid          │
  │                        │  balance += 奖励积分     │
  │                        │                        │
  │                        │  调 /user/bindtg API:   │
  │                        │  _xingxy_telegram_uid  │
  │                        │  = TG user ID (v3 API) │
  │                        │────(REST API)────────►│
  │                        │                        │
  │  绑定成功！              │                        │
  │◄──────────────────────│                        │
```

**数据落地**：
- `tgbot_verify.users` 表写入 `wp_openid`（精灵侧 openid）
- **`wp_usermeta` 写入 `_xingxy_telegram_uid` = TG user ID**（通过 zibll-oauth `/user/bindtg` API，走 `update_user_meta` hooks）

---

### 5.2 流程 B：用户登录 Nebuluxe Center（OAuth 登录）

```
浏览器                 Nginx              Gateway (FastAPI)        星小芽 WP
  │                      │                      │                      │
  │ 访问 /airdrop/packs  │                      │                      │
  │─────────────────────►│                      │                      │
  │                      │                      │                      │
  │ 无 token → 跳登录页   │                      │                      │
  │ 点击 OAuth 登录       │                      │                      │
  │─────────────────────►│ /api/auth/wp-login   │                      │
  │                      │─────────────────────►│                      │
  │                      │                      │                      │
  │                      │ 302 跳转到 WP 授权页   │                      │
  │◄─────────────────────────────────────────────────────────────────  │
  │                      │                      │                      │
  │ 用户在 WP 登录确认     │                      │                      │
  │─────────────────────────────────────────────────────────────────► │
  │                      │                      │                      │
  │ WP 回调 → Gateway    │                      │                      │
  │─────────────────────►│ /api/auth/wp-callback │                      │
  │                      │─────────────────────►│                      │
  │                      │                      │                      │
  │                      │                      │ ① code → token        │
  │                      │                      │─────────────────────►│
  │                      │                      │◄─────────────────────│
  │                      │                      │                      │
  │                      │                      │ ② token → userinfo    │
  │                      │                      │   + unionid (WP user ID)│
  │                      │                      │─────────────────────►│
  │                      │                      │◄─────────────────────│
  │                      │                      │                      │
  │                      │                      │ ③ /userinfo 已含 tg_uid│
  │                      │                      │   （zibll-oauth 扩展）  │
  │                      │                      │   无额外 DB 查询       │
  │                      │                      │                      │
  │                      │                      │ ④ 签发自包含 JWT:       │
  │                      │                      │   tg_uid / name /     │
  │                      │                      │   avatar / is_super   │
  │                      │                      │                      │
  │ 302 跳转到前端:        │                      │                      │
  │ /#/auth/oauth-callback?accessToken=xxx      │                      │
  │◄─────────────────────────────────────────────│                      │
  │                      │                      │                      │
  │ 前端存储 token        │                      │                      │
  │ 跳转到 /airdrop/packs │                      │                      │
```

**关键产物**：自包含 JWT Token（Gateway 重启不影响已登录用户）：
```json
{
  "sub": "wp_oid_xj60tgwyhcolpdodtzttfxpbae09",
  "wp_uid": 1,
  "tg_uid": 1861667385,
  "is_super": true,
  "name": "小芽妹",
  "avatar": "https://...",
  "exp": 1742xxx
}
```

---

### 5.3 流程 C：空投包页面加载（v2 优化后 — 零身份查询）

```
前端 (浏览器)           Nginx           Gateway            空投机 DB
  │                      │                │                   │
  │ GET /api/airdrop/identity             │                   │
  │ Authorization: Bearer <JWT>           │                   │
  │─────────────────────►│───────────────►│                   │
  │                      │                │                   │
  │                      │                │ 解析 JWT:          │
  │                      │                │ tg_uid = 1861..   │
  │                      │                │ is_super = true   │
  │                      │                │ （零 DB 查询！）    │
  │                      │                │                   │
  │ { bound: true,       │                │                   │
  │   tg_user_id: 1861.. │                │                   │
  │   is_super: true }   │                │                   │
  │◄─────────────────────│◄───────────────│                   │
  │                      │                │                   │
  │ GET /api/airdrop/packs?page=1         │                   │
  │─────────────────────►│───────────────►│                   │
  │                      │                │                   │
  │                      │                │ JWT → tg_uid      │
  │                      │                │ 查 xiaoyaairdrop:  │
  │                      │                │ WHERE admin_id =  │
  │                      │                │ tg_user_id        │
  │                      │                │──────────────────►│
  │                      │                │                   │
  │                      │                │ 返回空投包列表       │
  │                      │                │◄──────────────────│
  │                      │                │                   │
  │ { items: [...66个包], │                │                   │
  │   total: 66 }        │                │                   │
  │◄─────────────────────│◄───────────────│                   │
```

**v4 优化效果**：身份解析从 3 跳 2 库坍缩为纯 JWT 解析，**零额外 DB 查询**。TG 侧服务查绑定问精灵（<1ms），不经 WP。

```
旧方案（v1 — 3跳2库，每请求都跑）:
  JWT(wp_uid) → WP usermeta(精灵openid) → tgbot_verify(tg_user_id)

v2（登录时直连 WP DB 查 tg_uid）:
  Gateway pymysql → wp_usermeta → JWT

v3（统一走 zibll-oauth API）:
  /userinfo 已含 tg_uid → JWT
  空投机 → GET /user/tgbind → WP API 查绑定

v4（当前 — 精灵为 TG 侧身份权威）:
  Gateway 登录 → /userinfo(含 tg_uid) → JWT ✅
  TG Bot 查绑定 → 精灵 /api/check-bind(本地 DB) ✅
  精灵 /bind → POST /user/bindtg → WP usermeta ✅

身份权威分层:
  WP 侧: zibll-oauth (Gateway 用)
  TG 侧: 精灵 (空投机/未来Bot 用)
  Center: JWT (前端用)
```

---

### 5.4 流程 D：TG Bot 中点击"管理我的空投包"

```
用户 TG             空投机 Bot            精灵 (xyjl.1yo.cc)    浏览器
  │                      │                      │                │
  │ 完成资源包存储后       │                      │                │
  │ Bot 显示按钮           │                      │                │
  │                      │                      │                │
  │                      │ GET /api/check-bind   │                │
  │                      │ (tg_uid+sign)         │                │
  │                      │─────────────────────►│                │
  │                      │                      │                │
  │                      │ { bound: true/false } │                │
  │                      │ (查精灵本地DB, <1ms)   │                │
  │                      │◄─────────────────────│                │
  │                      │                      │                │
  │ 如果已绑定:            │                      │                │
  │ URL 按钮 → center     │                      │                │
  │ .manyuzo.com/airdrop  │                      │                │
  │ /packs (直跳前端)      │                      │                │
  │                      │                      │                │
  │ 如果未绑定:            │                      │                │
  │ callback 按钮         │                      │                │
  │ → 弹窗解释            │                      │                │
  │ → 替换为 URL 按钮      │                      │                │
  │ → 跳精灵 /start bind  │                      │                │
  │                      │                      │                │
  │ 点击"管理"按钮         │                      │                │
  │ (已绑定)              │                      │                │────►浏览器打开
  │                      │                      │                │ center 前端
  │                      │                      │                │ auth guard 自动
  │                      │                      │                │ 处理登录状态
```

---

### 5.5 流程 E：空投口令分发（用户侧）

```
普通用户 TG           空投机 Bot            空投机 DB
  │                      │                      │
  │ 发送口令文本           │                      │
  │ "XY-XDJR4L"          │                      │
  │─────────────────────►│                      │
  │                      │                      │
  │                      │ lookup_code("XY-XDJR4L")
  │                      │─────────────────────►│
  │                      │                      │
  │                      │ 返回 pack_id + 校验    │
  │                      │◄─────────────────────│
  │                      │                      │
  │                      │ increment_code_use    │
  │                      │─────────────────────►│
  │                      │                      │
  │                      │ 查询 pack_items       │
  │                      │─────────────────────►│
  │                      │                      │
  │ 转发资源包内所有文件    │                      │
  │◄─────────────────────│                      │
```

---

## 六、文件结构概览

```
services/
├── vue-vben-admin/                  # Nebuluxe Center 整体
│   ├── api_gateway/                 # FastAPI 网关 (port 8555)
│   │   ├── main.py                  # 入口，挂载路由
│   │   ├── core/
│   │   │   ├── config.py            # 配置（空投机 DB、OAuth、Bot token，无 WP DB）
│   │   │   └── security.py          # JWT 签发 & 验证
│   │   └── routers/
│   │       ├── auth.py              # OAuth 登录 + 自包含 JWT（tg_uid/name/avatar）
│   │       └── airdrop.py           # 空投包 CRUD（身份从 JWT 直取，零 DB 查询）
│   │
│   ├── apps/web-ele/                # 前端 (Element Plus 版本)
│   │   ├── .env.production          # VITE_GLOB_API_URL=/api
│   │   └── src/
│   │       ├── api/
│   │       │   ├── request.ts       # Axios 封装 + 拦截器
│   │       │   └── airdrop.ts       # 空投机 API 调用
│   │       ├── views/
│   │       │   ├── airdrop/index.vue          # 空投包管理页
│   │       │   └── _core/authentication/
│   │       │       └── oauth-callback.vue     # OAuth 回调处理
│   │       └── router/
│   │           └── routes/modules/airdrop.ts  # 路由定义
│   │
│   └── deploy.sh                    # 一键构建部署
│
├── File-Sharing-Bot/                # 小芽空投机 TG Bot
│   ├── main.py                      # Bot 入口
│   ├── config.py                    # 空投机配置
│   ├── database/database.py         # DB 操作（含 pack_codes、调精灵 /api/check-bind 查绑定）
│   └── plugins/
│       ├── store_session.py         # 资源包存储流程 + 管理按钮
│       └── code_handler.py          # 口令监听 + 投递
│
├── tgbot-verify/                    # 小芽精灵 TG Bot
│   ├── main.py
│   ├── config.py
│   ├── database_mysql.py            # users 表（含 wp_openid）
│   ├── oauth_server.py              # /bind OAuth 回调 + /api/check-bind 内部API (port 8443)
│   └── handlers/
│       ├── bind_command.py          # /bind 命令
│       ├── me_command.py            # /me 查询
│       └── exchange_command.py      # 积分兑换
│
└── zibll-oauth-main/ (WP 插件)      # OAuth2 提供方 + 身份 API
    └── includes/
        ├── service.php              # /token, /userinfo, /unionid 端点
        ├── rest-usermeta.php         # /user/bindtg, /user/tgbind 端点 (v3)
        ├── rest-points.php          # /points/add, /points/balance 端点
        └── util.php                 # openid 生成 + userinfo 含 tg_uid (v3)
```

---

## 七、身份体系一览

一个用户在整个生态中有 **4 种 ID**：

| ID 类型 | 示例值 | 来源 | 存储位置 |
|---------|--------|------|----------|
| **WP user ID** (unionid) | `1` | WordPress 注册 | `wp_users.ID` |
| **Center openid** | `oid_xj60tgwyhcolpdodtzttfxpbae09` | Zibll OAuth (Center app) | `wp_usermeta` |
| **精灵 openid** | `oid_zkykr90w8nhkei5ucc5v5lqqycg5` | Zibll OAuth (精灵 app) | `wp_usermeta` + `tgbot_verify.users.wp_openid` |
| **TG user ID** | `1861667385` | Telegram | **`wp_usermeta._xingxy_telegram_uid`** + `tgbot_verify.users.user_id` + `xiaoyaairdrop.resource_packs.admin_id` |

**v4 身份权威分层**：
```
写入: 精灵 /bind → POST /user/bindtg → update_user_meta(_xingxy_telegram_uid)
读取: Gateway 登录 → /userinfo 响应含 tg_uid → 塞入 JWT
TG侧: 空投机/未来Bot → 精灵 /api/check-bind → 本地 DB (<1ms)

身份权威分层:
  WP 侧 → zibll-oauth REST API (Gateway 用)
  TG 侧 → 精灵内部 API (TG Bot 用, sign=md5(params+key))
  Center → JWT (前端用)

登录后 JWT 自包含 tg_uid，后续请求零桥接查询。
openid 的 per-appid 差异不再影响任何业务逻辑。
```

---

## 八、API 端点清单

### 认证相关 (`/api/auth/`)

| 方法 | 路径 | 功能 |
|------|------|------|
| GET | `/api/auth/wp-login` | 发起 OAuth 授权（支持 `?redirect=` 参数） |
| GET | `/api/auth/wp-callback` | OAuth 回调：换 token → userinfo(含 tg_uid) + unionid → 签自包含 JWT |
| GET | `/api/user/info` | 返回当前登录用户信息 |
| GET | `/api/auth/codes` | 返回用户权限码 |

### 空投包管理 (`/api/airdrop/`)

| 方法 | 路径 | 功能 | 权限 |
|------|------|------|------|
| GET | `/api/airdrop/identity` | 检查身份绑定状态 | 需登录 |
| GET | `/api/airdrop/packs` | 搜索/分页列出空投包 | 需绑定 |
| GET | `/api/airdrop/packs/:id` | 空投包详情 | 需绑定，owner 或 super |
| PUT | `/api/airdrop/packs/:id` | 编辑名称/标签 | owner 或 super |
| DELETE | `/api/airdrop/packs/:id` | 删除空投包 | owner 或 super |
| GET | `/api/airdrop/packs/:id/link` | 生成/获取分享链接 | owner 或 super |
| POST | `/api/airdrop/packs/:id/codes` | 新增自定义口令 | owner 或 super |
| PUT | `/api/airdrop/codes/:id` | 编辑口令状态 | owner 或 super |

---

## 九、安全边界

| 边界 | 措施 |
|------|------|
| 前端 → Gateway | JWT Bearer Token (HS256, 7天有效期, 自包含 tg_uid/name/avatar) |
| Gateway → WP OAuth | client_id + client_secret + CSRF state (TTLCache 10min 自动过期) |
| Gateway → 空投机 DB | 独立 MySQL 用户，最小权限（不再连 WP DB） |
| Bot → WP API | appid + appkey + md5 签名 |
| 空投包权限 | 普通用户只能管理自己的包 (admin_id = tg_user_id)，super admin 可管理所有 |
| Nginx | 反代隔离，Gateway 仅绑定 127.0.0.1 |

---

## 十、部署命令备忘

```bash
# 前端构建部署
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/plugins/services/vue-vben-admin
bash deploy.sh

# Gateway 重启
pkill -f "uvicorn main:app.*8555"
cd api_gateway && nohup python3 -m uvicorn main:app --host 127.0.0.1 --port 8555 > /tmp/gateway.log 2>&1 &

# 空投机 Bot 重启
cd File-Sharing-Bot && python3 main.py

# 精灵 Bot 重启
cd tgbot-verify && python3 main.py
```
