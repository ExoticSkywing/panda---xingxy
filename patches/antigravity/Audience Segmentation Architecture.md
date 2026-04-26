# 受众分群生态架构（Audience Segmentation Architecture）

> 日期：2026-03-21（初稿）→ 2026-03-24（口令透传方案定稿）→ 2026-04-21（分享链接打标方案升级）
> 状态：Phase 0 技术方案已锁定（v3：分享链接为主，口令为辅）
> 前置文档：ARCHITECTURE.md、与TG侧融合的生态架构.md、Integrate Bot and Zibll Points.md

---

## 〇、本文档解决什么问题

生态的流量来自全球多个社交平台（X、QQ、YouTube、抖音、小红书、Instagram…），每个平台最多承载 1-2 个业务方向。所有流量汇入星小芽作为统一着陆点后，面临一个核心矛盾：

> **不同业务的用户群体，应该看到不同的内容、进入不同的 TG 子频道、获取不同的资源包——但当前的 VIP 等级门槛模型，既不能区分群体，又违背用户心理。**

本文档提出 **"标签分群"（Segment Tagging）** 架构，替代纵向 VIP 阶梯，在星小芽论坛侧和 TG 侧同步实现 **千人千面** 的内容可见性与资源准入控制。

---

## 一、为什么 VIP 阶梯模型不适合

### 1.1 模型对比

```
VIP 阶梯模型（纵向）                标签分群模型（横向）
━━━━━━━━━━━━━━━━━━━                ━━━━━━━━━━━━━━━━━━━

VIP3 ── 全部内容                    [标签A] ── A 专属内容
 ↑ 花钱/花时间                       [标签B] ── B 专属内容
VIP2 ── 部分内容                    [标签A+B] ── 都能看
 ↑ 花钱/花时间                       [general] ── 公共内容
VIP1 ── 少量内容
 ↑ 注册                             标签不是"赚"来的，
游客 ── 公开内容                     是"带"来的或"触发"获得的
```

### 1.2 用户心理分析

| 场景 | VIP 模型下的用户体验 | 标签模型下的用户体验 |
|------|---------------------|---------------------|
| 用户从 X 的推广链接进入，只想看一个特定资源 | "要开 VIP？我就想看一个东西啊" → 流失 | 进来就能看（因为推广链接自带标签）→ 留存 |
| 用户有兴趣但不想花钱 | "VIP 太贵了/等级要慢慢升" → 挡板 | 标签是免费的，按来源自动获得 → 无挡板 |
| 用户想看更多不同类型的内容 | "再升一级才行" → 挫败感 | "做个任务/买个商品就获得新标签" → 成就感 |

**核心区别**：VIP 是一块挡板，阻止水的流入。标签是分流器，让水自然流入对应河道。

### 1.3 结论

VIP 等级适合的场景是"内容质量分层"（普通 vs 精品 vs 独家），但不适合"内容类型分群"（A 业务 vs B 业务）。我们的场景是后者，因此需要标签分群模型。

> VIP 等级可以保留作为**付费增值层**（如去广告、优先下载、专属客服），但不应作为内容可见性的主要门控机制。

---

## 二、标签分群模型定义

### 2.1 核心概念：Segment（受众标签）

一个 segment 代表一类用户群体。用户可以拥有一个或多个 segment 标签。

```
segment 的属性：
├── slug        唯一标识符，如 "biz_a"、"biz_b"、"general"
├── name        显示名称，如 "A 业务用户"
├── color       标签颜色（后台展示用）
├── tg_channel  对应的 TG 子频道 ID（可选）
└── auto_rules  自动打标规则（可选）
```

### 2.2 标签的来源：分享链接即标签载体（升级定稿 2026-04-21）

> **核心洞察**：标签应该在管理员**分享内容的那一刻**被配置。全站任何内容（文章、商品、帖子）都有分享按钮，管理员点击分享时——像飞书设置链接权限一样——配置"此链接打什么标签、生命周期多长"。系统生成带 share key 的链接，用户通过该链接注册即自动打标。
>
> 1yo.cc 口令作为**文字场景补充**保留——当不方便发链接时（如抖音评论区），用纯文字口令引导用户跃迁。

**一条分享链接承载两个功能**：
- **管理员**：① 打标签（配置 tag + 生命周期）② 建立推荐关系
- **普通用户**：仅 ② 建立推荐关系（标签通过树型继承自推荐人）

按优先级排列：

| 优先级 | 途径 | 触发时机 | 机制说明 |
|--------|------|----------|------|
| **①** | **分享链接打标** | 管理员点击分享 → 配置标签+生命周期 → 生成链接 | 链接携带 `_sk`（share key），服务端存储配置，防篡改。三层追踪跨浏览器不丢失（详见 §3.1.1）。 |
| **②** | **1yo.cc 口令透传** | 不方便发链接时，纯文字告知口令 | 口令作为 `_gate` 参数透传，三层追踪，注册时查映射表打标（详见 §3.1.2）。 |
| **③** | **推荐链继承 (树型)** | 普通用户分享，无标签配置权限 | 继承推荐人的 `_xingxy_segments`（详见 §3.1.3）。 |
| **④** | **商品购买/任务** | 在星小芽或商城完成特定行为 | 业务逻辑触发自动追加标签。 |
| **⑤** | **管理员手动** | 后台/Center 维护 | 管理员手动赋予或调整标签。 |

