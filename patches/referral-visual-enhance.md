# 推广任务视觉增强 (Referral Visual Enhancement)

## 🎯 目标 (Objective)
将 "邀请好友注册" 任务卡片从默认样式改造为 **高定级(High-End)** 视觉体验。
深度适配 Panda 主题夜间模式，实现大气、融合、生动且高交互的效果。

## 🎨 视觉与交互方案 (Final Design: Scheme Q)

### 1. 核心视觉 (Core Visuals)
*   **流体背景 (Fluid Bubbles)**:
    *   **Day**: 清新水彩 (Pastel)。
    *   **Night**: **灰紫星云 (Grey-Purple Nebula)**，深邃且高饱和度。
    *   **Animation**: 高速流动 (Turbo Speed, 10-15s)，高动态视觉反馈。
*   **极光文字 (Aurora Text)**:
    *   夜间模式下，文案表面有 **浅金/浅紫流光** 循环扫过。
    *   JS 强制剥离主题默认样式，确保 100% 可见度。

### 2. 核心交互 (Core Interaction: Dynamic Toggle)
*   **组件**: 胶囊型动态滑块 (Capsule Toggle)，替代传统按钮。
*   **逻辑**: 
    *   点击 "复制链接" -> 滑块左移 -> 触发复制。
    *   点击 "推广海报" -> 滑块右移 -> 弹出海报。
*   **技术栈**: **Class Driven + Direct Binding**。
    *   使用纯 JS (`.state-copy` / `.state-poster`) 控制滑块位置，不依赖 Radio Input。
    *   使用直接事件绑定 (`$el.on('click')`) 对抗主题的 `stopPropagation` 拦截。

### 3. 布局重构 (Layout Refactor)
*   **左侧**: 🎁 高清 SVG 悬浮图标 (60px+)。
*   **中间**: 标题 + 极光流光文案。
*   **右侧**: 积分数字 + Dynamic Toggle 组件。
*   **标签**: 贴边实心渐变红 "福利" 标签。

## 🛠️ 文件变更 (File Changes)

### 1. `assets/js/referral.js`
*   **Dynamic Toggle 构建**: 使用 jQuery 构建纯 DOM 结构，不依赖 Radio/Label 机制。
*   **事件冲突修复 (Event Fix)**: 
    *   Panda 主题的 `.btn-poster` 会阻止事件冒泡。
    *   解决方案：在创建元素时**直接绑定** `click` 处理函数，优先于主题委托执行。
*   **样式强制**: 运行时移除 `.muted-color` 类名。

### 2. `assets/css/referral.css`
*   **UI 组件**: 定义 `.xingxy-toggle-control` 及指示器动画。
*   **状态驱动**:
    *   `.state-copy .xingxy-toggle-indicator { transform: translateX(0) }`
    *   `.state-poster .xingxy-toggle-indicator { transform: translateX(100%) }`
*   **动画库**: 定义了 `moveInCircle`, `moveVertical`, `text-shine` 等关键帧。

## ⚠️ 维护说明 (Maintenance)
*   **Toggle 失效？**: 检查 `referral.js` 中是否正确添加了 `.state-poster` 类名。
*   **文案变灰？**: 检查是否有新的 CSS 覆盖了 `.xingxy-referral-desc` 的权重，或 JS 未能成功移除 `.muted-color`。
*   **SVG 显示异常？**: 检查 `config.iconHtml` 中的 SVG 代码转义是否正确。

## 📝 版本历史 (History)
- **v1.0 - v3.0**: 边框/背景/普通按钮迭代 (Archived)
- **v4.0**: Glass Button + Aurora Text (Archived)
- **v5.0 (Final)**: 
    - **Dynamic Toggle 组件** (交互重构)
    - **Turbo Speed Animation** (视觉加速)
    - **Direct Event Binding** (Bug 修复)
