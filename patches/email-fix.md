# 邮件通知修复补丁

## 问题描述

管理员新订单邮件无法发送，即使后台开关已开启。

## 根本原因

`zibpay/functions/zibpay-msg.php` 中的用户邮件发送函数 `zibpay_mail_payment_order` 在调用 `zib_get_wechat_template_id('payment_order')` 时发生 **PHP 致命错误**：

```
zib_get_wechat_template_id(): Argument #1 ($type) could not be passed by reference
```

该函数定义为 `function zib_get_wechat_template_id(&$type)`，需要**引用传参**，但代码直接传入字面量字符串 `'payment_order'`，导致 PHP 7+ 抛出致命错误。

致命错误会中断整个 PHP 执行流程，导致：
- ❌ 后续的管理员邮件发送代码无法执行
- ❌ 其他注册了 `payment_order_success` Hook 的函数无法执行

## 修复方案

创建 `/xingxy/inc/email-fix.php` 模块：

1. 使用 `remove_action()` 移除原有的 `zibpay_mail_payment_order` 函数
2. 注册修复后的 `xingxy_fixed_mail_payment_order` 函数
3. 修复引用传参问题：使用变量 `$type = 'payment_order'` 而非字面量

## 文件变更

| 文件 | 变更类型 | 说明 |
|------|---------|------|
| `xingxy/inc/email-fix.php` | 新增 | 邮件通知修复模块 |
| `xingxy/init.php` | 修改 | 加载新模块 |

## 验证结果

修复后测试日志：
```
10:53:41 [zibpay_mail_payment_order_to_admin] 函数被调用
10:53:41 [开关状态] email_payment_order_to_admin = true
10:53:41 [zibpay_mail_payment_order_to_admin] 准备发送邮件...
10:53:44 [zibpay_mail_payment_order_to_admin] zib_mail_to_admin 调用完成 ✅
```

管理员邮箱成功收到订单通知邮件。