**`general` 标签**：所有注册用户保底拥有，如果以上所有途径都未匹配，则退化为 `general`。

> **设计演进**：
> - v1（已废弃）：伪装短链 + 302 跳转 + WebView 检测
> - v2（2026-03-24）：1yo.cc 口令透传三层追踪
> - **v3（2026-04-21）**：分享链接为主力打标载体，口令为文字场景补充

### 2.3 标签的存储

```
WordPress wp_usermeta:
  user_id:  123
  meta_key: _xingxy_segments
  meta_value: ["general", "biz_a"]    ← JSON 数组，支持多标签
```

选择 WordPress usermeta 作为唯一存储源头，理由：
- 与现有的 `xingxy_profile_data`、`_xingxy_telegram_uid` 等 meta 一致
- 星小芽是身份统一中心，标签属于用户身份的一部分
- TG 侧通过精灵绑定关系查询即可获取

### 2.4 标签的传播路径

```
                    ┌────────────────────────┐
                    │   WordPress usermeta    │
                    │  _xingxy_segments       │
                    │  ["general", "biz_a"]   │
                    └───────┬────────────────┘
                            │ 唯一数据源
            ┌───────────────┼───────────────┐
            ▼               ▼               ▼
     论坛帖子过滤        精灵查询         Center 展示
     (WP 查询时         (绑定后读取       (Gateway 从
      meta_query)        user_meta)       /userinfo 获取)
```

不存在"同步"问题，因为只有一份数据。各系统都从 WordPress usermeta 读取。

---

## 三、三层架构详解

### 3.1 第一层：身份层（标签分配与存储）

#### 3.1.1 分享链接打标（核心机制，定稿 2026-04-21）

> **设计原则**：标签在分享时配置，链接即载体，服务端存储防篡改，三层追踪跨浏览器不丢失。
> **一条链接两个功能**：管理员的分享链接同时承载 ① 打标签 ② 建立推荐关系；普通用户的分享链接仅承载推荐关系。

##### 管理员分享流程

```
管理员在任意内容页（文章/商品/帖子）点击"分享"按钮
        │
        ▼
  Zibll 原生分享面板 + 管理员增强区域：
  ┌─────────────────────────────┐
  │ 🏷️ 打标签: [选择 segment ▼]   │
  │ ⏳ 时间限制: [7天 ▼]          │
  │ 🔢 次数限制: [100次 ▼]        │
  │ 📋 [复制链接]  [生成二维码]     │
  └─────────────────────────────┘
  （普通用户看不到此增强区域，只有原生分享功能）
        │
        ▼
  系统生成 share_key，写入 xingxy_share_links 表
        │
        ▼
  输出短链：xingxy.manyuzo.com/go/Xf9kQ2
  （用户看到的是干净短链，不暴露追踪参数）
```

##### 数据存储：xingxy_share_links 表

分享链接的配置存储在自定义表中（一个管理员可生成多条链接，不适合 usermeta）：

```sql
CREATE TABLE xingxy_share_links (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    share_key   VARCHAR(12) UNIQUE NOT NULL,  -- /go/{key} 短链路径 + #fragment，随机生成
    user_id     BIGINT NOT NULL,              -- 生成者（即推荐人）
    tag_slug    VARCHAR(50) NULL,             -- 打什么标签（仅管理员可设，普通用户为 NULL）
    content_url VARCHAR(500) NOT NULL,        -- 分享的具体内容 URL
    max_uses    INT NULL,                     -- 最大打标次数（NULL=不限）
    used_count  INT DEFAULT 0,                -- 已打标次数
    expires_at  DATETIME NULL,                -- 打标过期时间（NULL=永久）
    clicks      INT DEFAULT 0,                -- 链接点击数（分析用）
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**生命周期**：`max_uses` 和 `expires_at` 各自独立可选——可以只设时间、只设次数、或双重限制（任一达到即停止打标）。两者均为 `NULL` 时永久有效。链接本身始终可正常访问内容，生命周期仅控制"是否继续为新注册用户打标"。

##### 着陆后三层追踪（URL 混淆方案）

> **设计目标**：全程对用户隐藏追踪参数。分享链接为短链（`/go/Xf9kQ2`），站内浏览时信号藏在 URL fragment（`#Xf9kQ2`）中——看起来像页面锚点，用户几乎不会注意。

```
用户点击短链：xingxy.manyuzo.com/go/Xf9kQ2
        │
        ├─── 【第一层：PHP 短链重定向】
        │    WordPress rewrite: /go/{key} → PHP handler
        │    → 读 share_key → setcookie('_xsk', 'Xf9kQ2', 7天)
        │    → clicks++ → 302 重定向到 content_url
        │    用户浏览器地址栏：xingxy.manyuzo.com/688.html（干净 URL）
        │
        ├─── 【第二层：JS Fragment 双向桥接】
        │    每个页面加载时（wp_footer）：
        │    ┌─ 有 Cookie _xsk?
        │    │   → replaceState 补入 #Xf9kQ2 到地址栏
        │    │   （看起来像锚点，用户无感知）
        │    │
        │    └─ 没有 Cookie，但 URL 有 #Xf9kQ2?
        │        → 从 fragment 提取 key → JS 写 Cookie
        │        （系统浏览器着陆时，从 URL 恢复信号 ✅）
        │
        └─── 【第三层：注册表单隐藏字段】
             读 Cookie _xsk → <input type="hidden" name="_sk">
        │
        ▼
  用户注册 → user_register 钩子：
  1. 读取 POST._sk || Cookie._xsk → 查 xingxy_share_links 表
  2. 校验生命周期：expires_at 未到 AND used_count < max_uses
  3. 通过 → 打标 _xingxy_segments + 建立推荐关系 + used_count++
  4. 未通过 → 降级到 _gate / _s / general
  5. 清除 Cookie
```

