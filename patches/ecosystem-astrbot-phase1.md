# 星小芽生态与 AstrBot：第一阶段实施约定

日期：2026-11-08。本文记录当前决策；历史 `antigravity/ARCHITECTURE.md`、`DEVELOPER_GUIDE.md` 中“精灵是永久 TG 身份权威”和 MD5 内部签名的设计，只描述旧系统，不能直接用作新模块设计。

## 代码与数据归属

| 位置 | 职责 |
| --- | --- |
| `plugins/zibll-oauth-main/` | OAuth 授权、PKCE、令牌、后续 OIDC；现有业务接口在调用方迁移期间保留兼容性。 |
| `themes/panda/xingxy/inc/ecosystem/` | 站点业务接口、服务凭据与能力授权、权威 TG 绑定、奖励规则、积分账本、服务权益、审计。 |
| `/root/data/docker_data/xingxy-astrbot-dev/astrbot_plugin_xingxy/` | 新 Python 插件的开发目录；接收 TG 用户操作、调用业务接口、展示结果、通知和互动流程。当前不是线上 AstrBot 插件目录。 |
| 现有精灵、空投机、Center | 过渡期间保留入口与兼容接口；现有生产数据不随新开发目录自动迁移。 |

站点模块沿用 `xingxy` 独立 Git 仓库。后续由 `init.php` 加载，初次接入使用默认关闭的开关和独立测试凭据。当前目录通过 Panda 主题的 `func.php` 加载，依赖该主题及其加载条件；未来更换主题需要迁移启动入口。

代码所在目录不决定数据表前缀。新表使用 `$wpdb->prefix`，不得硬编码 `wp_`。积分规则、可兑换余额和权益以站点为权威，AstrBot 不维护第二份可兑换余额。

## 迁移方式

用户已决定：AstrBot 先使用新的测试 TG Bot；具备替代能力后，停止旧程序接收原 Bot 更新，由 AstrBot 接管原账号。正式入口保留原精灵账号。

新测试 Bot 使用独立 OAuth 应用、测试凭据、测试数据和测试奖励环境。Telegram Bot 身份和 OAuth 应用身份分别管理，不能依赖切换 Bot Token 自动转换 OpenID。

现有身份、665 TG 积分和历史关系须以切换时的新快照核对；当前观察值不是未来迁移金额。旧余额仅经可重试但不重复入账的迁移操作处理，未绑定余额保留待认领。旧 `/api/check-bind` 与 Center 管理接口先保留兼容路径。

## 第一阶段交付范围

1. PHP/Python 请求签名格式与跨语言验证。
2. 服务凭据、环境与能力授权；持久 nonce 防重放。
3. 绑定、身份查询及其状态恢复，接入 OAuth code + PKCE。
4. 签到事件由服务端计算奖励，持久业务去重、事务入账、站点积分读写兼容。
5. 查询与审计界面，以及失败请求、重启、超时、并发和越权测试。

不能仅用“新增日志表”“签名校验通过”宣告积分链路完成。必须覆盖子比消费、退款、后台调账、注册邀请等奖励入口，并确定唯一写入机制和回滚后的交易保留方式。

首批核心能力不依赖大模型。游戏、广播、知识库、卡密新增业务与完整 OIDC 不阻塞首批技术验证；是否上线其他旧功能按真实需求决定。

## 请求签名 v1（首批实现的固定接口）

私有业务请求使用 HTTPS、POST、UTF-8 JSON，无 URL 查询参数。签名以接收端 WordPress REST route 为准，例如 `/xingxy-ecosystem/v1/identity/resolve`，不包含域名或 `/wp-json`；不支持用 `?rest_route=` 接入这批接口。HTTP 集成必须验证实际方法与 route，再将其交给签名模块。

请求头：

| 头 | 内容 |
| --- | --- |
| `X-Xingxy-Key-Id` | 1–64 位 ASCII 字母、数字、下划线或连字符；凭据标识，不是 OAuth AppID。 |
| `X-Xingxy-Timestamp` | 10 位十进制 Unix 秒，无前导零。 |
| `X-Xingxy-Nonce` | 16 字节安全随机数编码为 32 位小写十六进制。 |
| `X-Xingxy-Signature` | HMAC-SHA256 的 64 位小写十六进制摘要。 |

