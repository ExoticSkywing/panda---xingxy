# Panda func.php 修改补丁

## 修改位置
文件：`/wp-content/themes/panda/func.php`

## 修改内容
在 `foreach ($require_once ...)` 循环之后添加：

```php
    // 加载 Xingxy 自定义功能模块
    $xingxy_init = get_theme_file_path('/xingxy/init.php');
    if (file_exists($xingxy_init)) {
        require_once $xingxy_init;
    }
```

## 完整上下文

```php
} else{
    // 对接子主题核心文件
    require_once get_theme_file_path('/panda/functions.php');
    
    $require_once = array(
        'others-func.php',
    );
    
    foreach ($require_once as $require) {
        require_once get_theme_file_path('/' . $require);
    }
    
    // 加载 Xingxy 自定义功能模块  <-- 添加这段
    $xingxy_init = get_theme_file_path('/xingxy/init.php');
    if (file_exists($xingxy_init)) {
        require_once $xingxy_init;
    }
}
```