**用户全程视角**：
1. 在社交平台看到 `xingxy.manyuzo.com/go/Xf9kQ2`（干净短链）
2. 点击 → 重定向到 `688.html`（无任何参数）
3. 站内浏览 → 地址栏 `xxx.html#Xf9kQ2`（像锚点，无感知）
4. "用浏览器打开" → `#Xf9kQ2` 跟随 URL 传到系统浏览器 → JS 恢复 Cookie → 追踪延续
5. 注册 → 隐藏字段提交 → 打标完成。**全程无明显追踪痕迹**

**为什么用 Fragment（`#`）**：
- 不参与 HTTP 请求 → 服务器日志不记录，隐私友好
- 不影响页面加载和缓存
- "用浏览器打开"时会随 URL 传递
- 看起来像页面锚点，用户不会主动删除

##### 注册时优先级链

```
user_register 钩子（优先级 5）：

1. 有 _sk（分享链接）且生命周期有效?
   → 查 xingxy_share_links 表 → 获取 tag_slug → 打标
   → 分享者 user_id 作为推荐人 → 建立推荐关系
   → used_count++

2. 没有 _sk，有 _gate（口令）?
   → 查 gate_segment_mapping → 打标

3. 没有 _sk/_gate，有 _s（推荐码）?
   → 继承推荐人的 _xingxy_segments

4. 都没有?
   → 分配 "general"
```

#### 3.1.2 1yo.cc 口令透传（文字场景补充）

> 当管理员在**不方便发链接的平台**（如抖音评论区、微信群纯文字消息）推广时，用纯文字告知用户"在 1yo.cc 输入口令 xxx 即可到达"。1yo.cc 是最外层的跃迁壳。

##### 全链路流程

```
用户在社交平台看到纯文字口令（如 "shop"）
        │
        ▼
  1yo.cc 输入口令 "shop" → grantAccess() 跳转
  目标 URL 自动追加 _gate 参数：?_gate=shop
        │
        ▼
  着陆星小芽 → 三层追踪（Cookie _xgate + JS replaceState + 隐藏字段）
  机制与 §3.1.1 完全一致，只是参数名为 _gate 而非 _sk
        │
        ▼
  注册时查 gate_segment_mapping 配置表 → 打标
```

##### 三层追踪覆盖矩阵（分享链接 `_sk` 使用 Fragment 方案）

| 场景 | 第一层 短链重定向 | + 第三层隐藏字段 | + 第二层 Fragment 桥接 |
|------|:----------------:|:---------------:|:--------------------:|
| 内置浏览器直接注册 | ✅ Cookie 已设 | ✅ | ✅ |
| 着陆页即切"用浏览器打开" | ✅ Cookie 已设 | ✅ | ✅ fragment 跟随 |
| 浏览数页后在注册页切换浏览器 | ❌ Cookie 丢 | ✅ fragment→Cookie | ✅ |
| 浏览数页后在任意页切换浏览器 | ❌ | ❌ | ✅ fragment→Cookie 恢复 |

##### 与分享链接的区别

| 维度 | 分享链接 (`_sk`) | 口令 (`_gate`) |
|------|-----------------|---------------|
| 场景 | 可以发链接的平台 | 只能发文字的平台 |
| URL 格式 | `/go/Xf9kQ2`（短链）→ 站内 `#Xf9kQ2`（fragment） | `?_gate=shop`（query param） |
| 混淆 | ✅ 全程无明显追踪参数 | ❌ 参数可见（延后，暂不实现） |
| 配置粒度 | per-link（每条链接独立设置） | 全局（CSF 面板统一映射） |
| 生命周期 | 时间+次数独立可选 | 无限制（口令存在即有效） |
| 推荐关系 | 自动（分享者=推荐人） | 无（口令不绑定个人） |
| 数据存储 | `xingxy_share_links` 表 | CSF `gate_segment_mapping` 配置 |

##### 口令 → Segment 映射配置

在星小芽后台 CSF 面板中维护映射表（`gate_segment_mapping`）：

```
口令          →  Segment Slug
─────────────────────────────
shop          →  biz_a
pxkjvip       →  biz_a
yzq           →  biz_b
xhs           →  biz_b
dy618         →  campaign_dy618
douyin        →  biz_c
（默认）       →  general
```

纯配置表，新增渠道 = 新增一条映射，**星小芽端零代码改动**。

##### 实现参考代码

文件：`xingxy/inc/gate-tracker.php`（新建）

