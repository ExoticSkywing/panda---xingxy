# 生态业务模块

站点生态业务的代码归属为本目录。OAuth 协议由现有 OAuth 插件负责，TG 交互由 AstrBot 的 Python 插件负责。

2026-11-08 已将只读身份接口接入 `xingxy/init.php`：`bootstrap.php` 仅在站点外私有配置显式启用时注册 `POST /xingxy-ecosystem/v1/identity/resolve`。独立服务密钥仅授予 `identity:read`，首批仅允许当前测试 TG 账号。`wpdb-binding-reader.php` 查询现有 TG usermeta，并拒绝冲突绑定；`read-api.php` 校验签名、nonce、权限、JSON 与调用范围。此次只创建 transport nonce 表，不迁移绑定或修改真实积分。

第一批 PHP/Python 请求签名 codec 已完成并复核，目前用于只读身份请求。

第二批服务鉴权层已用于只读接口：`service-credentials.php` 负责环境隔离和能力注册，`nonce-store.php` 负责持久 nonce 的 SQL 原子认领，`service-auth.php` 负责固定路由授权、签名检查和失败关闭。配置和密钥均存于站点外私有文件；启动入口不自动建表。

第三批核心评估闭环已完成：`ecosystem-schema.php` 只生成事件和绑定表结构，`identity-checkin.php` 提供绑定查询、上海日期、一次性签到事件和奖励回调编排。它仍未注册 REST 路由、未执行建表、未启用生产奖励，等待评测后再进入 adapter 和迁移阶段。

Python 开发源码位于 `/root/data/docker_data/xingxy-astrbot-dev/astrbot_plugin_xingxy/`。已将 v0.2.0 安装到运行中的 AstrBot，保留本地模拟模式，新增 `/xy_site_identity` 用于真实站点查询；查询不会返回积分或写入身份。

独立跨语言测试位于 `/root/data/docker_data/xingxy-astrbot-dev/tests/test_service_signature.py`。PHP 7.4 与 8.4 各通过 7 组检查，包括字节格式一致性、签名篡改、严格输入类型、原始二进制正文和时钟边界。

真实 HTTP 已验证当前 TG 身份关联站点用户 1，以及 nonce 并发重放、篡改、越权、JSON 与查询参数拒绝。真实绑定和积分前后指纹一致。后续仍需实现 OAuth 绑定流程、绑定迁移、完整积分账本和事务入账；当前不提供真实签到奖励。

同日已继续部署 OAuth 绑定测试入口：`link-api.php` 提供签名发起/确认与浏览器回调，`link-sessions.php` 保存短期 state/PKCE/确认码哈希，`binding-repository.php` 与 `transaction-db.php` 在固定 mysqli 连接中完成绑定和会话消费。旧绑定/解绑经 `legacy-binding.php` 进入同一事务入口。AstrBot v0.3.0 提供 `/xy_bind` 和 `/xy_bind_confirm`，使用独立的仅绑定权限密钥；站点 OAuth 客户端密钥不离开 WordPress。

隔离 MySQL 已验证争绑并发、断线回滚、重放与实际 WordPress 格式化兼容。生产已验证授权发起、登录跳转和回调取消；真实浏览器授权与 TG 确认仍待用户验收。此部署未改变现有绑定与积分，旧机器人余额尚未迁移，也未开启真实积分奖励。运维记录见开发目录 `ops/oauth-link-rollout-2026-11-08.md`。

开发目录的 `site/ecosystem/service-signature.php` 是本批实现暂存副本。后续改动须复核站点版本与 Python 实现的兼容性，不得将两个副本当作独立协议演进。

完整约定见 [第一阶段实施约定](../../patches/ecosystem-astrbot-phase1.md)。
