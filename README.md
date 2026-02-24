# 星小雅高级定制

基于 Panda 子主题架构的自定义功能模块。

## 安装方法

1. 将此目录放置到 `/wp-content/themes/panda/xingxy/`
2. 在 `panda/func.php` 中添加加载代码：
```php
// 加载 Xingxy 自定义功能模块
$xingxy_init = get_theme_file_path('/xingxy/init.php');
if (file_exists($xingxy_init)) {
    require_once $xingxy_init;
}
```

## 目录结构

```
xingxy/
├── init.php                    # 模块入口
├── inc/
│   ├── options.php             # 配置面板（后台可视化）
│   ├── referral.php            # 邀请注册送积分
│   ├── referral-tracker.php    # 推广链接伪装追踪 & 商城返佣修复
│   ├── shop-coupon.php         # 商城优惠码集成
│   ├── product-capability.php  # 商品发布权限控制
│   ├── action-newproduct.php   # 商品发布 AJAX 处理
│   ├── action-cardpass.php     # 卡密导入/列表/编辑/删除 AJAX 处理
│   ├── shipping-guard.php      # 卡密发货守护（库存校验/部分发货/自动补发）
│   ├── user-products.php       # 用户中心商品管理
│   ├── assets.php              # 前端资源加载
│   ├── console-cleaner.php     # 控制台净化
│   └── discount.php            # 数量限制功能
├── assets/                     # 前端资源（JS/CSS）
├── custom-design/              # 自定义设计
├── pages/                      # 页面模板
├── patches/                    # 补丁文档
└── scripts/                    # 恢复脚本
```

## 功能列表

- [x] 配置面板（星小雅高级定制）
- [x] 数量限制功能
- [x] 阶梯优惠互斥
- [x] 邀请注册送积分
- [x] 推广链接伪装追踪（数据库映射方案）
- [x] 商城返佣修复（Zibll 参数继承 Bug）
- [x] 商城创作分成（补充 income_price 计算）
- [x] 商品管理优化（完整列表、编辑器工具栏、暗色模式修复）
- [x] 商城优惠码集成
- [x] 邮件通知修复
- [x] 虚拟商品发货邮件控制
- [x] 卡密编辑功能
- [x] Shop VIP 引导功能
- [x] 控制台净化
- [x] 星盟创作分成 & 前台商品发布
- [x] 星盟卡密导入与发货设置
- [x] 前台卡密管理（列表/编辑/删除）与发货区 UI 优化
- [x] 卡密发货守护（库存校验 / 部分发货 / 自动补发 / 弹窗修复 / 一键复制）

## 主题更新后恢复

参考 `patches/README.md` 恢复主题文件修改。