```php
<?php
// ========================================
// 第一层：PHP 短链重定向（/go/{key}）
// ========================================

// 注册 WordPress rewrite 规则
add_action('init', function () {
    add_rewrite_rule('^go/([A-Za-z0-9]+)/?$', 'index.php?xingxy_sk=$1', 'top');
});
add_filter('query_vars', function ($vars) {
    $vars[] = 'xingxy_sk';
    return $vars;
});

// 处理短链请求：设 Cookie → 302 重定向到内容页
add_action('template_redirect', function () {
    $sk = get_query_var('xingxy_sk');
    if (empty($sk)) return;

    global $wpdb;
    $link = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}xingxy_share_links WHERE share_key = %s", $sk
    ));
    if (!$link) {
        wp_redirect(home_url('/'));
        exit;
    }

    // 设置追踪 Cookie（httpOnly=false，JS 需要读取）
    setcookie('_xsk', $sk, time() + 7 * 86400, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false);
    // 记录点击
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}xingxy_share_links SET clicks = clicks + 1 WHERE id = %d",
        $link->id
    ));
    // 302 重定向到内容页（干净 URL，无任何追踪参数）
    wp_redirect($link->content_url);
    exit;
}, 0);

// ========================================
// 第二层：JS Fragment 双向桥接
// ========================================
// Cookie → Fragment：确保地址栏带信号（内置浏览器中）
// Fragment → Cookie：系统浏览器着陆时恢复信号
add_action('wp_footer', function () {
    $sk = $_COOKIE['_xsk'] ?? '';
    if (empty($sk) && empty($_COOKIE['_xgate'])) {
        // 无追踪信号时仍需输出 JS（可能有 fragment 需要恢复）
    }
    ?>
    <script>
    (function(){
        var ck = <?php echo json_encode($sk); ?>;
        var h = location.hash.slice(1);
        if (ck) {
            // Cookie 存在 → 确保 fragment 带信号（用于"用浏览器打开"渡信号）
            if (h !== ck) {
                history.replaceState(null, '', location.pathname + location.search + '#' + ck);
            }
        } else if (/^[A-Za-z0-9]{6,12}$/.test(h)) {
            // 无 Cookie 但 fragment 像 share_key → 从 URL 恢复 Cookie（系统浏览器着陆）
            var exp = new Date(Date.now() + 7*864e5).toUTCString();
            document.cookie = '_xsk=' + h + ';path=<?php echo esc_js(COOKIEPATH); ?>'
                + ';expires=' + exp
                + '<?php echo is_ssl() ? ";secure" : ""; ?>;samesite=lax';
        }
    })();
    </script>
    <?php
}, 999);

// ========================================
// 第三层：注册表单隐藏字段
// ========================================
add_action('zib_register_form_end', function () {
    $sk   = $_COOKIE['_xsk']   ?? '';
    $gate = $_COOKIE['_xgate'] ?? '';
    if ($sk) {
        echo '<input type="hidden" name="_sk" value="' . esc_attr($sk) . '">';
    } elseif ($gate) {
        echo '<input type="hidden" name="_gate" value="' . esc_attr($gate) . '">';
    }
});

// ========================================
// 注册时打标（优先级链）
// ========================================
add_action('user_register', function ($user_id) {
    $tagged = false;

    // ① 分享链接 _sk
    $sk = sanitize_text_field($_POST['_sk'] ?? $_COOKIE['_xsk'] ?? '');
    if ($sk) {
        global $wpdb;
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}xingxy_share_links WHERE share_key = %s", $sk
        ));
        if ($link && $link->tag_slug) {
            $alive = true;
            if ($link->expires_at && strtotime($link->expires_at) < time()) $alive = false;
            if ($link->max_uses && $link->used_count >= $link->max_uses) $alive = false;
            if ($alive) {
                xingxy_add_segment($user_id, $link->tag_slug);
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}xingxy_share_links SET used_count = used_count + 1 WHERE id = %d",
                    $link->id
                ));
                update_user_meta($user_id, '_source_sk', $sk);
                $tagged = true;
            }
        }
        // 无论是否打标成功，分享者都作为推荐人
    }

    // ② 口令 _gate（延后启用，仅当 _sk 未打标时）
    if (!$tagged) {
        $gate = sanitize_text_field($_POST['_gate'] ?? $_COOKIE['_xgate'] ?? '');
        if ($gate) {
            update_user_meta($user_id, '_source_gate', $gate);
            $mapping = xingxy_pz('gate_segment_mapping', []);
            $segment = $mapping[$gate] ?? 'general';
            xingxy_add_segment($user_id, $segment);
            $tagged = true;
        }
    }

    // ③ 推荐链继承（由 §3.1.3 处理，此处不重复）

    // ④ 兜底
    if (!$tagged) {
        xingxy_add_segment($user_id, 'general');
    }

    // 清除追踪 Cookie
    setcookie('_xsk',   '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false);
    setcookie('_xgate', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
}, 5);

// ========================================
// 辅助函数
// ========================================
function xingxy_add_segment($user_id, $slug) {
    $segments = get_user_meta($user_id, '_xingxy_segments', true) ?: ['general'];
    if (!in_array($slug, $segments)) {
        $segments[] = $slug;
    }
    update_user_meta($user_id, '_xingxy_segments', $segments);
}
```

> **注意**：`_xsk` Cookie 的 `httpOnly` 设为 `false`，因为第二层 JS 需要读取。`_xgate` 保持 `httpOnly=true`。

#### 3.1.3 推荐链继承（树型传播）

普通用户分享时，链接仅携带推荐码 `_s`，无标签配置权限。新用户继承推荐人的标签：

