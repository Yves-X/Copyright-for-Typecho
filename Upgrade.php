<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Db;
use Typecho\Db\Exception as DbException;
use Typecho\Plugin as PluginManager;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 从 1.x 升级
 *
 * 升级只需覆盖文件。Typecho 禁用插件时会删除插件的设置，所以不能要求用户重新启用，
 * 而是由插件自己改写注册信息；旧的自定义字段则由用户在设置页确认后再迁移。
 */
final class Upgrade
{
    /**
     * 只覆盖文件升级后，Typecho 仍按 1.x 的注册调用插件，这时就地改为当前版本的注册
     */
    public static function registerIfOutdated(): void
    {
        static $isAttempted = false;
        if ($isAttempted || !self::isRegistrationOutdated()) {
            return;
        }
        $isAttempted = true;

        try {
            self::reregister();
        } catch (DbException $e) {
            // 改写失败不影响页面输出，下次请求会再试
        }
    }

    /**
     * 仍在使用的 1.x 字段，键为旧字段名
     *
     * @return array<string, array{field: string, contents: list<array{cid: int, type: string, title: string, value: string}>}>
     */
    public static function legacyFields(): array
    {
        $db = Db::get();
        $rows = $db->fetchAll($db->select('table.fields.*', 'table.contents.title', 'table.contents.type AS contentType', 'table.contents.parent')
            ->from('table.fields')
            ->join('table.contents', 'table.contents.cid = table.fields.cid', Db::LEFT_JOIN)
            ->where('table.fields.name IN ?', array_values(Field::LEGACY_NAMES))
            ->order('table.fields.cid', Db::SORT_DESC));

        $usage = [];
        foreach ($rows as $row) {
            $legacy = $row['name'];
            $usage[$legacy] ??= [
                'field'    => (string) array_search($legacy, Field::LEGACY_NAMES, true),
                'contents' => [],
            ];

            // 已发布内容的草稿由原内容编辑
            $isDraftOfPublished = (int) $row['parent'] > 0 && substr((string) $row['contentType'], -6) === '_draft';
            $usage[$legacy]['contents'][] = [
                'cid'   => $isDraftOfPublished ? (int) $row['parent'] : (int) $row['cid'],
                'type'  => (string) $row['contentType'],
                'title' => (string) $row['title'],
                'value' => self::value($row),
            ];
        }

        return $usage;
    }

    /**
     * 把选中的 1.x 字段改名为新字段
     *
     * 新字段已有不同的值时保留新字段，旧字段原样留下，计入 skipped。
     *
     * @param list<string> $legacyNames 旧字段名，如 switch、url
     * @return array{migrated: int, skipped: int}
     */
    public static function migrateFields(array $legacyNames): array
    {
        $db = Db::get();
        $result = ['migrated' => 0, 'skipped' => 0];

        foreach (array_intersect(Field::LEGACY_NAMES, $legacyNames) as $field => $legacy) {
            $rows = $db->fetchAll($db->select()->from('table.fields')->where('name = ?', $legacy));

            foreach ($rows as $row) {
                $where = ['cid = ? AND name = ?', $row['cid'], $field];
                $current = $db->fetchRow($db->select()->from('table.fields')->where(...$where));

                if ($current) {
                    $value = self::value($current);
                    if ($value !== '' && $value !== self::value($row)) {
                        $result['skipped']++;
                        continue;
                    }

                    $db->query($db->delete('table.fields')->where(...$where));
                }

                $db->query($db->update('table.fields')
                    ->rows(['name' => $field])
                    ->where('cid = ? AND name = ?', $row['cid'], $legacy));
                $result['migrated']++;
            }
        }

        return $result;
    }

    /**
     * 当前版本才注册了后台操作，以此判断注册是否来自 1.x
     */
    private static function isRegistrationOutdated(): bool
    {
        if (!isset(PluginManager::export()['activated'][Plugin::NAME])) {
            return false;
        }

        return !isset(self::readOption('actionTable')[Action::NAME]);
    }

    private static function reregister(): void
    {
        PluginManager::deactivate(Plugin::NAME);
        Plugin::register();
        PluginManager::activate(Plugin::NAME);

        self::writeOption('plugins', PluginManager::export());
    }

    /**
     * 读取数组类型的选项：Typecho 1.2 用 serialize 保存，1.3 起用 JSON
     *
     * @return array<mixed>
     */
    private static function readOption(string $name): array
    {
        $saved = self::savedOption($name);
        $value = self::isSerialized($saved)
            ? unserialize($saved, ['allowed_classes' => false])
            : json_decode($saved, true);

        return is_array($value) ? $value : [];
    }

    /**
     * 按选项原有的格式写入
     *
     * @param array<mixed> $value
     */
    private static function writeOption(string $name, array $value): void
    {
        $encoded = self::isSerialized(self::savedOption($name)) ? serialize($value) : json_encode($value);

        $db = Db::get();
        $db->query($db->update('table.options')->rows(['value' => $encoded])->where('name = ? AND user = 0', $name));
    }

    private static function savedOption(string $name): string
    {
        $db = Db::get();
        $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ? AND user = 0', $name));

        return (string) ($row['value'] ?? '');
    }

    private static function isSerialized(string $value): bool
    {
        return strpos($value, 'a:') === 0;
    }

    /**
     * 字段的值按类型保存在 str_value、int_value 或 float_value 中
     *
     * @param array<string, mixed> $row
     */
    private static function value(array $row): string
    {
        $column = in_array($row['type'], ['int', 'float'], true) ? $row['type'] . '_value' : 'str_value';

        return trim((string) $row[$column]);
    }
}
