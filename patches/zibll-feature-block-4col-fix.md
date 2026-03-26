# Zibll 亮点块 4 列布局修复

> **补丁日期**: 2026-03-26
> **影响范围**: Zibll 主题 `zibllblock/feature`（亮点块）在前台显示为 3+1 列而非 4 列
> **修复位置**: Panda 子主题（不动 Zibll 源码）
> **诊断耗时**: ~45 分钟，跨多轮排查

---

## 1. 现象

在 xingxy.manyuzo.com 发布包含 4 个亮点块的帖子时：

- **期望**: 4 个亮点块排成一行（与 Zibll 官方 demo 一致）
- **实际**: 3 个在第一行，第 4 个溢出到第二行
- **关键线索**: 刷新后**最初几秒**显示 4 列，随后跳变为 3+1 列

## 2. 根因分析（三层叠加）

### 2.1 第一层：wp-block-library 完整包被加载

Zibll 是经典主题（非 block 主题）。在 WP 6.8.x 中，`_add_default_theme_supports()` 里的
`add_filter('should_load_separate_core_block_assets', '__return_true')` 只对 block 主题生效。
经典主题会无条件加载完整的 `wp-block-library` CSS（~5KB），其中包含影响 `inline-block` 元素的规则。

而 WP 6.9 在 `script-loader.php:3613` 新增了**无条件**的同名 filter，对所有主题生效。
这就是为什么 Zibll 官方 demo（WP 6.9）没问题，但 xingxy（WP 6.8.3）有问题。

**关键代码位置**:
- WP 6.8.3: `wp-includes/theme.php` → `_add_default_theme_supports()` → 仅 block 主题
- WP 6.9:   `wp-includes/script-loader.php:3613` → `add_filter('should_load_separate_core_block_assets', '__return_true', 0)` → 所有主题

### 2.2 第二层：Footer 延迟加载的 CSS 触发回流

Panda 子主题在 `wp_footer` 中加载了多个 CSS 文件：

| 文件 | 大小 | 来源 |
|------|------|------|
| `vue/index.css` | **318KB** | Element UI 完整包 |
| `szgotop.css` | 小 | 返回顶部组件 |
| `kuakua.css` | 小 | 夸夸组件 |

WordPress 自身还在 footer 输出了 `global-styles-inline-css`（8858 字节，含 `is-layout-*` 规则）。

这些 CSS 在 `</body>` 前加载，导致浏览器**重新计算样式并回流**（reflow）。

### 2.3 第三层：inline-block 空白间隙——真正的脆弱点

Zibll 的 `.feature` 块使用 `display: inline-block` 布局：

```css
/* zibll/css/main.css:9444 */
.feature {
    width: calc(25% - 14px);
    display: inline-block;
    vertical-align: middle;
    margin: 5px;
}
```

4 个块的总宽度计算：
```
每个块: calc(25% - 14px) + margin-left(5px) + margin-right(5px) = calc(25% - 4px)
4 个块: calc(100% - 16px)
```

看似有 **16px 的余量**，但 `inline-block` 元素之间的 HTML 空白字符（换行/空格）
会被浏览器渲染为约 **4px 的间隙**（取决于 font-size）。

```
4 个块 + 3 个空白间隙: calc(100% - 16px) + ~12px = calc(100% - 4px)
```

**只剩 ~4px 容差！** 任何微小的 CSS 变化（sub-pixel rounding、字体回流、延迟 CSS 加载）
都可能击穿这个余量，导致第 4 个块溢出。

### 因果链

用户在**任何修复之前**就观察到：刷新后最初几秒显示 4 列，随后跳变为 3+1 列。
这说明 inline-block 布局本身就处于临界状态——初始渲染勉强够 4 列，
但 footer 延迟 CSS 加载触发回流后立刻击穿余量。

```
页面初始渲染（仅 head CSS）
        ↓
inline-block + calc(25%-14px) + ~4px 空白间隙 × 3 = 勉强塞下 4 列
        ↓
几秒后 footer 加载 318KB Element UI CSS + WP 全局样式
        ↓
浏览器重新计算样式 → 回流 → 空白间隙/sub-pixel 微调 → 第 4 个溢出 → 3+1 列
```

三层问题同时存在，共同导致了这个现象：
1. **wp-block-library 完整包**让余量更小（WP 6.8 vs 6.9 差异）
2. **Footer 延迟 CSS**触发回流（318KB Element UI + 全局样式）
3. **inline-block 空白间隙**是根本脆弱点（~4px 容差）

## 3. 修复方案

### 3.1 修复一：对齐 WP 6.9 的 block CSS 按需加载行为

**文件**: `panda/func.php:69-72`

