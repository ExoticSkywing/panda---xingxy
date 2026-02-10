# Shop 模块 VIP 引导功能 (V5)

## 概述
在 Shop 商品详情页（单一产品页）增加 VIP 升级引导区域。针对未登录或普通用户，展示 VIP 优惠价格，并引导升级。

## 变更文件

### 1. `inc/functions/shop/inc/single.php`
- **操作**: 覆盖父主题文件。
- **修改**: 
  - 注入 `vip_promo` 数据到 Vue `$v_data`。
  - 修改 `zib_shop_single_content` 中的购买按钮 HTML 模板。
  - 实现 [加购] + [VIP导流] + [原价购] 三按钮布局。

### 2. `xingxy/inc/vip-promo.php` (新增)
- **功能**: 提供 `xingxy_get_vip_promo_data($post_id)` 函数。
- **逻辑**: 
  - 支持读取商品 Meta 中的固定 VIP 价格 (`vip_1_price`, `vip_2_price`)。
  - 支持动态计算 VIP 折扣（基于 `zib_shop_get_product_discount`）。
  - 返回 VIP 名称、价格、节省金额等信息。

### 3. `xingxy/assets/css/vip-promo.css` (新增)
- **功能**: 定义 VIP 引导按钮的样式。
- **样式**: 
  - `.xingxy-vip-group-btn`: **流光金**渐变背景，带呼吸动画，Flex 权重 1.8。
  - `.xingxy-vip-secondary-btn`: 浅灰背景，支持深色模式适配。
  - 适配 Zibll 原生 `.but-group` 布局。

### 4. `xingxy/inc/assets.php`
- **修改**: 在 `xingxy_enqueue_assets` 中排队加载 `vip-promo.css`。

### 5. `xingxy/init.php`
- **修改**: 引入 `inc/vip-promo.php`。

## 布局演进
- **V1-V2**: 上下堆叠双按钮（被废弃，破坏原生高度）。
- **V3**: 三按钮均分并排（被废弃，过宽重叠）。
- **V4**: 极简主次分离（被废弃，用户认为“丑”）。
- **V5**: 原生三按钮并排。
- **V6 (最终融合版)**: 
  - 左侧：[加入购物车] 独立圆角胶囊，带物理间距。
  - 右侧：[VIP引导] + [原价购买] 组合为**连体长胶囊**（VIP左圆右直，原价左直右圆），消除割裂感，强化整体性。
  - **重要修复**: 移除 `pay-vip` 类名，防止 Zibll 原生 JS 拦截 URL 跳转并弹出购买弹窗。
  - **体验优化**: 链接增加 `target="_blank"`，在新标签页打开，防止流失当前页面状态。
  - **移动端适配**: 
    - 修改 `zib_shop_single_footer_tabbar` 函数，使移动端底部栏同步 V6 布局。
    - **V8 配色**: 
      - 购物车：**微粉色背景 (#fff2f2)** + SVG 图标（红芯白粉），参考抖音商城风格，既显原色又不干扰。
      - 原价购买：浅红底红字，解决“灰色隐形”问题。
    - **V8 配色**: 
      - 购物车：**微粉色背景 (#fff2f2)** + SVG 图标（红芯白粉），参考抖音商城风格，既显原色又不干扰。
      - 原价购买：浅红底红字，解决“灰色隐形”问题。
    - **图标定制**: 购物车按钮移除文字，替换为用户指定的 SVG 图标，直观且节省空间。
    - **性能修复**: 将移动端购物车按钮 CSS 类名更名为 `xingxy-mobile-cart-btn`，避免与 `data-onclick` 目标发生递归冲突导致的点击延迟。
    - **样式修复**: 强制重置 `.shop-paybtn-group` 容器样式（透明背景、无圆角、溢出可见），防止内部独立按钮被父级 `.but-group` 样式截断。
    - CSS 强制不换行，隐藏 VIP 图标，精简间距，确保在小屏下完美显示。

## 截图
> (请参考前台效果)
