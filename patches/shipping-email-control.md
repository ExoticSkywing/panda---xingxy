# 虚拟商品发货邮件控制补丁

## 问题

购买虚拟商品后，系统自动发送包含卡密的邮件给用户，可能导致信息泄露或不必要的邮件。

## 修复方案

在 xingxy 后台添加开关，控制虚拟商品发货邮件发送：

- **开关开启**：禁用虚拟商品（自动发货/手动发货）发货邮件
- **开关关闭**：正常发送所有发货邮件

## 文件变更

### xingxy 模块（无需补丁）

- `xingxy/inc/options.php`: 添加 `disable_virtual_shipping_email` 开关

---

### 父主题 msg.php（需补丁）

**文件**: `/inc/functions/shop/inc/msg.php`

#### 1. zib_shop_virtual_shipping_to_user 函数（约第 65 行）

```diff
 function zib_shop_virtual_shipping_to_user(array $order, array $order_meta_data)
 {
+    // [Xingxy Patch] 虚拟商品发货邮件控制
+    if (function_exists('xingxy_pz') && xingxy_pz('disable_virtual_shipping_email', true)) {
+        return; // 开关开启，禁用虚拟商品发货邮件
+    }
+
     $delivery_html = $order_meta_data['shipping_data']['delivery_content'] ?? '';
```

#### 2. zib_shop_manual_shipping_to_user 函数（约第 168 行）

```diff
 function zib_shop_manual_shipping_to_user(array $order, array $order_meta_data)
 {
+    // [Xingxy Patch] 非快递发货邮件控制 - 仅快递发货发送邮件
+    $shipping_type = $order_meta_data['shipping_type'] ?? '';
+    if (function_exists('xingxy_pz') && xingxy_pz('disable_virtual_shipping_email', true)) {
+        if ($shipping_type !== 'express') {
+            return; // 非物流快递发货，禁用邮件
+        }
+    }
+
     $delivery_type = $order_meta_data['shipping_data']['delivery_type'] ?? '';
```

## 后台设置

WordPress 后台 → 星小雅高级定制 → 高级设置 → **禁用虚拟商品发货邮件**

**更新日期**: 2026-01-28
