# 推广链接伪装 & 商城返佣修复

## 修改日期
2026-02-19

## 概述
重构推广链接系统，将原始的数学编解码方案替换为数据库映射方案，实现推广链接伪装（用户无感知）。同时修复 Zibll 商城返佣始终为 0 的参数继承 Bug。

## 问题描述

### 问题一：推广码编解码 Bug
旧方案使用数学算法对用户 ID 进行编解码，在特定 ID 下会产生解码错误，导致推荐关系无法建立。

### 问题二：商城返佣始终为 0
当商品的推广返佣设为"默认"（继承上级配置）时，`product_config` meta 中存储了 `rebate.type = ""`（空字符串）。`zib_shop_get_product_in_turn_config` 将此非空数组视为有效配置，不再向全局配置 fallback，导致佣金计算始终返回 0。

**数据链路**：
```
商品 meta: rebate.type = ""（空字符串，代表"默认"）
→ zib_shop_get_product_config: 返回非空数组 → !config = false
→ 不 fallback 到全局 shop_rebate（type=ratio, 15%）
→ zib_shop_get_product_rebate_config: empty(type)=true → 返回 []
→ zib_shop_get_order_rebate_data: rebate_price = 0
```

## 变更文件

### 1. `xingxy/inc/referral-tracker.php` (新增)
核心追踪模块，包含以下功能：

| 函数 | 说明 |
|------|------|
| `xingxy_get_user_ref_code()` | 为用户生成唯一 5 位随机推广码，存 `_xingxy_ref_code` user_meta |
| `xingxy_decode_ref_code()` | 数据库查询解析推广码 → 用户ID |
| `xingxy_generate_referral_url()` | 生成伪装推广链接（`?utm_source=share&v=2&_s={code}&_t={ts}`） |
| `xingxy_intercept_referral_tracking()` | `template_redirect` 优先级 0，解析 `_s` 参数，写入 HttpOnly Cookie + Session |
| `xingxy_restore_referrer_from_cookie()` | `user_register` 优先级 5，从 Cookie 恢复推荐人到 Session |
| `main_user_tab_content_rebate` filter | 佣金详情页旧链接替换为伪装链接 |
| `get_post_metadata` filter | **修复商城返佣 Bug**：拦截 `product_config` 读取，空 `type` 替换为全局配置 |

### 2. `xingxy/inc/options.php` (修改)
- 更新推广设置说明文案
- 新增「推广追踪窗口（小时）」配置项
- 移除已废弃的「隐藏推广链接参数」开关

### 3. `xingxy/inc/assets.php` (修改)
- 移除对 `referral-hide.js` 的加载
- `wp_localize_script` 改用 `xingxy_generate_referral_url()` 生成推广链接

### 4. `xingxy/assets/js/referral.js` (修改)
- 移除 `hideReferralParam()` 函数体
- `getReferralData()` 适配新的 `_s` 参数格式

### 5. `xingxy/init.php` (修改)
- 在 `referral.php` 之前加载 `referral-tracker.php`

## 后台配置
**路径**: WordPress 后台 → 星小雅高级定制 → 推广设置

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| 推广追踪窗口（小时） | Cookie 有效期 | 24 |

商城返佣配置无需更改，修复后会正确继承全局配置：
**路径**: 主题设置 → 商城商品 → 商品参数 → 推广返佣

## 恢复方法

### xingxy 模块
```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/panda/xingxy
git log --oneline -5  # 查看提交历史
git revert <commit>   # 回滚对应提交
```

### 返佣修复的独立回滚
如果仅需移除返佣修复（保留推广链接伪装），删除 `referral-tracker.php` 文件末尾的 `get_post_metadata` filter（约第 225-275 行）。

## 技术细节

### 推广码方案对比
| | 旧方案（数学编解码） | 新方案（数据库映射） |
|---|---|---|
| 存储 | 无 | `_xingxy_ref_code` user_meta |
| 编码 | `base64 + 位移 + salt` | 5 位随机码 |
| 解码 | 逆向数学运算 | `WHERE meta_value = %s` |
| 可靠性 | ❌ 特定 ID 解码错误 | ✅ 100% 可靠 |
| 格式 | `?ref=编码值` → `?_s=编码值` | `?_s=随机码` |

### 返佣修复原理
通过 `get_post_metadata` filter 在 `product_config` 被读取时介入。当 `rebate.type` 为空字符串（商品选择"默认"选项时的存储值）时，将整个 `rebate` 子数组替换为全局 `shop_rebate` 配置值，实现正确的参数继承。

**影响范围**：仅影响 `rebate.type` 为空的商品，不影响已设为"不参与"、"按比例"或"固定金额"的商品。

### 验证结果
| 项目 | 修复前 | 修复后 |
|---|---|---|
| `rebate_config.type` | `""` (空) | `"ratio"` |
| `rebate_price`（¥13.90商品） | 0 | **¥2.09** (15%) |
| 累计佣金显示 | ¥0 | **¥2.09** ✅ |
