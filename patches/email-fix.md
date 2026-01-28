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
