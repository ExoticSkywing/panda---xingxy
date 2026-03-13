# Nebuluxe 生态开发者指南

> **版本**：v1.0 — 2026-03-14
> **前置阅读**：[ARCHITECTURE.md](./ARCHITECTURE.md)（系统全景 + 数据流）
> **定位**：本文档面向所有新接入 Nebuluxe 生态的开发者。无论你要开发新的 TG Bot、新增 Web 后台模块，还是为现有服务扩展功能，本文都将指导你**在 5 分钟内理解架构，30 分钟内写出第一个可运行的业务端点**。

---

## 〇、当前阶段

| 阶段 | 状态 | 内容 |
|------|------|------|
| 一 · Vben 瘦身 + 品牌定制 | ✅ 完成 | Center 前端框架搭建 |
| 二 · FastAPI 网关 + Nginx | ✅ 完成 | API Gateway 基础设施 |
| 三 · OAuth 认证闭环 | ✅ 完成 | WP → Gateway JWT → Vben |
| 四 · 业务模块：空投包管理 | ✅ 完成 | Bot 存储 + 口令 + Center 管理页 |
| 四b · v4 身份权威分层 | ✅ 完成 | 精灵 = TG 侧身份权威，内部 API |
| **五 · 生态扩容** | 🚀 当前 | **你在这里 — 按本文范式扩展** |

**基础设施已全部就绪**，新开发者只需聚焦：**业务逻辑、用户体验、UI 优雅**。

---

## 一、30 秒理解生态

```
                    ┌─────────────────────────────┐
                    │     星小芽 WordPress          │
                    │  xingxy.manyuzo.com          │
                    │  用户身份唯一源头 + OAuth2      │
                    └──────┬──────────┬────────────┘
                           │          │
                    写 tg_uid    发 openid/token
                           │          │
              ┌────────────┴┐    ┌────┴─────────────┐
              │  小芽精灵     │    │  FastAPI Gateway   │
              │  TG 侧身份   │    │  Web 侧身份        │
              │  权威        │    │  JWT 签发           │
              │  /api/*      │    │  /api/*             │
              └──┬───────────┘    └────┬───────────────┘
                 │  查绑定               │  JWT 鉴权
           ┌─────┴─────┐          ┌─────┴─────┐
           │ 空投机      │          │ Center     │
           │ 未来Bot A   │          │ 前端 SPA   │
           │ 未来Bot B   │          │ Vue3+EleUI │
           └────────────┘          └────────────┘
```

**一句话总结**：
- **TG Bot** 查身份 → 问**精灵**（<1ms，本地 DB）
- **Web 页面** 查身份 → 解**JWT**（零 DB 查询）
- **写入身份** → 只有**精灵**经 OAuth 绑定时写入 WP

---

## 二、身份接入一页纸

### 2.1 身份权威三层分离

| 层 | 权威服务 | 消费者 | 协议 |
|----|----------|--------|------|
| **WP 侧** | zibll-oauth REST API | Gateway (登录) | OAuth2 + client_secret |
| **TG 侧** | 精灵内部 API (`xyjl.1yo.cc`) | 所有 TG Bot | GET + HMAC 签名 |
| **Center 侧** | JWT Token | 前端 SPA | Bearer Token (HS256, 7天) |

### 2.2 TG Bot 查绑定（你最常用的）

```python
import hashlib
import requests

VERIFY_API_BASE = "https://xyjl.1yo.cc"      # 精灵域名
VERIFY_API_KEY  = "<从运维获取>"               # 共享 HMAC 密钥

def check_bindstatus(tg_user_id: int) -> bool:
    """查询用户是否已绑定星小芽账号"""
    tg_uid_str = str(tg_user_id)
    sign = hashlib.md5((tg_uid_str + VERIFY_API_KEY).encode()).hexdigest()
    try:
        resp = requests.get(
            f"{VERIFY_API_BASE}/api/check-bind",
            params={"tg_uid": tg_uid_str, "sign": sign},
            timeout=5,
        )
        return resp.status_code == 200 and resp.json().get("bound", False)
    except Exception:
        return False  # 精灵不可用时降级为"未绑定"
```

### 2.3 引导用户绑定

未绑定时，引导用户跳转精灵的 `/bind` 流程：

```python
# Telegram deep link → 精灵自动触发 /bind
bind_url = "https://t.me/moemoji_bot?start=bind"
```

