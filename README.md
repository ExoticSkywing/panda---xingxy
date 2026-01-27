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
├── init.php           # 模块入口
├── inc/
│   ├── options.php    # 配置面板（后台可视化）
│   └── discount.php   # 功能说明文档
├── patches/           # 补丁文档
├── scripts/           # 恢复脚本
└── assets/            # 资源文件（备用）
```

## 功能列表

- [x] 配置面板（星小雅高级定制）
- [x] 数量限制功能（需修改主题文件）

## 主题更新后恢复

参考 `patches/README.md` 恢复主题文件修改。
