<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Config;
use Typecho\Db;
use Widget\Base\Contents;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 把版权信息写进内容
 *
 * 作者、许可协议和声明是内容自身的属性，应当跟随内容，而不是插件的设置。全局设置只是新内容的默认值：
 * 发布时写进这篇内容留空的字段，之后修改或丢失全局设置，都不会改变已发布内容的版权信息。
 *
 * 写入的值同时记在 copyrightSealed 字段里。字段的值仍与记录相同，说明他来自全局设置且没有被手动修改过，
 * 全局设置修改后，可以由用户确认是否更新为新的设置；手动修改过的值不再记录，也不会被更新。
 *
 * 显示位置、本文链接、输出方式和模板是站点的呈现方式，仍然只看全局设置。
 */
final class Seal
{
    /** 写进内容的字段 */
    public const FIELDS = [Field::AUTHOR, Field::LICENSE, Field::NOTICE];

    /**
     * 同一请求内查询过的全部内容
     *
     * @var list<array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>}>|null
     */
    private static ?array $contents = null;

    /**
     * finishPublish：发布文章或独立页面后，把全局设置写进留空的字段
     *
     * @param array<string, mixed> $contents
     */
    public static function onPublish(array $contents, Contents $widget): void
    {
        foreach (self::contents((int) $widget->cid) as $content) {
            self::sealContent($content, Settings::current());
        }
    }

    /**
     * 还没有写入版权信息的文章和独立页面
     *
     * @return list<array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>}>
     */
    public static function pending(Settings $settings): array
    {
        return array_values(array_filter(
            self::contents(),
            static fn(array $content): bool => self::missing($content, $settings) !== []
        ));
    }

    /**
     * 为选中的、还没有写入版权信息的文章和独立页面写入，返回写入的篇数
     *
     * @param list<int> $cids
     */
    public static function seal(array $cids): int
    {
        $settings = Settings::current();
        $sealed = 0;

        foreach (self::contents() as $content) {
            if (in_array($content['cid'], $cids, true) && self::sealContent($content, $settings)) {
                $sealed++;
            }
        }

        return $sealed;
    }

    /**
     * 写入时的全局设置已经改变的值，按字段分组
     *
     * @return array<string, list<array{cid: int, type: string, title: string, old: string, new: string}>>
     */
    public static function outdated(Settings $settings): array
    {
        $outdated = [];

        foreach (self::contents() as $content) {
            foreach (self::untouched($content) as $name => $old) {
                $new = self::siteValue($name, $content, $settings);
                if ($new !== $old) {
                    $outdated[$name][] = [
                        'cid'   => $content['cid'],
                        'type'  => $content['type'],
                        'title' => $content['title'],
                        'old'   => $old,
                        'new'   => $new,
                    ];
                }
            }
        }

        return $outdated;
    }