secret 为至少 32 字节的独立随机密钥，按原始字节使用；不复用 OAuth AppKey。测试和生产使用不同凭据。服务器凭据配置决定环境与能力，不能信任客户端自行声明的环境或权限。

Canonical message 使用下列字段按顺序以 LF 连接，末尾没有 LF：

```text
XINGXY-HMAC-SHA256-V1
<key_id>
<timestamp>
<nonce>
POST
<route>
<sha256(raw_request_body) 的小写十六进制摘要>
```

route 必须为 `/xingxy-ecosystem/v1/` 后跟一个或多个 `/` 分隔的 `[a-z][a-z0-9-]*` 段；拒绝结尾斜杠、空段、点段、百分号编码、查询和 fragment。JSON 必须先编码一次，再将同一份原始字节用于签名和发送；服务器不重新序列化 JSON。

签名校验允许时钟偏差最多 300 秒（边界包含）；拒绝非法字段，不做 trim、大小写转换或隐式类型转换。nonce 在方法、route、凭据、签名、时间检查通过后由持久存储原子认领，作用域是 `(key_id, nonce)`，保留时间至少覆盖整个可能被接受的时间窗口。相同请求网络重试使用新 nonce，并沿用同一业务事件编号。

签名模块只提供 canonical message、sign、verify。它不负责凭据查找、能力判断、nonce 认领、JSON 解析、绑定归属或积分业务去重，不能单独作为生产接口的完整 permission callback。

## 业务接口边界（待下一任务细化）

| 能力 | 核心规则 |
| --- | --- |
| `identity:read` | 查询当前权威绑定，仅返回调用方获准读取的字段。 |
| `identity:bind` | 绑定双方所有权证明、唯一约束、原子完成与历史记录；完成后才宣告成功。 |
| `points:read` | 读取统一余额，无写入能力。 |
| `tasks:checkin` | 提交签到事实，奖励数额、时区、每日上限由服务器决定；不接受任意充值额度。 |

签到日按 Asia/Shanghai 计算。业务唯一条件应涵盖主账号、规则和日期，不能因切换 Bot、重复点击或更换随机请求 ID 再发一次奖励。绑定奖励应有独立的一次性业务记录，解绑不删除历史或自动清空主账号积分。

## 开发与验收分工

架构、接口、状态机与验收由当前 GPT/Codex 负责；按用户最新决定，实现子代理改用 `gpt-6-luna`，复杂独立复核使用 `gpt-6.1-sol`。密钥只在本地执行进程使用，不进入提示词、仓库或报告。

模型分工使用 Codex 原生子代理，由父代理创建、发送任务、等待和复核：

| 角色 | 模型 | 本机配置 |
| --- | --- | --- |
| 父代理：架构、任务协调、最终验收 | `gpt-6.1-sol` | `/root/.codex/xingxy.config.toml` |
| `xingxy_implementer`：按确定的接口实现代码 | `gpt-6-luna` | `/root/.codex/agents/xingxy_implementer.toml` |
| `xingxy_reviewer`：独立复核代码与测试 | `gpt-6.1-sol` | `/root/.codex/agents/xingxy_reviewer.toml` |

角色继承父会话 provider、认证与权限，不能在角色文件中另配 provider。`xingxy` 原生配置档继承现有 `config.toml` 中的 CCH Responses provider，不更改全局默认模型或凭据。

按用户在 2026-11-08 的最新决定，Luna 实现子代理明确使用 `max` 推理强度：在成本相近时优先 max，不再套用此前“所有子代理与主会话相同”的规则。创建时显式指定 `gpt-6-luna` 和 `max`；已有缓存了较低强度的实现代理须替换。GPT 独立审查保持当前主会话强度。通过原生子代理参数落实该分工，不为此改动网关、认证或全局主模型配置。

从开发目录启动本机原生会话：

