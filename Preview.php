<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Common;
use Typecho\Config;
use Typecho\Db;
use Widget\Base\Contents;
use Widget\Options;
use Widget\Security;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 设置页和编辑页的实时预览
 *
 * 页面脚本把尚未保存的表单提交给后台操作，由这里按与前台相同的逻辑渲染。
 * 样式以主题为准，预览只用后台的样式和自定义样式示意结构与内容。
 */
final class Preview
{
    /**
     * 预览区域，内容由脚本填入
     *
     * @param string $context settings 或 editor
     */
    public static function container(string $context, string $type = ''): string
    {
        $url = Security::alloc()->getIndex('/action/' . Action::NAME . '?do=preview');
        $note = $context === 'settings'
            ? _t('依据上述全局设置，新发布的内容默认显示以下版权信息。你可以在自定义字段中单独调整。')
            : _t('按当前填写的内容显示，保存后生效。');

        return '<div class="copyright-preview" data-context="' . Html::escape($context) . '" data-type="' . Html::escape($type) . '"'
            . ' data-url="' . Html::escape($url) . '">'
            . '<p class="copyright-preview-title">' . _t('预览') . ' <span class="description">' . $note . '</span></p>'
            . '<div class="copyright-preview-body"></div>'
            . '</div>';
    }

    /**
     * 设置页：用未保存的设置渲染最近发布的一篇文章，字段全部留空，即新发布的内容默认显示的样子
     */
    public static function forSettings(Settings $settings): string
    {
        $db = Db::get();
        $row = $db->fetchRow($db->select('cid')->from('table.contents')
            ->where('type = ? AND status = ?', 'post', 'publish')
            ->order('created', Db::SORT_DESC)
            ->limit(1));

        $content = $row ? self::content((int) $row['cid']) : null;

        return self::render('post', $content ?? self::placeholder(''), new Config(), $settings);
    }

    /**
     * 编辑页：用编辑中的字段渲染这一篇，没有填写的 1.x 旧字段仍从已保存的字段读取
     *
     * @param array<string, mixed> $fields 编辑页提交的 fields[]
     */
    public static function forEditor(int $cid, string $type, string $title, array $fields): string
    {
        $content = $cid > 0 ? self::content($cid) : null;

        $values = [];
        if ($cid > 0) {
            $db = Db::get();
            foreach ($db->fetchAll($db->select('name', 'str_value')->from('table.fields')->where('cid = ?', $cid)) as $row) {
                $values[(string) $row['name']] = (string) $row['str_value'];
            }
        }
        foreach ([Field::DISPLAY, Field::SOURCE_URL, Field::AUTHOR, Field::LICENSE, Field::NOTICE] as $name) {
            $values[$name] = is_string($fields[$name] ?? null) ? $fields[$name] : '';
        }

        $content ??= self::placeholder($title);
        if ($title !== '') {
            $content['title'] = $title;
        }

        return self::render($type, $content, new Config($values), Settings::current());
    }

    /**
     * @param array{permalink: string, title: string, date: string, screenName: string} $content
     */
    private static function render(string $type, array $content, Config $fields, Settings $settings): string
    {
        $statement = Statement::fromFields($type, $content, $fields, $settings);
        if ($statement === null) {
            return '<p class="description">' . _t('不显示版权信息') . '</p>';
        }

        return self::style($settings) . Renderer::render($statement, $settings);
    }

    /**
     * 自定义样式，前台由 header 钩子输出
     */
    public static function style(Settings $settings): string
    {
        if ($settings->style === '') {
            return '';
        }

        // 样式中不应出现标签，以免提前结束 <style>
        return '<style>' . str_replace('<', '', $settings->style) . '</style>';
    }

    /**
     * @return array{permalink: string, title: string, date: string, screenName: string}|null
     */
    private static function content(int $cid): ?array
    {
        $widget = Contents::allocWithAlias('copyright-preview-' . $cid);
        $db = Db::get();
        $db->fetchRow($widget->select()->where('table.contents.cid = ?', $cid), [$widget, 'push']);
        if (!$widget->have()) {
            return null;
        }

        $author = $widget->author;

        return [
            'permalink'  => (string) $widget->permalink,
            'title'      => (string) $widget->title,
            'date'       => $widget->created ? $widget->date->format('Y-m-d') : '',
            'screenName' => (string) ($author->screenName ?: $author->name),
        ];
    }

    /**
     * 还没有保存过的内容，本文链接要等发布后才确定
     *
     * @return array{permalink: string, title: string, date: string, screenName: string}
     */
    private static function placeholder(string $title): array
    {
        $user = User::alloc();

        return [
            'permalink'  => Common::url('…', Options::alloc()->index),
            'title'      => $title !== '' ? $title : _t('（无标题）'),
            'date'       => date('Y-m-d'),
            'screenName' => (string) ($user->screenName ?: $user->name),
        ];
    }
}
