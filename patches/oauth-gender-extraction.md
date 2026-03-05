# OAuth 社交登录性别数据自动提取与画像展示

**日期**: 2026-03-05
**涉及文件**:
- `inc/user-profile.php`
- `inc/admin-profile-dashboard.php`

## 背景

通过 QQ/微信等第三方社交登录的用户，可能跳过邮箱绑定而不触发盲盒问卷，导致画像性别字段为空。但彩虹聚合登录的 OAuth 回调数据中已包含 `gender` 字段，且被 Zibll 静默存储在 `oauth_{type}_getUserInfo` 的 `zib_other_data` 打包字段中。

## 改动

### 1. 新增 `xingxy_get_oauth_gender()` 工具函数
- 遍历所有社交登录类型（微信/QQ/微博/Google 等），从 `oauth_{type}_getUserInfo` 中提取 `gender`
- 自动标准化多平台返回格式（男/male/1/m 等）
- 返回结构：`['gender' => '男'|'女'|'', 'source' => 'weixin'|'qq'|...]`

### 2. 后台用户列表「隐形画像」列增强
- 修复了 Zibll `zib_users_columns()` 重写列结构导致该列不显示的 bug（优先级 10 → 99，锚点 `registered` → `all_time`）
- 问卷数据为空时自动降级显示 OAuth 推断性别 + 来源徽章（如 `👤 微信`）
- 有人工打标时显示 `✨ 人工打标` 标记

### 3. 画像数据中心面板扩展
- 查询条件新增 `oauth_new EXISTS`，社交登录用户也纳入管理范围
- 性别列：问卷无数据时降级显示 OAuth 推断值 + 来源徽章
- 证据列：OAuth-only 用户显示 `[OAuth] 数据来源：微信 社交登录授权`
- 人工打标后显示：~~原推断~~ **新标注** `✨人工`（PHP + JS 双端同步）

### 4. 优先级体系
```
人工打标 (xingxy_manual_gender) > 盲盒问卷推断 (xingxy_profile_data) > OAuth 社交登录推断
```

## 备注
- Google OAuth 自 2019 年起不再默认返回 `gender`，该渠道用户仍需走问卷或人工打标
- 微信/QQ/微博均可正常提取性别