```bash
codex --profile xingxy --cd /root/data/docker_data/xingxy-astrbot-dev
```

父代理依据范围调用 `xingxy_implementer` 实际编辑开发文件，再由父代理或 `xingxy_reviewer` 检查差异与测试。不得把手写 HTTP 调用器或嵌套独立 CLI 任务当作原生子代理。该目录不挂载到运行中的 AstrBot，首批代码不会自动上线。

状态：用户已取消 Gemini 子代理方案，角色配置已改用 GPT-6 Luna。若当前会话缓存旧角色模型，则使用原生 worker 并显式指定 GPT-6 Luna，不继续重试 Gemini 或改造中转服务。模型是否适合每类任务以实际实现质量、测试结果和返工情况判断，复杂任务再升级。

首批进展：GPT-6 Luna 原生实现任务已产生 PHP/Python 签名 codec；原 medium 任务停止后，由 xhigh 实现子代理完成严格类型复核，父代理独立验收。PHP 7.4、8.4 各通过 7 组跨语言检查，PHP 类已纳入 `inc/ecosystem/service-signature.php`，但尚未连接启动入口。凭据授权、nonce 存储、身份与积分业务接口仍待后续实施。

第二批进展：凭据注册与 test/production 环境隔离、固定路由 capability 映射、重复 header 拒绝、签名后 nonce 原子认领及有上限清理已实现并复制到 `inc/ecosystem/`。PHP 7.4、8.4 的服务鉴权测试和 7 组签名测试通过；GPT-6.1 Sol/xhigh 独立复核另通过 157 项只读断言。尚未接入 `init.php`，尚未执行真实 MySQL 并发、重启持久性或 REST adapter 测试。

第三批进展：身份查询、绑定一致性检查、Asia/Shanghai 签到日、事件幂等、同日冲突、事务回滚和奖励 callback 编排已实现；schema helper 仅返回 SQL，不执行建表。PHP 7.4、8.4 的鉴权、身份签到和签名回归全部通过。核心评估代码已复制到 `inc/ecosystem/`，尚未注册 REST 路由、启用生产奖励或迁移生产数据；现在可由用户进行代码与契约评测。

实现子代理产物先写入独立开发目录，主代理复核代码并执行独立测试后再按范围纳入站点仓库。首批签名基础不接入 `init.php`，不注册路由、不自动建表、不运行迁移，也不改动线上 Bot。

后续 HTTP 接入、测试凭据配置、首次测试建表与上线启用分别记录，不能把未接入的基础代码报告为已上线功能。正式生产切换需要一份可复核的迁移、验收与回退结果。

## 当前实施检查点（2026-11-08）

上述各批进展是历史记录。当前已上线真实站点身份、积分只读与受限 OAuth 绑定预览；用户已确认 `/xy_site_identity` 和 `/xy_balance` 返回真实站点身份与余额。真实浏览器授权完成链路仍需独立验收，不能用已有绑定的查询结果代替。

统一积分账本、与账本共享事务的 TG 签到、独立签到客户端以及带手续费的原子转账目前在 `/root/data/docker_data/xingxy-astrbot-dev/` 开发和验收，尚未切换生产积分写入。现有线上 AstrBot 插件为 v0.4.1，开发插件为 v0.4.2；本地模拟积分和旧机器人余额均未导入站点。

继续实施的约束与源代码调用点盘点见开发目录的 `docs/points-integration-contract.md`、`docs/points-writer-migration-matrix.md` 和 `docs/points-display-projection-plan.md`。主题原有充值、消费、退款、后台调账、免费奖励、共享签到日与持久结算恢复仍须迁移；检查点不代表第一阶段交付完成。

开发进展补充：积分历史已改为“新账本流水 + 不可变旧展示快照”，积分写入不再改写整块 `zib_other_data`，主题展示读点补丁已暂存并验证。真实 MySQL 的晚到旧快照覆盖、初始化失败回滚、并发读取、损坏状态和 128MB 限额大快照验收通过；详见开发目录 `ops/points-history-acceptance-2026-11-08.md`。此变更尚未部署或开启生产写入。