```php
// WP 6.9 默认按需加载 block CSS，WP 6.8 仍加载完整 wp-block-library 包
// 完整包会导致 Zibll 亮点块等 inline-block 布局溢出（4列变3列）
// 此 filter 与 WP 6.9 行为对齐，优先级 0 允许其他插件覆盖
add_filter( 'should_load_separate_core_block_assets', '__return_true', 0 );
```

**效果**: 阻止加载完整的 `wp-block-library` CSS，改为按需加载单个块的样式。
消除了初始渲染时的布局破坏。

### 3.2 修复二：Flexbox 替代 inline-block，彻底消除空白间隙

**文件**: `panda/xingxy/inc/assets.php:119-147`

```php
add_action('wp_enqueue_scripts', function() {
    wp_add_inline_style('_main', '
        .wp-posts-content:has(> .wp-block-zibllblock-feature) {
            display: flex !important;
            flex-wrap: wrap !important;
        }
        .wp-posts-content > .wp-block-zibllblock-feature.feature {
            display: block !important;
            float: none !important;
            flex: 0 0 calc(25% - 14px) !important;
            width: calc(25% - 14px) !important;
            margin: 5px !important;
        }
        .wp-posts-content > :not(.wp-block-zibllblock-feature) {
            flex: 0 0 100% !important;
        }
        @media (max-width: 991px) {
            .wp-posts-content > .wp-block-zibllblock-feature.feature {
                flex: 0 0 calc(50% - 14px) !important;
                width: calc(50% - 14px) !important;
            }
        }
    ');
}, 99);
```

**为什么用 Flexbox 而不是 float**:

| 方案 | 空白间隙 | 精度容差 | 与 JS height 兼容 |
|------|---------|---------|------------------|
| `inline-block`（原始） | ❌ 有 ~4px/间隙 | ❌ ~4px | ✅ |
| `float: left` | ✅ 无 | ⚠️ 理论上够，实测仍溢出 | ✅ |
| **`flexbox`** | **✅ 无** | **✅ 16px** | **✅** |

**关键细节**:
- CSS handle 是 `_main`（Zibll 通过 `_cssloader()` 注册，handle = `'_' . $key`）
- `:has()` 选择器确保只在有 feature 块时才改变父容器的 display
- 非 feature 子元素设为 `flex: 0 0 100%` 保证不受影响
- Zibll JS `main.js:4137-4144` 会设置 `.feature` 统一 height，只影响垂直方向，与 flex 兼容
- 响应式断点 `991px` 与 Zibll 原始媒体查询保持一致

## 4. 排查过程中的关键发现

### 4.1 Zibll CSS Handle 命名规则

Zibll 使用 `_cssloader()` 函数（`zib-theme.php:1075`）批量注册样式：
```php
_cssloader(array(
    'bootstrap'   => 'bootstrap.min',
    'fontawesome' => 'font-awesome.min',
    'main'        => 'main.min',       // → handle = '_main'
));
```

`wp_add_inline_style` 必须使用正确的 handle `_main`，否则 CSS 不会输出。

### 4.2 WP 全局样式在 Footer 输出

WordPress 的 `global-styles-inline-css`（8858 字节）在 `wp_footer()` 中输出，
而不是 `wp_head()`。这包含 `is-layout-flex` 等布局规则和所有 CSS preset 变量。

### 4.3 Zibll 亮点块 JS 高度均衡

```javascript
// zibll/js/main.js:4137-4144 — 在 $(document).ready() 中执行
if ($('.feature').length) {
    var _feh = 0, _fehm = 0;
    $('.feature').each(function () {
        var _th = $(this);
        _feh = _th.find('.feature-icon').innerHeight()
             + _th.find('.feature-title').innerHeight()
             + _th.find('.feature-note').innerHeight();
        _fehm = Math.max(_fehm, _feh);
    });
    $('.feature').css('height', _fehm);  // 设置统一高度
}
```

这段 JS 在 DOM ready 后给所有 `.feature` 设置相同的 pixel height。
它只影响垂直方向，不会干扰 flexbox 的水平布局。

## 5. 升级注意事项

- **WP 升级到 6.9+**: 修复一（`should_load_separate_core_block_assets` filter）将变为冗余
  但不会产生冲突（WP 6.9 自带同样的 filter，优先级也是 0）
- **Zibll 主题更新**: 如果 Zibll 改用 flexbox 或 grid 原生实现 4 列，修复二可能需要移除
- **Element UI CSS 优化**: 理想情况下应该按需加载 Element UI CSS 而非在 footer 加载完整 318KB

## 6. 验证

- 帖子 URL: `https://xingxy.manyuzo.com/688.html`（ID 688，标题"亮点"）
- 强制刷新 (Ctrl+Shift+R) 后确认 4 列稳定显示
- 等待几秒确认无跳变
- 检查 991px 以下断点自动切换为 2 列
