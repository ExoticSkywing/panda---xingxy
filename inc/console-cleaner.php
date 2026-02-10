<?php
/**
 * Xingxy Console Cleaner
 * 移除 Zibll 和 Panda 主题在控制台的版权推广日志
 */

add_action('wp_head', function() {
    // 仅在前台且非管理员调试模式下运行（可选，这里默认全开）
    ?>
    <script id="xingxy-console-cleaner">
    (function(){
        try {
            const _log = console.log;
            const _info = console.info;
            
            // 关键词黑名单 (匹配即拦截)
            const blockList = [
                'Zibll Theme', 
                'zibll.com', 
                'panda Theme', 
                'www.scbkw.com',
                'Panda PRO' 
            ];

            function shouldBlock(args) {
                if (!args || args.length === 0) return false;
                // 检查第一个参数（通常是格式化字符串或主要信息）
                const msg = String(args[0]);
                for (let i = 0; i < blockList.length; i++) {
                    if (msg.includes(blockList[i])) return true;
                }
                return false;
            }

            // 代理劫持 console.log
            console.log = function(...args) {
                if (!shouldBlock(args)) {
                    _log.apply(console, args);
                }
            };

            // 代理劫持 console.info (部分主题使用 info 输出)
            console.info = function(...args) {
                if (!shouldBlock(args)) {
                    _info.apply(console, args);
                }
            };
        } catch(e) {
            // 发生意外则不做任何事，防止破坏控制台
        }
    })();
    </script>
    <?php
}, 0); // 优先级设为 0，确保在所有其他 JS 之前执行
