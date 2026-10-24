<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

use Typecho\Config;
use Widget\Base\Contents;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 某篇内容最终显示的版权信息，由全局设置和该篇的自定义字段合并而来
 *
 * @immutable
 */
final class Statement
{
    public string $author;

    /** 本文链接，或转载时的原文链接，只会是 http(s) */
    public string $link;

    public bool $isRepost;

    public ?LicenseType $license;

    /** 未经过滤的声明 HTML */
    public string $notice;

    public string $title;

    public string $permalink;

    /** 发布日期，如 2016-08-30 */
    public string $date;

    /**
     * @param array{permalink: string, title: string, date: string, screenName: string} $content
     */
    private function __construct(array $content, Config $fields, Settings $settings)
    {
        $read = static fn(string $field): string => Field::read($fields, $field, $settings->readLegacyFields);

        $sourceUrl = $read(Field::SOURCE_URL);
        $this->isRepost = $sourceUrl !== '' && $sourceUrl !== Field::OFF;
        $this->permalink = Html::webUrl($content['permalink']);

        if ($this->isRepost) {
            $this->link = Html::webUrl($sourceUrl);
        } else {
            $this->link = $sourceUrl === '' && $settings->showPermalink ? $this->permalink : '';
        }

        // 转载内容的作者、协议和声明属于原作者，不套用本站的全局设置
        $siteAuthor = $settings->author !== '' ? $settings->author : $content['screenName'];
        $siteLicense = $settings->license !== null ? $settings->license->value : '';

        $this->author = Field::override($read(Field::AUTHOR), $this->isRepost ? '' : $siteAuthor);
        $this->license = LicenseType::tryFrom(Field::override($read(Field::LICENSE), $this->isRepost ? '' : $siteLicense));
        $this->notice = Field::override($read(Field::NOTICE), $this->isRepost ? '' : $settings->notice);

        $this->title = $content['title'];
        $this->date = $content['date'];
    }

    /**
     * 转载的原文链接；原创内容，或链接不是 http(s) 时返回空字符串
     */
    public static function sourceUrl(Config $fields, Settings $settings): string
    {
        return Html::webUrl(Field::read($fields, Field::SOURCE_URL, $settings->readLegacyFields));
    }

    /**
     * 不需要显示时返回 null
     */
    public static function resolve(Contents $content, Settings $settings): ?self
    {
        $author = $content->author;

        // Typecho 1.2 每次读取 fields 都会查询数据库
        return self::fromFields((string) $content->type, [
            'permalink'  => (string) $content->permalink,
            'title'      => (string) $content->title,
            'date'       => $content->created ? $content->date->format('Y-m-d') : '',
            'screenName' => (string) ($author->screenName ?: $author->name),
        ], $content->fields, $settings);
    }

    /**
     * 由给定的内容信息和字段合并，用于预览尚未保存的字段和设置；不需要显示时返回 null
     *
     * @param array{permalink: string, title: string, date: string, screenName: string} $content
     */
    public static function fromFields(string $type, array $content, Config $fields, Settings $settings): ?self
    {
        if (!self::isShown($type, $fields, $settings)) {
            return null;
        }

        $statement = new self($content, $fields, $settings);
        $isEmpty = $statement->author === ''
            && $statement->link === ''
            && $statement->license === null
            && $statement->notice === '';

        return $isEmpty ? null : $statement;
    }

    private static function isShown(string $type, Config $fields, Settings $settings): bool
    {
        if ($type !== 'post' && $type !== 'page') {
            return false;
        }

        $display = Field::read($fields, Field::DISPLAY, $settings->readLegacyFields);
        if ($display === Field::ON || $display === Field::OFF) {
            return $display === Field::ON;
        }

        return $settings->displaysOn($type);
    }
}