```
注册钩子 (user_register) 中：
1. _sk 或 _gate 已处理? → 跳过
2. 有 _xref Cookie（推荐人推广码）?
   → 读取推荐人的 _xingxy_segments
   → 新用户继承推荐人的 segment（树型传播）
3. 都没有? → 分配 "general"
```

这形成了一个**树型传播结构**：管理员（根节点）通过分享链接/口令打标给首批用户（第一层）。当这批用户使用自己的推荐链接继续分享时，下级用户（第二层及以下）自动继承相同标签。

#### 3.1.4 商品购买触发打标

```
用户购买商品 → Zibll 支付成功钩子 → 检查商品 meta 中是否有 segment 配置
→ 有则追加到用户的 _xingxy_segments
```

#### 3.1.5 TG 侧触发打标

```
用户在精灵中执行命令/输入口令 → 精灵调用星小芽 REST API → 追加 segment
```

需要星小芽侧暴露一个受保护的 REST 端点供精灵调用。

#### 3.1.6 管理员手动打标与后台管理

- 在星小芽后台用户列表中，增加 segment 标签编辑列。
- 在 Nebuluxe Center 中，提供统一的群体概览和批量打标功能。

### 3.2 第二层：内容层（论坛千人千面）

#### 3.2.1 核心机制：两层可见性模型（微信标签模式）

采用类似于微信朋友圈标签可见性的**两层模型（板块级继承 + 帖子级覆盖）**。

1. **板块级（Board-level）标签**：
   - 论坛的每个板块可以设置一个默认的可见群体数组，如 `_xingxy_board_segments = ["biz_a", "biz_b"]`。
   - 所有发布在该板块内的帖子，**默认继承**该板块的可见性规则。
   - **非匹配用户的体验**：如果用户没有对应标签（例如只有 `biz_c`），他可以**看到板块的名称、封面和描述，但看不到里面的任何帖子**。系统会展示一句友好的引导文案（例如："该星域需要特定权限，获取权限请..."），而不是生硬的报错。这兼顾了内容隐私和营销橱窗的作用。

2. **帖子级（Post-level）标签**：
   - 每篇帖子在发布时，可以单独设置可见群体。
   - **优先级**：帖子级别的设置**覆盖**板块级别的设置。
   - 这使得管理员可以在一个私密板块中发一篇公开的引导帖，或者在一个公开板块中发一篇仅特定群体可见的核心资料。

```
数据结构示例：
板块 A meta: _xingxy_board_segments = ["biz_a"]
帖子 1 (在板块A, 未设限制) → 继承为 ["biz_a"]可见
帖子 2 (在板块A, 设置为 ["biz_b"]) → 仅 ["biz_b"]可见 (覆盖)

用户 user_meta: _xingxy_segments = ["general", "biz_a"] 
结果：用户可以看到帖子1，看不到帖子2。
```

#### 3.2.2 实现方式

**方案：WordPress `pre_get_posts` 过滤器**

在主查询中注入 meta_query，基于用户的 `_xingxy_segments` 过滤掉他无权限看到的帖子：

```php
// 伪代码示意
add_action('pre_get_posts', function($query) {
    if (is_admin() || !$query->is_main_query()) return;
    
    $user_segments = get_user_meta($user_id, '_xingxy_segments', true) ?: ['general'];
    
    // 查询逻辑：
    // (帖子本身的 segment 在用户 segment 列表中) 
    // OR 
    // (帖子没有设置 segment AND 其所在分类的 segment 在用户 segment 列表中)
    // OR
    // (帖子和分类都没设置 segment，即完全公开)
    
    // 注意：实际实现可能需要 JOIN terms 表或在 save_post 时将分类的 segment
    // 同步写入帖子的隐藏 meta 中以优化查询性能。
});
```

**用户感知**：未授权的帖子在列表中是**物理隐藏**的，不会显示占位符或锁图标。用户的体验是：他看到的整个社区，就是专为他这个群体定制的。

#### 3.2.3 管理员发帖界面改造

- 在发帖/编辑帖子侧边栏，增加【可见性设置】面板。
- 选项包含：【继承板块设置 (默认)】、【指定标签可见】。
- 选择【指定标签可见】时，弹出所有的 Segment 供多选。

### 3.3 第三层：交付层（TG 侧千人千面）

#### 3.3.1 TG 频道结构

```
主频道 [0]          ← 所有人可入，防失联/导航/通知
├── 子频道 [C]      ← segment: biz_a 才能入
├── 子频道 [D]      ← segment: biz_b 才能入
└── 子频道 [E]      ← segment: biz_a + biz_b 才能入（交叉内容）
```

#### 3.3.2 准入控制流程

```
用户请求加入子频道 C
        │
        ▼
   小芽精灵检查
        │
   ┌────┴────┐
   │         │
已绑定?   未绑定?
   │         │
   ▼         ▼
查询 WP     引导 /bind
usermeta     绑定后再试
   │
   ▼
_xingxy_segments 包含 "biz_a"?
   │
   ┌────┴────┐
   │         │
   ✅        ❌
   │         │
   ▼         ▼
生成邀请    "此频道需要
链接并发送   biz_a 权限"
```

#### 3.3.3 主频道的特殊性

