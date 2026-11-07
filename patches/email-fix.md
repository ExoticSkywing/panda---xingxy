# 邮件通知修复补丁

## 问题描述

1. **管理员新订单邮件无法发送**
2. **虚拟商品发货邮件需要控制**

## 根本原因

panda 子主题 `zibpay/functions/zibpay-msg.php` 中多处 `zib_get_wechat_template_id()` 使用字面量传参，导致 PHP 致命错误（该函数要求引用传参）。

## 修复方案

### 1. panda 子主题 zibpay-msg.php（6处引用传参修复）

将所有字面量传参改为变量传参，并添加 try-catch 保护：

```diff
- $wechat_template_id = zib_get_wechat_template_id('payment_order');
+ $type = 'payment_order';
+ try {
+     $wechat_template_id = @zib_get_wechat_template_id($type);
+ } catch (Exception $e) {
+     $wechat_template_id = false;
+ } catch (Error $e) {
+     $wechat_template_id = false;
+ }
```

**修复位置**（行号为修复后）：
- 第 84 行：用户支付邮件
- 第 241 行：管理员新订单邮件
- 第 380 行：分成收入通知
- 第 489 行：推荐人收入通知
- 第 539 行：提现申请通知
- 第 619 行：提现处理通知

---

### 2. zibll 父主题 shop/inc/msg.php（虚拟商品发货邮件控制）

在两个发货邮件函数开头添加开关判断：

```diff
 function zib_shop_virtual_shipping_to_user(array $order, array $order_meta_data)
 {
+    // [Xingxy Patch] 虚拟商品发货邮件控制
+    if (function_exists('xingxy_pz') && xingxy_pz('disable_virtual_shipping_email', true)) {
+        return;
+    }
```

```diff
 function zib_shop_manual_shipping_to_user(array $order, array $order_meta_data)
 {
+    // [Xingxy Patch] 非快递发货邮件控制
+    $shipping_type = $order_meta_data['shipping_type'] ?? '';
+    if (function_exists('xingxy_pz') && xingxy_pz('disable_virtual_shipping_email', true)) {
+        if ($shipping_type !== 'express') {
+            return;
+        }
+    }
```

---

### 3. xingxy 模块 options.php

高级设置中添加开关：`disable_virtual_shipping_email`

## 后台设置

WordPress 后台 → 星小雅高级定制 → 高级设置 → **禁用虚拟商品发货邮件**

## 验证结果

- ✅ 管理员新订单邮件正常发送
- ✅ 虚拟商品发货邮件已禁用（开关开启时）
- ✅ 物流快递发货邮件正常发送

**更新日期**: 2026-01-28

---

### 4. Postal / 第三方平台 SMTP 凭据适配

**背景与问题**：
Postal 等发信服务的 SMTP 登录用户名是一串系统标识（例如 `islamjarataniya-gmail-com/nebuluxe`），但发件人邮箱（From）必须是已验证的域名邮箱（例如 `xingxy@tsmoe.com`）。
在子比原生后台中，「发信人邮箱账号」与「SMTP 用户名」强绑定为一个字段且受邮箱格式正则约束，直接填写 Postal 用户名会报错且阻断保存。

**解决方案**：
在 `inc/email-fix.php` 挂载 `phpmailer_init` 钩子，保持子比原生后台填写标准域名邮箱，在底层向 Postal 服务器握手时精准注入 Postal 用户名，并自动兼容 `starttls` / `tls` 协议：

```php
add_action('phpmailer_init', 'xingxy_postal_smtp_credentials', 99);
function xingxy_postal_smtp_credentials($phpmailer) {
    if (empty($phpmailer->Host)) {
        return;
    }
    if (strpos($phpmailer->Host, 'sidecloud.cc') !== false) {
        $phpmailer->Username = 'islamjarataniya-gmail-com/nebuluxe';
        if (strtolower($phpmailer->SMTPSecure) === 'starttls') {
            $phpmailer->SMTPSecure = 'tls';
        }
    }
}
```

**更新日期**: 2026-11-08