    /**
     * 处理全局设置已经改变的值：更新为当前设置，或保持原样并不再提示，返回处理的篇数
     *
     * @param array<string, list<int>> $selected 要处理的字段及其中选中的内容
     */
    public static function resolveOutdated(array $selected, bool $isUpdate): int
    {
        $settings = Settings::current();
        $resolved = 0;

        foreach (self::contents() as $content) {
            $record = self::untouched($content);
            $changes = [];

            foreach (array_intersect_key($record, $selected) as $name => $old) {
                $new = self::siteValue($name, $content, $settings);
                if ($new === $old || !in_array($content['cid'], $selected[$name], true)) {
                    continue;
                }

                if ($isUpdate) {
                    $changes[$name] = $new;
                    $record[$name] = $new;
                } else {
                    unset($record[$name]);
                }
            }

            if ($changes !== [] || $record !== self::untouched($content)) {
                self::write($content['cid'], $changes, $record);
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * 把全局设置写进一篇内容留空的字段，返回是否写入了字段
     *
     * @param array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>} $content
     */
    private static function sealContent(array $content, Settings $settings): bool
    {
        $record = self::untouched($content);
        $changes = [];

        if (self::isRepost($content, $settings)) {
            // 转载的版权属于原作者，之前从全局设置写入、没有修改过的值要撤下
            $changes = array_fill_keys(array_keys($record), '');
            $record = [];
        } else {
            foreach (self::missing($content, $settings) as $name) {
                $changes[$name] = $record[$name] = self::siteValue($name, $content, $settings);
            }
        }

        $isRecordChanged = $record !== self::record($content);
        if ($changes === [] && !$isRecordChanged) {
            return false;
        }

        self::write($content['cid'], $changes, $record);

        return $changes !== [];
    }

    /**
     * 写入字段和记录；值为空字符串时删除字段
     *
     * @param array<string, string> $changes
     * @param array<string, string> $record
     */
    private static function write(int $cid, array $changes, array $record): void
    {
        self::$contents = null;
        $changes[Field::SEALED] = $record !== [] ? (string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        $db = Db::get();
        foreach ($changes as $name => $value) {
            // 编辑页提交的空字段也会存成一行，先删掉再写
            $db->query($db->delete('table.fields')->where('cid = ? AND name = ?', $cid, $name));
            if ($value === '') {
                continue;
            }

            $db->query($db->insert('table.fields')->rows([
                'cid'         => $cid,
                'name'        => $name,
                'type'        => 'str',
                'str_value'   => $value,
                'int_value'   => 0,
                'float_value' => 0,
            ]));
        }
    }

    /**
     * 还留空的字段；转载内容的作者、协议和声明属于原作者，不写入全局设置
     *
     * @param array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>} $content
     * @return list<string>
     */
    private static function missing(array $content, Settings $settings): array
    {
        if (self::isRepost($content, $settings)) {
            return [];
        }

        $fields = new Config($content['fields']);

        return array_values(array_filter(
            self::FIELDS,
            static fn(string $name): bool => Field::read($fields, $name, $settings->readLegacyFields) === ''
        ));
    }

    /**
     * @param array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>} $content
     */
    private static function isRepost(array $content, Settings $settings): bool
    {
        $sourceUrl = Field::read(new Config($content['fields']), Field::SOURCE_URL, $settings->readLegacyFields);

        return $sourceUrl !== '' && $sourceUrl !== Field::OFF;
    }

    /**
     * 全局设置对这篇内容的值；没有设置的项写入 0，表示不显示
     *
     * @param array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>} $content
     */
    private static function siteValue(string $name, array $content, Settings $settings): string
    {
        switch ($name) {
            case Field::AUTHOR:
                $value = $settings->author !== '' ? $settings->author : self::screenName($content['authorId']);
                break;
            case Field::LICENSE:
                $value = $settings->license !== null ? $settings->license->value : '';
                break;
            default:
                $value = $settings->notice;
        }

        $value = trim($value);

        return $value !== '' ? $value : Field::OFF;
    }

    /**
     * 记录中仍与字段的值相同、即没有被手动修改过的部分
     *
     * @param array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>} $content
     * @return array<string, string>
     */
    private static function untouched(array $content): array
    {
        return array_filter(
            self::record($content),
            static fn(string $value, string $name): bool => trim($content['fields'][$name] ?? '') === $value,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>} $content
     * @return array<string, string>
     */
    private static function record(array $content): array
    {
        $record = json_decode($content['fields'][Field::SEALED] ?? '', true);
        if (!is_array($record)) {
            return [];
        }

        $record = array_intersect_key($record, array_flip(self::FIELDS));

        return array_map('strval', array_filter($record, 'is_string'));
    }

    /**
     * 文章和独立页面及其版权字段；指定 cid 时只取这一篇
     *
     * 只在发布、打开或保存插件设置、执行写入和更新时调用，不影响前台。不指定 cid 时同一请求内只查询一次，
     * 写入后重新查询。
     *
     * @return list<array{cid: int, type: string, authorId: int, title: string, fields: array<string, string>}>
     */
    private static function contents(?int $cid = null): array
    {
        if ($cid === null && self::$contents !== null) {
            return self::$contents;
        }

        $db = Db::get();
        $query = $db->select('cid', 'type', 'authorId', 'title')->from('table.contents')
            ->where('type = ? OR type = ?', 'post', 'page');
        // 只取需要的字段，不读其他插件和主题的字段
        $names = array_merge(self::FIELDS, [Field::SEALED, Field::SOURCE_URL], array_values(Field::LEGACY_NAMES));
        $fieldQuery = $db->select('cid', 'name', 'str_value')->from('table.fields')->where('name IN ?', $names);
        if ($cid !== null) {
            $query->where('cid = ?', $cid);
            $fieldQuery->where('cid = ?', $cid);
        }

        $fields = [];
        foreach ($db->fetchAll($fieldQuery) as $row) {
            $fields[(int) $row['cid']][(string) $row['name']] = (string) $row['str_value'];
        }

        $contents = array_values(array_map(static fn(array $row): array => [
            'cid'      => (int) $row['cid'],
            'type'     => (string) $row['type'],
            'authorId' => (int) $row['authorId'],
            'title'    => (string) $row['title'],
            'fields'   => $fields[(int) $row['cid']] ?? [],
        ], $db->fetchAll($query)));

        if ($cid === null) {
            self::$contents = $contents;
        }

        return $contents;
    }

    private static function screenName(int $authorId): string
    {
        /** @var array<int, string> $names */
        static $names = [];

        if (!isset($names[$authorId])) {
            $db = Db::get();
            $user = $db->fetchRow($db->select('screenName', 'name')->from('table.users')->where('uid = ?', $authorId));
            $names[$authorId] = (string) ($user['screenName'] ?? '') ?: (string) ($user['name'] ?? '');
        }

        return $names[$authorId];
    }
}
