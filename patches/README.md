# 数量限制功能 - 补丁说明

## 未记录的前置修复

### 1. 添加自定义卡密限制
添加自定义卡密，因数据库字段大小限制，导致卡密内容无法保存，修复方法是修改数据库字段大小限制

### 2. 优惠活动添加仅VIP1
VIP2享双重优惠，违背运营逻辑，修复方法是添加仅VIP1选项，详见 [vip-discount-fix.md](./vip-discount-fix.md)

## 概述
由于 Zibll 商城模块没有提供 WordPress 钩子接口，此功能需要直接修改主题文件。

## 修改清单

### 1. term-option.php
**路径**: `/inc/functions/shop/admin/options/term-option.php`
**修改**: 添加"数量限制"输入字段

### 2. discount.php  
**路径**: `/inc/functions/shop/inc/discount.php`
**修改**: 
- 添加 `zib_shop_discount_count_limit_check()` 函数
- 在折扣数据中包含 `count_limit` 字段

### 3. order.php
**路径**: `/inc/functions/shop/inc/order.php`
**修改**: 添加数量限制判断调用

### 4. main.js / main.min.js
**路径**: `/inc/functions/shop/assets/js/`
**修改**: 
- 在 `syncItemDiscountPrice` 函数中添加数量限制判断
- 需要同时修改源文件和压缩文件

### 5. dis.php
**路径**: `/inc/functions/shop/page/dis.php`
**修改**: 添加"满X件可用"标签显示

## 恢复方法

主题更新后，参考 Git 历史记录恢复修改：
```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/zibll
git diff HEAD~1 -- inc/functions/shop/
```

---

## 阶梯优惠互斥功能

详见 [tiered-discount-mutual-exclusion.md](./tiered-discount-mutual-exclusion.md)

---

## 邀请注册送积分功能

详见 [referral-points.md](./referral-points.md)

---

## 邀请任务视觉增强

详见 [referral-visual-enhance.md](./referral-visual-enhance.md)

---

## 邮件通知修复

修复管理员新订单邮件无法发送的问题。根因是 `zib_get_wechat_template_id()` 引用传参错误导致致命错误。

详见 [email-fix.md](./email-fix.md)

---

## 虚拟商品发货邮件控制

禁用非物流快递发货（自动发货/手动发货）的订单发货邮件通知。配合 xingxy 后台开关控制。

详见 [shipping-email-control.md](./shipping-email-control.md)

**更新日期**: 2026-01-28
