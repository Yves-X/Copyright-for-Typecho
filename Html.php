<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 转义与过滤：插件输出的每一段文本和链接都经过这里
 */
final class Html
{
    /** 声明中允许保留的行内标签 */
    private const INLINE_TAGS = ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'code', 'br', 'span'];

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 只接受带主机名的 http 和 https 链接，其余一律返回空字符串
     */
    public static function webUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || self::hasControlCharacters($url)) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $url : '';
    }

    /**
     * 过滤声明：只保留少量行内标签，属性只保留 a 的 http(s) 或 mailto 链接
     */
    public static function inline(string $html): string
    {
        $html = strip_tags($html, '<' . implode('><', self::INLINE_TAGS) . '>');

        return (string) preg_replace_callback(
            '/<(\/?)([a-z]+)\b([^>]*)>/i',
            static function (array $match): string {
                [, $closing, $tag, $attributes] = $match;
                $tag = strtolower($tag);

                if (!in_array($tag, self::INLINE_TAGS, true)) {
                    return '';
                }

                if ($closing !== '') {
                    return "</{$tag}>";
                }

                if ($tag !== 'a') {
                    return "<{$tag}>";
                }

                $href = self::href(self::attribute($attributes, 'href'));

                return $href === '' ? '<a>' : '<a href="' . self::escape($href) . '">';
            },
            $html
        );
    }

    private static function href(string $href): string
    {
        $href = trim($href);
        $isMailto = stripos($href, 'mailto:') === 0 && !self::hasControlCharacters($href);

        return $isMailto ? $href : self::webUrl($href);
    }

    private static function attribute(string $attributes, string $name): string
    {
        $pattern = '/\b' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
        if (!preg_match($pattern, $attributes, $match)) {
            return '';
        }

        // 三个分组中只有一个匹配
        return html_entity_decode(implode('', array_slice($match, 1)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 空白与控制字符会被浏览器忽略，从而让 java\nscript: 这样的链接绕过检查
     */
    private static function hasControlCharacters(string $url): bool
    {
        return preg_match('/[\x00-\x20\x7f]/', $url) === 1;
    }
}
