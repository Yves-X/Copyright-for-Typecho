<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Plugin as PluginManager;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;
use Widget\Archive;
use Widget\Base\Contents;
use Widget\Contents\Page\Edit as PageEdit;
use Widget\Contents\Post\Edit as PostEdit;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 一个灵活的版权小尾巴插件
 *
 * @package Copyright
 * @author YvesX
 * @version 2.0.0
 * @since 1.2.0
 * @link https://github.com/Yves-X/Copyright-for-Typecho
 */
final class Plugin implements PluginInterface
{
    /** 插件名，也是插件目录名 */
    public const NAME = 'Copyright';

    public static function activate(): void
    {
        self::register();
    }

    public static function deactivate(): void
    {
        Helper::removeAction(Action::NAME);
    }

    public static function config(Form $form): void
    {
        SettingsForm::build($form);
    }

    public static function personalConfig(Form $form): void {}

    /**
     * 保存设置时，提示有多少内容仍是按旧设置写入的
     *
     * @param array<string, mixed> $settings
     */
    public static function configCheck(array $settings): ?string
    {
        $outdated = Seal::outdated(Settings::fromSaved($settings));
        if ($outdated === []) {
            return null;
        }

        $count = count(array_unique(array_column(array_merge(...array_values($outdated)), 'cid')));

        return _t('插件设置已经保存。有 %d 篇已发布的内容仍是按旧设置写入的，可以在插件设置页更新。', $count);
    }

    /**
     * 供主题在任意位置输出版权信息
     */
    public static function render(Contents $content): void
    {
        echo self::html($content);
    }

    /**
     * 某篇内容的版权信息 HTML，不需要显示时为空字符串
     */
    public static function html(Contents $content): string
    {
        $settings = Settings::current();
        $statement = Statement::resolve($content, $settings);

        return $statement !== null ? Renderer::render($statement, $settings) : '';
    }

    /**
     * 注册钩子和后台操作
     */
    public static function register(): void
    {
        PluginManager::factory(Contents::class)->contentEx = [self::class, 'appendStatement'];
        PluginManager::factory(Contents::class)->excerptEx = [self::class, 'stripStatement'];
        PluginManager::factory(Archive::class)->header = [self::class, 'declareLicense'];
        PluginManager::factory(PostEdit::class)->getDefaultFieldItems = [self::class, 'addPostFields'];
        PluginManager::factory(PageEdit::class)->getDefaultFieldItems = [self::class, 'addPageFields'];
        PluginManager::factory(PostEdit::class)->finishPublish = [Seal::class, 'onPublish'];
        PluginManager::factory(PageEdit::class)->finishPublish = [Seal::class, 'onPublish'];

        Helper::addAction(Action::NAME, Action::class);
    }

    /**
     * contentEx：在文章页正文末尾追加版权信息
     *
     * Typecho 1.2 把前一个插件的结果放在 $lastResult，第一个插件拿到的是 null；1.3 起直接放在 $content。
     */
    public static function appendStatement(?string $content, Contents $widget, ?string $lastResult = null): string
    {
        $content = $lastResult ?? $content ?? '';
        if (Renderer::isRendered($content)) {
            return $content;
        }

        // Typecho 1.3 起独立页面的 Edit 不再继承文章的 Edit
        if ($widget instanceof PostEdit || $widget instanceof PageEdit) {
            return $content . self::sourceLink($widget);
        }

        $settings = Settings::current();
        $isTarget = $widget instanceof Archive
            && ($widget->is('single') || ($settings->displaysOn('feed') && self::isFeed($widget)));

        if (!$settings->appendToContent || !$isTarget) {
            return $content;
        }

        return $content . self::html($widget);
    }

    /**
     * excerptEx：摘要、页面描述和 RSS 摘要中不应出现版权信息
     */
    public static function stripStatement(?string $excerpt, Contents $widget, ?string $lastResult = null): string
    {
        return Renderer::strip($lastResult ?? $excerpt ?? '');
    }

    /**
     * header：在文章页的 <head> 中用 rel="license" 声明许可协议，并输出自定义样式
     *
     * 默认不输出任何样式，版权信息沿用主题对 hr、blockquote、p 和 a 的样式。
     */
    public static function declareLicense(?string $header, Archive $archive): void
    {
        if (!$archive->is('single') || !$archive->have()) {
            return;
        }

        $settings = Settings::current();
        $statement = Statement::resolve($archive, $settings);
        if ($statement === null) {
            return;
        }

        $deedUrl = $statement->license !== null ? $statement->license->deedUrl() : null;
        if ($deedUrl !== null) {
            echo '<link rel="license" href="' . Html::escape($deedUrl) . '" />' . "\n";
        }

        $style = Preview::style($settings);
        if ($style !== '') {
            echo $style . "\n";
        }
    }

    /**
     * getDefaultFieldItems：文章编辑页的版权字段；Typecho 1.2 的独立页面编辑页也调用这里
     */
    public static function addPostFields(Layout $layout): void
    {
        $isPageEditor = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'write-page.php';
        EditorFields::addTo($layout, $isPageEditor ? 'page' : 'post');
    }

    /**
     * getDefaultFieldItems：独立页面编辑页的版权字段（Typecho 1.3 起）
     */
    public static function addPageFields(Layout $layout): void
    {
        EditorFields::addTo($layout, 'page');
    }

    /**
     * 1.x 注册的 contentEx 回调，名字不能改
     *
     * 只覆盖文件升级时，Typecho 仍按 1.x 的注册调用这里，由此完成升级。
     *
     * @deprecated 2.0.0 使用 appendStatement()
     */
    public static function Copyright(?string $content, Contents $widget, ?string $lastResult = null): string
    {
        Upgrade::registerIfOutdated();

        return self::appendStatement($content, $widget, $lastResult);
    }

    /**
     * 发布文章后，Typecho 向正文中的链接发送 pingback，正文正是经由 contentEx 读取的
     *
     * 转载时在这里带上原文链接，就由 Typecho 自己通告给原文，与正文中的链接一起去重。
     * 无论版权信息是否显示，转载都应告知原文。
     */
    private static function sourceLink(Contents $widget): string
    {
        $url = Statement::sourceUrl($widget->fields, Settings::current());

        return $url !== '' ? '<a href="' . Html::escape($url) . '"></a>' : '';
    }

    /**
     * Typecho 1.2 的聚合页把结果导入 type 为 feed 的 Archive，1.3 起标记 isFeed
     */
    private static function isFeed(Archive $archive): bool
    {
        // @phpstan-ignore property.protected (Typecho 通过 ___parameter() 公开)
        $parameter = $archive->parameter;

        return !empty($parameter->isFeed) || $parameter->type === 'feed';
    }
}
