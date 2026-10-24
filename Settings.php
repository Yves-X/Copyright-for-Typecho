<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Plugin\Exception as PluginException;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 插件的全局设置
 *
 * 1.x 保存的 showOnPost、showOnPage、showURL 继续有效，下次在后台保存时换成新的键名。
 *
 * @immutable
 */
final class Settings
{
    /** 设置表单中的多选项，提交时是数组 */
    public const ARRAY_KEYS = ['display', 'showPermalink', 'legacyFields'];

    /** 设置表单中的文本项 */
    public const TEXT_KEYS = ['author', 'license', 'notice', 'placement', 'template', 'style'];

    /**
     * 在哪里显示：post 和 page 与 Typecho 的内容类型一致，feed 表示 RSS / Atom 聚合
     *
     * @var list<string>
     */
    public array $display;

    /** 留空时显示文章作者的昵称 */
    public string $author;

    /** 原创内容是否显示本文链接 */
    public bool $showPermalink;

    public ?LicenseType $license;

    /** 未经过滤的声明 HTML */
    public string $notice;

    /** 自动追加到正文末尾；否则由主题调用 Plugin::render() */
    public bool $appendToContent;

    /** 留空时使用默认结构 */
    public string $template;

    /** 输出到文章页 <head> 的 CSS，留空不输出 */
    public string $style;

    /** 新字段留空时是否读取 1.x 的字段 */
    public bool $readLegacyFields;

    /**
     * @param array<string, mixed> $saved 保存的设置，全新启用时为空数组
     */
    private function __construct(array $saved)
    {
        $isNew = $saved === [];

        $this->display = $isNew ? ['post'] : self::values($saved['display'] ?? self::legacyDisplay($saved));
        $this->author = self::text($saved['author'] ?? null);
        $this->showPermalink = $isNew || self::isChecked($saved['showPermalink'] ?? $saved['showURL'] ?? null);
        $this->license = LicenseType::tryFrom(self::text($saved['license'] ?? null));
        $this->notice = $isNew ? _t('转载时须注明出处及本声明') : self::text($saved['notice'] ?? null);
        $this->appendToContent = ($saved['placement'] ?? null) !== 'manual';
        $this->template = self::text($saved['template'] ?? null);
        $this->style = self::text($saved['style'] ?? null);
        // 取消勾选的复选框保存为 null，只有缺少这一项时才是从 1.x 升级而来
        $this->readLegacyFields = !$isNew
            && (!array_key_exists('legacyFields', $saved) || self::isChecked($saved['legacyFields']));
    }

    /**
     * 由保存前的设置创建，用于在保存时比较新旧设置
     *
     * @param array<string, mixed> $saved
     */
    public static function fromSaved(array $saved): self
    {
        return new self($saved);
    }

    public static function current(): self
    {
        /** @var self|null $current */
        static $current;

        return $current ??= new self(self::load());
    }

    public function displaysOn(string $place): bool
    {
        return in_array($place, $this->display, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        try {
            return Options::alloc()->plugin(Plugin::NAME)->toArray();
        } catch (PluginException $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $saved
     * @return list<string>
     */
    private static function legacyDisplay(array $saved): array
    {
        return array_keys(array_filter([
            'post' => self::isChecked($saved['showOnPost'] ?? null),
            'page' => self::isChecked($saved['showOnPage'] ?? null),
        ]));
    }

    /**
     * 复选框保存为数组，全部取消时为 null
     *
     * @param mixed $value
     */
    private static function isChecked($value): bool
    {
        return in_array('1', self::values($value), true);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function values($value): array
    {
        return array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