主频道 [0] 是公开的，所有进入 TG 侧的用户都会关注。但主频道**不能反向链接子频道**，因为：
- 子频道对不同群体不同，主频道无法展示"你该进哪个"
- 暴露子频道链接会绕过精灵的准入控制

所有从主频道到子频道的路径都必须经过精灵。

#### 3.3.4 空投机资源包与 segment 的关系

空投机的 tag 系统已经存在。segment 与 tag 的映射关系：

```
空投机 resource_packs.tags ← 与 WP 侧的 segment slug 使用相同命名体系

示例：
  资源包 "A业务精品合集"  → tags: ["biz_a"]
  资源包 "通用教程"        → tags: ["general"]

用户请求资源包时：
  精灵/空投机 → 查用户 segments → 比对资源包 tags → 匹配则放行
```

现有空投机 tag 字段的语义从"分类标记"提升为"访问控制标签"，无需改表结构。

---

## 四、全局生态数据流

### 4.1 用户完整旅程（已更新 2026-04-21）

```
  四面八方的流量
        │
        ├─── 【主力】管理员在各平台发分享链接
        │    X/推特、QQ群、YouTube、小红书…
        │    链接携带 ?_sk=Xf9kQ2（per-link 标签配置）
        │            │
        │            ▼
        │    用户点击 → 直达星小芽内容页
        │
        ├─── 【补充】不方便发链接时，发纯文字口令
        │    抖音评论区、微信群纯文字…
        │    "在 1yo.cc 输入口令 shop"
        │            │
        │            ▼
        │    1yo.cc → grantAccess() → ?_gate=shop → 着陆星小芽
        │
        └─── 【裂变】普通用户分享推荐链接
             链接携带 ?_s=ref_code（仅推荐关系，标签继承）
                     │
                     ▼
                ┌────────────────────────┐
                │       星小芽            │
                │  三层追踪（§3.1.1/2）    │
                │  Cookie + JS + 隐藏字段  │
                │  注册 → 打标 + 推荐关系  │
                └────────┬───────────────┘
                         │
           ┌─────────────┼─────────────┐
           ▼             ▼             ▼
     论坛 [A可见]    论坛 [公共]    论坛 [B可见]
     A的索引帖       公开讨论区     B的索引帖
     预览+引导       所有人可见     预览+引导
           │                           │
           ▼                           ▼
    "完整资源前往TG"            "完整资源前往TG"
           │                           │
           └──────────┬────────────────┘
                      ▼
               ┌──────────────┐
               │   小芽精灵     │
               │  校验 segment  │
               └──────┬───────┘
                      │
        ┌─────────────┼─────────────┐
        ▼             ▼             ▼
   子频道 C       主频道 [0]     子频道 D
   [biz_a]       (所有人)       [biz_b]
        │                           │
        ▼                           ▼
   空投机资源包                 空投机资源包
   [tag: biz_a]               [tag: biz_b]
```

### 4.2 标签写入与读取的数据流（已更新 2026-04-21）

```
写入路径（五条独立的写入触发器）：

① 分享链接 ─→ ?_sk=xxx → Cookie/_POST → 查 xingxy_share_links 表
           ─→ WP user_register hook（优先级5）
           ─→ wp_usermeta._source_sk（归因到具体链接）
           ─→ wp_usermeta._xingxy_segments（标签）
           ─→ xingxy_share_links.used_count++（消耗计数）
② 口令透传 ─→ 1yo.cc ?_gate=xxx → Cookie/_POST
           ─→ WP user_register hook（优先级5）
           ─→ wp_usermeta._source_gate（归因到口令）
           ─→ wp_usermeta._xingxy_segments（映射后的 segment）
③ 商品购买 ─→ Zibll 支付成功 hook   ─→ wp_usermeta._xingxy_segments
④ TG 触发  ─→ 精灵 → WP REST API   ─→ wp_usermeta._xingxy_segments
⑤ 手动打标 ─→ WP 后台 / Center      ─→ wp_usermeta._xingxy_segments

读取路径（三个消费方）：

wp_usermeta._xingxy_segments
    │
    ├─→ 论坛查询: pre_get_posts meta_query（实时，每次页面加载）
    ├─→ 精灵校验: 绑定后读 usermeta（按需，加入频道时）
    └─→ Center: Gateway 从 /userinfo 获取（按需，管理后台查看时）

归因分析额外数据：
wp_usermeta._source_sk   → 精确到具体分享链接（谁分享的、什么时候、哪个内容）
wp_usermeta._source_gate → 精确到口令（哪个平台/活动）
xingxy_share_links       → 链接维度统计（点击数、转化数、生命周期消耗）
```

---

## 五、现有基础设施盘点

### 5.1 已就绪的拼图

| 能力 | 承载系统 | 状态 | 说明 |
|------|----------|------|------|
| 用户身份统一 | 星小芽 WordPress | ✅ 已有 | user_id 是全局唯一身份 |
| WP ↔ TG 身份打通 | 小芽精灵绑定 | ✅ 已有 | `_xingxy_telegram_uid` |
| user_meta 存储体系 | WordPress usermeta | ✅ 已有 | 画像、积分标记等已在用 |
| TG 资源分发 | 空投机 + tag 系统 | ✅ 已有 | tag 字段可直接复用为 segment |
| 管理后台 | Nebuluxe Center | ✅ 已有 | Gateway + Vben 已搭建 |
| 用户画像标签 | xingxy_profile_data | ✅ 已有 | 性别/年龄/兴趣标签 |
| OAuth 认证链路 | zibll-oauth 插件 | ✅ 已有 | Center + 精灵双应用 |
| 精灵内部 API | tgbot-verify /api/ | ✅ 已有 | check-bind 等端点 |
| 空投机内部 API | File-Sharing-Bot /internal_api/ | ✅ 已有 | 资源包 CRUD 端点 |