精灵的 `/start bind` deep link 会自动注册用户（如需）并直接进入 OAuth 绑定流程。用户完成 WP 授权后，精灵回调页面显示"绑定成功"，TG 内同步通知。

### 2.4 Gateway 路由中获取身份

```python
from core.security import verify_token

@router.get("/api/yourmodule/data")
async def get_data(request: Request):
    token = request.headers.get("authorization", "").replace("Bearer ", "")
    payload = verify_token(token)
    if not payload:
        raise HTTPException(status_code=401, detail="未登录")

    tg_uid   = payload.get("tg_uid")       # TG user ID (int)
    wp_uid   = payload.get("wp_uid")       # WordPress user ID (int)
    is_super = payload.get("is_super")     # 超级管理员 (bool)
    name     = payload.get("name")         # 用户昵称 (str)
    # ... 业务逻辑，直接用 tg_uid 查你自己的 DB
```

**JWT 已自包含全部身份信息，零额外查询**。

---

## 三、扩容范式 A：新增 TG Bot

### 3.1 脚手架

```
services/
└── my-new-bot/
    ├── .env              # BOT_TOKEN, DB 连接, VERIFY_API_BASE, VERIFY_API_KEY
    ├── .gitignore        # 务必包含 .env, config.py
    ├── config.py         # 读 os.environ，导出配置变量
    ├── main.py           # Bot 入口
    ├── database/
    │   └── database.py   # 自有业务 DB（每个 Bot 独立库）
    └── plugins/          # 命令/消息处理器
        └── ...
```

### 3.2 必选配置（仅 2 项身份相关）

```python
# config.py — 身份相关配置
VERIFY_API_BASE = os.environ.get("VERIFY_API_BASE", "https://xyjl.1yo.cc")
VERIFY_API_KEY  = os.environ.get("VERIFY_API_KEY", "")
```

### 3.3 绑定检查范式

```python
# 用户触发需要身份的功能时
is_bound = check_bindstatus(message.from_user.id)

if not is_bound:
    keyboard = InlineKeyboardMarkup([
        [InlineKeyboardButton("🔗 前往绑定", url="https://t.me/moemoji_bot?start=bind")]
    ])
    await message.reply("需要先绑定星小芽账号才能使用此功能", reply_markup=keyboard)
    return

# 已绑定 → 执行业务逻辑
```

### 3.4 关键约束

| 规则 | 原因 |
|------|------|
| **不要**直接连 WP 数据库 | 耦合灾难，WP 迁移全部服务挂 |
| **不要**自建绑定/注册流程 | 精灵是唯一 TG 侧身份入口 |
| **不要**存储 wp_openid 到自己的 DB | openid 是 per-appid 的，你拿不到也没用 |
| **只用** `tg_user_id` 作为自有 DB 主键 | 全生态唯一，稳定不变 |
| **查绑定**只问精灵 | 一个 API 调用，<1ms |
| **敏感配置**放 `.env`，`config.py` 加入 `.gitignore` | 密钥不入库 |

### 3.5 对接 Center 管理页（可选）

