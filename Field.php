<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Config;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 单篇内容的自定义字段
 *
 * 自定义字段由主题、插件和用户共用同一个命名空间，所以都带 copyright 前缀。
 * 字段留空表示套用全局设置，填 0 表示不显示这一项。
 */
final class Field
{
    public const DISPLAY = 'copyrightDisplay';
    public const SOURCE_URL = 'copyrightSourceUrl';
    public const AUTHOR = 'copyrightAuthor';
    public const LICENSE = 'copyrightLicense';
    public const NOTICE = 'copyrightNotice';

    /** 发布时从全局设置写入了哪些值，JSON 对象，由插件维护，编辑页不显示 */
    public const SEALED = 'copyrightSealed';

    /** 只用于 copyrightDisplay */
    public const ON = '1';

    public const OFF = '0';

    /** 1.x 的字段名，许可协议是 2.0 新增的，没有旧字段 */
    public const LEGACY_NAMES = [
        self::DISPLAY    => 'switch',
        self::SOURCE_URL => 'url',
        self::AUTHOR     => 'author',
        self::NOTICE     => 'notice',
    ];

    /**
     * 读取某篇内容的字段，新字段留空时可以退回 1.x 的字段
     */
    public static function read(Config $fields, string $name, bool $withLegacy): string
    {
        $value = trim((string) ($fields[$name] ?? ''));

        if ($value === '' && $withLegacy && isset(self::LEGACY_NAMES[$name])) {
            $value = trim((string) ($fields[self::LEGACY_NAMES[$name]] ?? ''));
        }

        return $value;
    }

    /**
     * 字段留空时套用全局设置，填 0 时不显示
     */
    public static function override(string $value, string $global): string
    {
        if ($value === '') {
            return $global;
        }

        return $value === self::OFF ? '' : $value;
    }
}
