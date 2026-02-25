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

---

## 卡密编辑功能

为卡密管理添加编辑功能，支持单条编辑（卡号、密码、备注）和批量修改（备注）。

详见 [card-edit.md](./card-edit.md)

---

## Shop 模块 VIP 引导功能 (V5)

在 Shop 商品详情页实现“加购+VIP引导+原价购”三按钮布局，智能计算 VIP 优惠并引导升级。

详见 [shop-vip-promo.md](./shop-vip-promo.md)

**更新日期**: 2026-02-11

---

## 控制台净化

移除 Zibll/Panda 主题在浏览器控制台的版权日志（如 "Zibll Theme" 等推广信息），保持控制台清爽。

**相关文件**: `inc/console-cleaner.php`

---

## 商城优惠码集成

将 zibpay 优惠码系统集成到商城购买流程。在订单确认弹窗中注入优惠码输入框，支持验证、折扣计算、次数扣减。通过 hooks 拦截实现，不修改主题原始文件。

详见 [shop-coupon.md](./shop-coupon.md)

**更新日期**: 2026-02-12

---

## 星盟阶段一：创作分成支持商城商品

修复创作分成"我的商品" tab 不显示 `shop_product` 的问题。修改 `panda/zibpay/functions/zibpay-income.php`，`post_type` 加入 `shop_product`，移除不兼容的 `zibpay_type` meta 条件。

详见 [staralliance-income.md](./staralliance-income.md)

**更新日期**: 2026-02-18

---

## 星盟阶段二：前台商品发布系统

实现合作方在前台直接发布和管理商城商品的能力。包含页面模板、AJAX 处理、权限控制、用户中心入口、样式文件，以及 Zibll 主题 sidebar 布局兼容修复。

详见 [staralliance-frontend-product.md](./staralliance-frontend-product.md)

**更新日期**: 2026-02-19

---

## 推广链接伪装 & 商城返佣修复

重构推广链接系统为数据库映射方案（推广码伪装、HttpOnly Cookie 持久追踪），并修复 Zibll 商城返佣参数继承 Bug（商品设"默认"时 `rebate.type` 存为空字符串导致不 fallback 到全局配置，佣金始终为 0）。

详见 [referral-tracker-rebate-fix.md](./referral-tracker-rebate-fix.md)

**更新日期**: 2026-02-19

---

## 星盟：商品管理体验优化

优化商品管理列表（支持查看全部）、编辑器工具栏（复用文章发布页按钮）、暗色模式文字颜色修复。

详见 [staralliance-product-improvements.md](./staralliance-product-improvements.md)

**更新日期**: 2026-02-21

---

## 星盟阶段三：卡密与发货设置

前台商品发布增加发货设置（固定内容/卡密），合作商可导入卡密（type=`partner_custom`，后台隔离）。子主题 `charge-card.php` 增加合作商卡密筛选 tab。

详见 [staralliance-cardpass.md](./staralliance-cardpass.md)

**更新日期**: 2026-02-22

---

## 前台卡密管理与发货区 UI 优化

前台卡密管理增加列表查看、编辑、删除功能，发货设置区域 UI 优化。

详见 [staralliance-cardpass-manage.md](./staralliance-cardpass-manage.md)

**更新日期**: 2026-02-23

---

## 卡密发货守护：库存校验 & 部分发货 & 自动补发

对卡密自动发货流程全面增强：支付前校验库存、部分发货、导入卡密后自动补发、支付成功弹窗 AJAX 轮询、发货信息一键复制按钮、部分发货订单状态精确控制（不再误触确认收货）、待发货状态可查看已发内容。涉及父主题 `pay.php`、`action.php`、`user-center.php` 的修改。

详见 [shipping-guard-fix.md](./shipping-guard-fix.md)

**更新日期**: 2026-02-25

---

## 前台商品发布 - 补充完善增强功能

在前台商品发布的基础上，增加推广返佣设置模块，优化 PC 及移动端底部操作栏、重写保存即时生效（免审核）与 AJAX 同步行为。

详见 [newproduct-enhancement.md](./newproduct-enhancement.md)

**更新日期**: 2026-02-25