如果你的 Bot 需要 Web 管理后台，继续阅读 [范式 B](#四扩容范式-b新增-web-业务模块)。

---

## 四、扩容范式 B：新增 Web 业务模块

> 适用场景：为某个 TG Bot 或独立业务增加 Web 管理后台。
> 参考实现：空投包管理（`routers/airdrop.py` + `views/airdrop/`）。

### 4.1 后端：Gateway 新增路由

**新建文件** `api_gateway/routers/yourmodule.py`：

```python
"""
你的模块路由
身份解析：完全从 JWT 取 tg_uid，零 DB 查询。
"""
import pymysql
from fastapi import APIRouter, HTTPException, Request, Query
from core.config import settings
from core.security import verify_token

router = APIRouter()

# ─── DB 连接 ───
def _get_conn():
    return pymysql.connect(
        host=settings.YOUR_DB_HOST,
        port=settings.YOUR_DB_PORT,
        user=settings.YOUR_DB_USER,
        password=settings.YOUR_DB_PASSWORD,
        database=settings.YOUR_DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )

# ─── 身份解析 ───
def _get_identity(request: Request) -> dict:
    """从 JWT 提取身份，已登录返回 payload，否则抛 401"""
    token = request.headers.get("authorization", "").replace("Bearer ", "")
    payload = verify_token(token)
    if not payload:
        raise HTTPException(status_code=401, detail="请先登录")
    return payload

# ─── 业务端点示例 ───
@router.get("/items")
async def list_items(request: Request, page: int = Query(1, ge=1)):
    identity = _get_identity(request)
    tg_uid = identity.get("tg_uid")
    is_super = identity.get("is_super", False)

    conn = _get_conn()
    try:
        with conn.cursor() as cur:
            if is_super:
                cur.execute(
                    "SELECT * FROM your_table ORDER BY id DESC LIMIT 20 OFFSET %s",
                    ((page - 1) * 20,),
                )
            else:
                cur.execute(
                    "SELECT * FROM your_table WHERE owner_tg_id = %s ORDER BY id DESC LIMIT 20 OFFSET %s",
                    (tg_uid, (page - 1) * 20),
                )
            items = cur.fetchall()
        return {"items": items, "page": page}
    finally:
        conn.close()
```

**注册路由** — `api_gateway/main.py` 添加：

```python
from routers import yourmodule
app.include_router(yourmodule.router, prefix="/api/yourmodule", tags=["Your Module"])
```

**新增 DB 配置** — `api_gateway/core/config.py` 添加：

```python
# ─── 你的模块 MySQL ───
YOUR_DB_HOST: str = os.getenv("YOUR_DB_HOST", "localhost")
YOUR_DB_PORT: int = int(os.getenv("YOUR_DB_PORT", "3306"))
YOUR_DB_USER: str = os.getenv("YOUR_DB_USER", "")
YOUR_DB_PASSWORD: str = os.getenv("YOUR_DB_PASSWORD", "")
YOUR_DB_NAME: str = os.getenv("YOUR_DB_NAME", "")
```

### 4.2 前端：Center 新增页面

```
apps/web-ele/src/
├── api/
│   └── yourmodule.ts           # API 调用封装
├── views/
│   └── yourmodule/
│       └── index.vue           # 页面组件
└── router/
    └── routes/modules/
        └── yourmodule.ts       # 路由定义
```

**API 封装** — `api/yourmodule.ts`：

```typescript
import { requestClient } from '#/api/request';

// 注意：前缀 /api 由 Axios baseURL 自动补，这里写相对路径
export function getItems(params?: Record<string, any>) {
  return requestClient.get('/yourmodule/items', { params });
}

export function updateItem(id: number, data: Record<string, any>) {
  return requestClient.put(`/yourmodule/items/${id}`, data);
}

export function deleteItem(id: number) {
  return requestClient.delete(`/yourmodule/items/${id}`);
}
```

**路由定义** — `router/routes/modules/yourmodule.ts`：

```typescript
import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    path: '/yourmodule',
    name: 'YourModule',
    meta: { title: '模块名', icon: 'lucide:box' },
    children: [
      {
        path: 'list',
        name: 'YourModuleList',
        component: () => import('#/views/yourmodule/index.vue'),
        meta: { title: '列表', icon: 'lucide:list' },
      },
    ],
  },
];

export default routes;
```

> **提示**：`requestClient` 没有 `patch` 方法，编辑操作用 `put` 代替。

### 4.3 从 TG Bot 跳转 Center 管理页

```python
# TG Bot 按钮 → 直接跳前端页面（不走 wp-login）
InlineKeyboardButton(
    "◆ 管理 ↗",
    url="https://center.manyuzo.com/yourmodule/list",
)
```

**重要**：不要跳 `/api/auth/wp-login?redirect=...`。前端有 auth guard，未登录时会自动触发 OAuth 流程，已登录则直接进页面。这样用户不需要每次都重新授权。

### 4.4 部署清单

```bash
# 1. Gateway 重启（加载新路由）
pkill -f "uvicorn main:app.*8555"
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/plugins/services/vue-vben-admin/api_gateway
nohup python3 -m uvicorn main:app --host 127.0.0.1 --port 8555 > /tmp/gateway.log 2>&1 &

# 2. 前端构建部署（自动 build web-ele + 复制到 center.manyuzo.com）
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/plugins/services/vue-vben-admin
bash deploy.sh

# 3. TG Bot 重启（如有改动）
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/plugins/services/your-bot
python3 main.py

# 4. 精灵 Docker 重建（仅精灵代码有改动时）
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/plugins/services/tgbot-verify
docker compose down && docker compose up -d --build
```

---

## 五、架构约束（红线）

### 5.1 绝对禁止

| ❌ 禁止行为 | 原因 |
|-------------|------|
| 直连 WordPress 数据库 | 破坏隔离，WP 迁移时全挂 |
| 新建 OAuth 应用来做绑定 | 精灵是唯一 TG 侧绑定入口，openid per-appid 不互通 |
| 在 JWT 之外存用户会话 | Gateway 无状态设计，重启不丢登录态 |
| 把 `INTERNAL_API_KEY` / `VERIFY_API_KEY` 提交到 Git | 安全红线 |
| 修改 `_xingxy_telegram_uid` 的写入逻辑 | 只有精灵经 zibll-oauth `/user/bindtg` API 写入 |
| TG Bot 之间直接互调 | 通过精灵 API 中继或 Gateway 路由 |
| 用 Python `.format()` 处理含 CSS 的 HTML 模板时不转义 | `{}` 必须写成 `{{}}` |

### 5.2 必须遵循

| ✅ 规范 | 说明 |
|---------|------|
| `tg_user_id` 作为 TG 侧全局主键 | int64，稳定不变，用它关联一切 |
| 每个 Bot 独立数据库 | 命名规范：`xiaoya<botname>` |
| 敏感配置用 `.env` | `config.py` 读 `os.environ`，加入 `.gitignore` |
| Gateway 路由用 JWT 鉴权 | 统一用 `verify_token(token)` 获取身份 |
| 前端跳转用直链 | `center.manyuzo.com/path`，auth guard 自动处理登录 |
| Pyrogram handler 必须 `stop_propagation()` | 处理完消息后调用，防止泄漏到其他 group 的 handler |
| 精灵 Docker 部署 | 代码 COPY 进镜像，改动后必须 `docker compose up -d --build` |

---

## 六、UX 与 UI 规范

### 6.1 TG Bot 交互原则

| 原则 | 实践 |
|------|------|
| **静默优于打扰** | 用户发无关消息，Bot **不回复**（不要设 USER_REPLY_TEXT） |
| **弹窗解释** | 需解释的操作用 `answer(show_alert=True)` 弹窗，不要在按钮文案上堆字 |
| **按钮变形** | 弹窗后可 `edit_reply_markup` 替换按钮（如 callback → URL 按钮） |
| **一步到位** | 绑定跳精灵 `/bind`，管理页直接跳 Center 前端，不绕 OAuth 中间页 |
| **不重复授权** | 按钮 URL 指向前端页面，不指向 `/api/auth/wp-login` |
| **按钮文案简洁** | `"◆ 管理空投包 ↗"` 而非 `"🔗 先绑定星小芽站点账号再管理空投包 ↗"` |
| **stop_propagation** | 所有 handler 处理完消息后必须调用，包括媒体消息 |

### 6.2 Center 前端原则

| 原则 | 实践 |
|------|------|
| **搜索优先** | 主页面首要元素是搜索框，数据量大时不做无限滚动列表 |
| **卡片式展示** | 内容型数据用卡片布局，不用传统表格 |
| **行内编辑** | 简单字段（名称、标签）支持行内编辑，不弹 Modal |
| **复制即走** | 链接/口令类数据一键复制，不需要进详情页 |
| **Element Plus** | 生产构建固定用 `web-ele`（Element Plus 版本） |
| **构建部署** | `bash deploy.sh` 一键完成，不手动 rsync |

---

## 七、服务清单与端口

| 服务 | 端口 | 域名 | 部署方式 | 重启命令 |
|------|------|------|----------|----------|
| Nginx | 80/443 | *.manyuzo.com | 系统服务 | `nginx -s reload` |
| WordPress | — | xingxy.manyuzo.com | 宝塔 PHP | 宝塔面板 |
| Gateway | 8555 | center.manyuzo.com/api/* | nohup uvicorn | `pkill -f 8555 && nohup ...` |
| Center SPA | — | center.manyuzo.com | 静态文件 | `bash deploy.sh` |
| 精灵 Bot + OAuth | 8443 | xyjl.1yo.cc | Docker (sheerid-tgbot) | `docker compose down && up -d --build` |
| 空投机 Bot | 18688 | — | 直接运行 | `python3 main.py` |

---

## 八、新服务接入 Checklist

### TG Bot 接入

- [ ] 创建 `services/my-bot/` 目录
- [ ] `.env` 配置 `BOT_TOKEN`、DB 连接、`VERIFY_API_BASE`、`VERIFY_API_KEY`
- [ ] `config.py` 读 `.env`，加入 `.gitignore`
- [ ] 创建独立 MySQL 数据库（不共享）
- [ ] 实现 `check_bindstatus(tg_user_id)` 调精灵 API
- [ ] 未绑定用户引导跳 `t.me/moemoji_bot?start=bind`
- [ ] 所有消息处理器使用 `stop_propagation()`
- [ ] 测试绑定/未绑定两种场景

### Web 模块接入

- [ ] `api_gateway/routers/yourmodule.py` 新增路由文件
- [ ] `api_gateway/core/config.py` 新增 DB 配置项
- [ ] `api_gateway/main.py` 注册路由 (`include_router`)
- [ ] 所有端点使用 JWT 鉴权（`verify_token`）
- [ ] `apps/web-ele/src/api/yourmodule.ts` API 封装
- [ ] `apps/web-ele/src/views/yourmodule/` 页面组件
- [ ] `apps/web-ele/src/router/routes/modules/yourmodule.ts` 路由定义
- [ ] TG Bot 管理按钮直接跳 `center.manyuzo.com/yourmodule/...`
- [ ] Gateway 重启 + `bash deploy.sh` 前端部署
- [ ] 端到端测试（TG 按钮 → Center 页面 → API 调用）

---

## 九、数据流速查

```
═══ 场景1：新用户首次使用 TG Bot ═══

  Bot → GET 精灵/api/check-bind → { bound: false }
  Bot → 回复引导消息 + "前往绑定" URL 按钮
  用户点击 → 跳精灵 → /start bind → /bind → OAuth 授权
  精灵 → 写 tgbot_verify.users.wp_openid
  精灵 → POST /user/bindtg → 写 wp_usermeta._xingxy_telegram_uid
  精灵 → TG 通知 "绑定成功！"
  Bot → GET 精灵/api/check-bind → { bound: true } ✅

═══ 场景2：用户从 TG Bot 跳转 Center 管理页 ═══

  用户点击 "管理 ↗" → 打开 center.manyuzo.com/xxx
  ├── 已有 JWT token → 直接进页面 ✅
  └── 无 token → auth guard 跳 OAuth → WP 授权
      → Gateway /wp-callback → 签 JWT(tg_uid,name,avatar,is_super)
      → 前端存 token → 跳回目标页 ✅

═══ 场景3：Center 页面 API 请求 ═══

  前端 → GET /api/yourmodule/items (Bearer JWT)
  Gateway → verify_token(JWT) → 直取 tg_uid（零 DB 查询）
  Gateway → 查你的 DB WHERE owner_tg_id = tg_uid
  Gateway → 返回业务数据 ✅
```

---

## 十、FAQ

**Q: 我的 Bot 需要知道用户的 WP user ID 吗？**
A: 不需要。TG 侧全部用 `tg_user_id`。WP user ID 只在 Gateway JWT 内部使用。

**Q: 怎么判断用户是不是超级管理员？**
A: TG 侧：检查 `tg_user_id in ADMINS`（Bot 自有 config 配置）。Web 侧：JWT 中 `is_super` 字段。

**Q: 精灵 API 挂了怎么办？**
A: `check_bindstatus` 的 `except` 分支返回 `False`，降级为"未绑定"体验。用户体验不中断，只是暂时无法使用需绑定的功能。

**Q: 能不能不用精灵，直接调 WP API 查绑定？**
A: **不能**。v4 架构核心就是精灵为 TG 侧身份权威。精灵查本地 DB（<1ms），WP API 需走网络+PHP（>100ms）。且避免所有 Bot 都持有 WP API 凭证。

**Q: 前端用 Element Plus 还是 Ant Design？**
A: 生产用 `web-ele`（Element Plus）。构建脚本 `deploy.sh` 已固定 `build:ele`。

**Q: 新 Bot 能部署到其他服务器吗？**
A: 可以。只需网络能访问 `https://xyjl.1yo.cc`（精灵 API），配好 `VERIFY_API_BASE` + `VERIFY_API_KEY` 即可。签名验证基于 HMAC，与部署位置无关。

**Q: openid 能当全局用户 ID 用吗？**
A: **不能**。openid 是 per-appid 的，精灵和 Center 对同一个 WP 用户生成的 openid 完全不同。全局用 WP user ID（Web 侧）或 `tg_user_id`（TG 侧）。

**Q: 如何给精灵 API 新增端点？**
A: 编辑 `tgbot-verify/oauth_server.py`，添加 handler 函数 + `app.router.add_get/post`。记得用 `_verify_internal_sign` 做签名校验。改完需 `docker compose up -d --build`。

---

> **记住**：基础设施已就绪。你只需聚焦 **业务逻辑 · 用户体验 · UI 优雅**。