### 5.2 需要新建的缺口

| 缺口 | 涉及系统 | 复杂度 | 说明 |
|------|----------|--------|------|
| **`xingxy_share_links` 表** | 星小芽 MySQL | 低 | 分享链接配置存储，建表即可 |
| **管理员增强分享面板** | 星小芽 PHP/JS | 中 | 增强 Zibll 分享按钮，管理员可配置标签+生命周期 |
| **三层追踪（`_sk` + `_gate`）** | 星小芽 PHP/JS | 低 | gate-tracker.php，同时处理分享链接和口令 |
| **注册时自动打标（优先级链）** | 星小芽 PHP hook | 低 | user_register 钩子，_sk > _gate > _s > general |
| **segment 定义与配置面板** | 星小芽 CSF / Center | 低 | 后台定义有哪些 segment、名称、颜色 |
| **口令→segment 映射配置** | 星小芽 CSF | 低 | gate_segment_mapping 配置表 |
| **帖子 segment 可见性设置** | 星小芽发帖 UI | 中 | 帖子编辑界面加 segment 选择器 |
| **pre_get_posts 过滤** | 星小芽 PHP | 中 | 主查询注入 meta_query |
| **精灵频道准入校验** | tgbot-verify | 中 | 加入频道前查 segment |
| **精灵追加标签 API** | tgbot-verify + WP REST | 中 | 精灵调 WP 写 segment |
| **Center segment 管理页** | Vben + Gateway | 低-中 | 查看/批量打标 |

### 5.3 画像系统与 segment 的关系

画像系统（`xingxy_profile_data`）和 segment 是**互补**而非替代关系：

```
画像 (profile)：描述用户"是什么样的人"（性别、年龄、兴趣偏好）
    → 用途：TG Bot 推送定向、运营分析、千人千面推荐排序

Segment：描述用户"属于哪个业务群体"（来源、权限、可见范围）
    → 用途：内容可见性控制、频道准入控制、资源包访问控制
```

两者可以交叉使用：例如，用 segment 控制"能不能看到"，用画像控制"看到后的排序/推荐优先级"。

---

## 六、与现有 VIP 体系的共存策略

Zibll 自带的 VIP/等级体系不需要废弃，而是**重新定位**：

```
              segment 标签                   VIP 等级
              ━━━━━━━━━━                    ━━━━━━━━
作用          控制"能看到什么类型"            控制"能享受什么增值服务"
门控对象       帖子/频道/资源包可见性          去广告、优先下载、专属徽章
获取方式       自动（来源）/ 触发（购买）       付费/积分/时间
心理感受       "这是为我准备的"               "我是VIP，体验更好"
```

两者正交，互不干扰。segment 解决"看什么"，VIP 解决"体验好不好"。

---

## 七、安全与边界约束

### 7.1 segment 不能被用户自行修改

- `_xingxy_segments` 仅通过后端写入（注册钩子、支付钩子、REST API）
- 前端不暴露任何修改 segment 的接口
- **分享链接 `_sk`**：标签配置存储在服务端 `xingxy_share_links` 表，URL 中仅有随机 key，用户无法伪造或篡改标签。伪造 `_sk` 值查表为空，降级为 `general`
- **口令 `_gate`**：口令必须存在于后台 CSF 映射表中才会生效，无效口令退化为 `general`
- `_xsk` / `_xgate` Cookie 仅用于注册时一次性读取，注册后立即清除

### 7.2 主频道不反向链接子频道

- 主频道内容不包含任何子频道链接
- 所有进入子频道的路径必须经过精灵校验
- 精灵发送的邀请链接可设为一次性（Telegram invite link revoke after use）

### 7.3 论坛帖子 segment 过滤不影响 SEO

- 公开文章（`visible_segments` 为空或 `["general"]`）正常被搜索引擎收录
- 受 segment 限制的帖子对未登录用户不可见，不影响 SEO 收录的公开内容

### 7.4 零侵入原则

- 不修改 Zibll 父主题任何文件
- 所有逻辑通过 Panda 子主题 + xingxy 模块实现
- 与现有邮箱绑定、画像采集、积分体系完全兼容

---

## 八、开发路线图（建议分期）

### Phase 0：分享链接打标 + 基础分群（最小可用，方案已锁定 2026-04-21）

**目标**：跑通"管理员分享内容链接 → 用户点击注册自动打标 → 论坛看到不同内容"的最小闭环

