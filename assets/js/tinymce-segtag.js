/**
 * TinyMCE 插件：在 zib_hide 隐藏内容下拉菜单中追加「标签用户可查看」
 * 从 mce.segtag_presets 读取预设列表，每个预设一个子菜单项
 * 插入 [hidecontent type="segtag" preset="N"] shortcode
 */
(function ($) {
    if (typeof tinymce === 'undefined') return;

    function insertSegtagContent(editor, presetIdx, presetName) {
        // 帖子级标签限制已启用时，内容级标签限制无意义（整篇已限制）
        var postPresetVal = $('input[name="xingxy_post_seg_preset"]:checked').val();
        if (postPresetVal && postPresetVal !== '') {
            alert('帖子已设置帖子级标签限制（整篇限制），无需再设置内容级标签限制。\n如需使用内容级限制作为钩子，请先取消帖子级标签限制。');
            return;
        }

        var getNode = editor.selection.getNode();
        var val = $.trim(editor.selection.getContent()) || '';
        var suffix = '<p></p>';
        val = '<p>' + val + '</p>';

        if (getNode.className === 'tinymce-hide') {
            suffix = '';
            val = $(getNode).find('[contenteditable="true"]').html();
        } else if (getNode.nodeName === 'P') {
            var outerHTML = $.trim(getNode.outerHTML);
            if (outerHTML) {
                editor.dom.remove(getNode);
                val = outerHTML;
            }
        }

        editor.insertContent(
            '<div class="tinymce-hide" contenteditable="false">' +
            '<p class="hide-before">[hidecontent type="segtag" preset="' + presetIdx + '" desc="隐藏内容：' + presetName + '可查看"]</p>' +
            '<div contenteditable="true">' + val + '</div>' +
            '<p class="hide-after">[/hidecontent]</p>' +
            '</div>' + suffix
        );
    }

    tinymce.PluginManager.add('xingxy_segtag', function (editor) {
        editor.on('BeforeRenderUI', function () {
            var btn = editor.buttons['zib_hide'];
            if (!btn || !btn.menu) return;
            if (typeof mce === 'undefined' || !mce.hide_segtag || !mce.segtag_presets) return;

            var presets = mce.segtag_presets;
            if (!presets.length) return;

            if (presets.length === 1) {
                // 仅一个预设 → 直接作为菜单项
                var p = presets[0];
                btn.menu.push({
                    text: p.icon + ' ' + p.name + ' 可查看',
                    onclick: (function (preset) {
                        return function () { insertSegtagContent(editor, preset.idx, preset.name); };
                    })(p),
                });
            } else {
                // 多个预设 → 子菜单
                var submenu = [];
                for (var i = 0; i < presets.length; i++) {
                    (function (preset) {
                        submenu.push({
                            text: preset.icon + ' ' + preset.name,
                            onclick: function () { insertSegtagContent(editor, preset.idx, preset.name); },
                        });
                    })(presets[i]);
                }
                btn.menu.push({
                    text: '标签用户可查看',
                    menu: submenu,
                });
            }
        });
    });
})(jQuery);
