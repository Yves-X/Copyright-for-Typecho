<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 把版权信息渲染成 HTML
 *
 * 输出用一对注释包住，以便识别已经追加过的内容，并从摘要中剔除。
 */
final class Renderer
{
    private const START = '<!--copyright-->';
    private const END = '<!--/copyright-->';

    /** 自定义模板可用的变量，与 variables() 的键一一对应 */
    public const TEMPLATE_VARIABLES = [
        'author', 'link', 'linkUrl', 'linkLabel', 'license', 'licenseName', 'licenseSummary', 'licenseUrl',
        'notice', 'title', 'permalink', 'date', 'site',
    ];

    public static function render(Statement $statement, Settings $settings): string
    {
        $html = $settings->template === ''
            ? self::defaultLayout($statement)
            : self::fillTemplate($settings->template, $statement);

        return self::START . $html . self::END;
    }

    public static function isRendered(string $html): bool
    {
        return strpos($html, self::START) !== false;
    }

    public static function strip(string $html): string
    {
        $pattern = '/' . preg_quote(self::START, '/') . '.*?' . preg_quote(self::END, '/') . '/s';

        return (string) preg_replace($pattern, '', $html);
    }

    /**
     * 默认结构与 1.x 一致，主题中已有的 .content-copyright 样式继续有效
     */
    private static function defaultLayout(Statement $statement): string
    {
        $lines = [];

        if ($statement->author !== '') {
            $lines[] = _t('版权属于：%s', Html::escape($statement->author));
        }

        if ($statement->link !== '') {
            $lines[] = _t('%s：%s', self::linkLabel($statement), self::linkHtml($statement));
        }

        $license = $statement->license;
        if ($license !== null) {
            // 保留所有权利是一句声明，不是许可协议，所以不加“许可协议：”
            $lines[] = $license->isAllRightsReserved()
                ? self::licenseHtml($license)
                : _t('许可协议：%s', self::licenseHtml($license));
        }

        if ($statement->notice !== '') {
            $lines[] = Html::inline($statement->notice);
        }

        $paragraphs = array_map(static fn(string $line): string => '<p class="content-copyright">' . $line . '</p>', $lines);

        return '<hr class="content-copyright" /><blockquote class="content-copyright">'
            . implode('', $paragraphs)
            . '</blockquote>';
    }

    private static function fillTemplate(string $template, Statement $statement): string
    {
        $replacements = [];
        foreach (self::variables($statement) as $name => $html) {
            $replacements['{' . $name . '}'] = $html;
        }

        return strtr($template, $replacements);
    }

    /**
     * 模板变量，除 link、license、notice 外都是转义后的纯文本
     *
     * @return array<string, string>
     */
    private static function variables(Statement $statement): array
    {
        $hasLink = $statement->link !== '';
        $license = $statement->license;

        return [
            'author'         => Html::escape($statement->author),
            'link'           => $hasLink ? self::linkHtml($statement) : '',
            'linkUrl'        => Html::escape($statement->link),
            'linkLabel'      => $hasLink ? self::linkLabel($statement) : '',
            'license'        => $license !== null ? self::licenseHtml($license) : '',
            'licenseName'    => $license !== null ? Html::escape($license->title()) : '',
            'licenseSummary' => $license !== null ? Html::escape($license->summary()) : '',
            'licenseUrl'     => $license !== null ? Html::escape((string) $license->deedUrl()) : '',
            'notice'         => Html::inline($statement->notice),
            'title'          => Html::escape($statement->title),
            'permalink'      => Html::escape($statement->permalink),
            'date'           => Html::escape($statement->date),
            'site'           => Html::escape((string) Options::alloc()->title),
        ];
    }

    private static function linkLabel(Statement $statement): string
    {
        return $statement->isRepost ? _t('原文链接') : _t('本文链接');
    }

    private static function linkHtml(Statement $statement): string
    {
        $url = Html::escape($statement->link);
        $external = $statement->isRepost ? ' target="_blank" rel="noopener"' : '';

        return "<a class=\"content-copyright\" href=\"{$url}\"{$external}>{$url}</a>";
    }

    /**
     * 协议名链接到协议说明页，鼠标悬停时显示白话说明
     */
    private static function licenseHtml(LicenseType $license): string
    {
        $title = Html::escape($license->title());
        $url = $license->deedUrl();

        if ($url === null) {
            return $title;
        }

        return '<a class="content-copyright" title="' . Html::escape($license->summary()) . '"'
            . ' rel="license noopener" target="_blank" href="' . Html::escape($url) . '">' . $title . '</a>';
    }
}
