# 商城优惠码集成

## 概述
将 zibpay 优惠码系统集成到商城购买流程中。商城的 `shop_submit_order` 独立于 zibpay 通用订单流程，本模块通过 WordPress hooks 注入优惠码验证和折扣逻辑，实现购买时输入优惠码、验证、折扣计算、次数扣减的完整闭环。

## 变更文件

### 1. `xingxy/inc/shop-coupon.php` (新增)
- **功能**: 后端核心逻辑。
- **AJAX 端点**: `xingxy_shop_check_coupon` — 验证优惠码，返回折扣信息。
- **Handler 替换**: 通过 `remove_action` + `add_action` 替换原 `shop_submit_order` handler。
  - 无优惠码时直接调用原函数 `zib_shop_ajax_submit_order()`，行为不变。
  - 有优惠码时走自定义逻辑：验证 → 计算折后价 → 按比例分摊到子订单 → 创建订单 → 扣减优惠码次数。
- **优惠码次数扣减**: 直接操作 `ZibCardPass::update()` 更新 `used_count`、`used_order_num`、`status`。
- **重要**: 不使用 `zibpay_is_allow_coupon`（基于 `posts_zibpay` meta，不适用于 `shop_product`），也不依赖 `_pz('coupon_post_s')` 全局开关。

### 2. `xingxy/assets/js/shop-coupon.js` (新增)
- **功能**: 前端交互逻辑。
- **DOM 注入**: 通过 MutationObserver 监听确认弹窗出现，注入优惠码输入框到 `.order-info-box` 后方。
- **AJAX 拦截**: 通过 `$.ajaxPrefilter` 拦截 `shop_submit_order` 请求，追加 `coupon` 参数。
- **Vue 数据**: 通过 `window.VueShopConfirmData`（PetiteVue 响应式数据）更新价格显示。

### 3. `xingxy/assets/css/shop-coupon.css` (新增)
- **功能**: 优惠码输入框样式。
- **特性**: 复用主题 CSS 变量，支持深色模式，移动端适配。

### 4. `xingxy/init.php`
- **修改**: 添加 `require_once XINGXY_PATH . 'inc/shop-coupon.php'`。

### 5. `xingxy/inc/assets.php`
- **修改**: 在商品详情页和购物车页加载 `shop-coupon.css` 和 `shop-coupon.js`。
- **新增**: `xingxy_is_shop_page()` 辅助函数。

## 技术要点

### 为什么不能调用 `zibpay_is_allow_coupon`
该函数通过 `get_post_meta($post_id, 'posts_zibpay', true)` 检查是否允许优惠码，但商城商品 (`shop_product`) 没有 `posts_zibpay` meta，始终返回 false。

### 为什么不能调用 `zibpay_payment_order_use_coupon`
该函数签名为 `zibpay_payment_order_use_coupon($order)`，接收 order 对象并从 `$order->other` 提取 `coupon_id`。商城订单提交后没有通过 `payment_order_success` hook 触发，需直接操作 `ZibCardPass::update()`。

### PetiteVue
商城弹窗使用 PetiteVue（非标准 Vue 2/3），响应式数据存储在 `window.VueShopConfirmData` 全局变量上。
