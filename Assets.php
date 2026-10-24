<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Common;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 设置页和编辑页的样式与脚本
 */
final class Assets
{
    public static function tags(): string
    {
        return '<link rel="stylesheet" href="' . Html::escape(self::url('admin.css')) . '" />'
            . '<script type="module" src="' . Html::escape(self::url('license-chooser.js')) . '"></script>'
            . '<script type="module" src="' . Html::escape(self::url('admin.js')) . '"></script>';
    }

    /**
     * 附带内容摘要，文件更新后浏览器不会用到旧缓存
     */
    private static function url(string $file): string
    {
        $path = __DIR__ . '/assets/' . $file;

        return Common::url(Plugin::NAME . '/assets/' . $file, Options::alloc()->pluginUrl)
            . '?v=' . (string) hash_file('crc32b', $path);
    }
}