| 序号 | 任务 | 文件/系统 | 状态 |
|------|------|-----------|------|
| 0.1 | 建 `xingxy_share_links` 表 | xingxy/inc/share-links.php（新建） | 待开发 |
| 0.2 | 管理员增强分享面板（标签+时间+次数设置） | xingxy/inc/share-links.php + JS | 待开发 |
| 0.3 | 三层追踪（`_sk` 通道，`_gate` 预留） | xingxy/inc/gate-tracker.php（新建） | 待开发 |
| 0.4 | 注册时打标（优先级链：_sk > _gate > _s > general） | xingxy/inc/gate-tracker.php | 待开发 |
| 0.5 | 帖子 meta box：设置可见 segment | xingxy/inc/segment.php（新建） | 待开发 |
| 0.6 | pre_get_posts 过滤：按用户 segment 过滤帖子 | xingxy/inc/segment.php | 待开发 |
| 0.7 | 后台用户列表：显示 segment 标签列 | xingxy/inc/segment.php | 待开发 |
| — | *以下延后，不阻塞主流程* | | |
| 0.8 | 1yo.cc `grantAccess()` 透传 `_gate` 参数 | HomePage/src/js/main.js | 延后 |
| 0.9 | CSF 面板：口令 → segment 映射配置表 | xingxy/inc/options.php | 延后 |

**交付物**：管理员在任意内容页点击分享 → 配置标签+生命周期 → 生成链接 → 用户点击注册自动打标 → 论坛千人千面。

### Phase 1：TG 侧联动

**目标**：精灵读取 segment，控制子频道准入

| 序号 | 任务 | 文件/系统 |
|------|------|-----------|
| 1.1 | 精灵绑定时同步缓存用户 segment | tgbot-verify |
| 1.2 | 精灵新增 /join 命令：校验 segment → 发邀请链接 | tgbot-verify |
| 1.3 | segment ↔ TG channel ID 映射配置 | tgbot-verify config |
| 1.4 | 空投机：请求资源包时校验用户 segment 与包 tag | File-Sharing-Bot |

**交付物**：A 群体只能进 C 频道、获取 A 标签的资源包。

### Phase 2：触发式打标扩展

**目标**：补全非注册来源的标签分配途径

| 序号 | 任务 | 文件/系统 |
|------|------|-----------|
| 2.1 | 商品购买后自动追加 segment（支付成功钩子） | xingxy/inc/segment.php |
| 2.2 | 精灵侧口令触发追加 segment（调 WP REST API） | tgbot-verify + WP REST |
| 2.3 | Center 批量打标页面 | Vben + Gateway |
| 2.4 | 标签变更日志（审计用） | xingxy/inc/segment.php |

**交付物**：多种途径获取标签，管理员可批量操作。

### Phase 3：运营精细化

**目标**：数据分析 + 推荐优化

| 序号 | 任务 | 文件/系统 |
|------|------|-----------|
| 3.1 | segment 仪表盘（各标签用户数、活跃度） | Center |
| 3.2 | 画像 × segment 交叉分析 | Center |
| 3.3 | 论坛内容推荐排序（基于画像优先级） | xingxy PHP |
| 3.4 | TG Bot 推送定向（基于 segment + 画像） | 精灵/空投机 |

---

## 九、关键决策点备忘

以下决策需要在开发前确认，本文档暂不锁定：

| # | 决策点 | 选项 | 状态 |
|---|--------|------|------|
| D1 | 目前实际有几个 segment？ | 取决于业务方向数量 | 待定义 |
| D2 | 帖子不可见时是“完全消失”还是“显示锁定标题”？ | 消失 = 无感知；锁定 = 有引导 | 看业务需要 |
| D3 | 用户是否可以看到自己拥有哪些 segment？ | 透明（个人中心显示） vs 隐式（用户无感知） | 待确认 |
| D4 | segment 是否有过期机制？ | 永久 vs 有期限 | 建议先做永久，后期按需加过期 |
| ~~D5~~ | ~~参数格式约定~~ | ~~`?ref=biz_a` vs `?_gate={code}`~~ | **✅ 已锁定（v3 升级）**：双通道——分享链接用 `?_sk={share_key}`（服务端存储，per-link 配置），口令用 `?_gate={code}`（CSF 全局映射） |
| ~~D6~~ | ~~分享链接生命周期~~ | ~~仅时间 vs 仅次数 vs 双限制~~ | **✅ 已锁定**：时间+次数双限制，任一到达即停止打标，链接仍可访问内容 |

---

## 十、总结

> **一句话概括**：用"标签"替代"等级"，把生态从"爬梯子"变成"走对门"。
> 
> 用户从哪来，就自动拥有对应的内容权限——论坛只展示他该看的帖子，TG 只让他进他该进的频道，空投机只给他他该拿的资源。没有挡板，没有挫败感，水流自然。

技术上，这套架构建立在已有的 WordPress usermeta + 精灵绑定 + 空投机 tag 系统之上，核心新增量是 `_xingxy_segments` meta 字段 + `xingxy_share_links` 自定义表，围绕它们的五条写入路径、三个读取消费方。

> **2026-03-24 更新**：Phase 0 的核心机制"口令透传三层追踪"已定稿。
>
> **2026-04-21 更新**：架构升级为 v3——分享链接为主力打标载体（`?_sk`），口令为文字场景补充（`?_gate`）。管理员在全站任意内容的分享按钮中配置标签+生命周期（时间+次数双限制），生成的链接即标签载体。一条链接承载两个功能：管理员的链接同时打标+建立推荐关系，普通用户的链接仅建立推荐关系。新增 `xingxy_share_links` 表存储 per-link 配置，`gate-tracker.php` 统一处理 `_sk`/`_gate` 双通道三层追踪。
