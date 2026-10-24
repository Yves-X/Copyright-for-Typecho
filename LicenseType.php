<?php

declare(strict_types=1);

namespace TypechoPlugin\Copyright;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 许可协议
 *
 * 用法与枚举相同：LicenseType::tryFrom('by-nc-sa')、LicenseType::cases()。标识保存在设置和自定义字段中，
 * CC 协议沿用官方短名。
 */
final class LicenseType
{
    public const CC0 = 'cc0';
    public const BY = 'by';
    public const BY_SA = 'by-sa';
    public const BY_NC = 'by-nc';
    public const BY_NC_SA = 'by-nc-sa';
    public const BY_ND = 'by-nd';
    public const BY_NC_ND = 'by-nc-nd';
    public const ALL_RIGHTS_RESERVED = 'all-rights-reserved';

    /** 按从宽到严排列 */
    private const ALL = [
        self::CC0,
        self::BY,
        self::BY_SA,
        self::BY_NC,
        self::BY_NC_SA,
        self::BY_ND,
        self::BY_NC_ND,
        self::ALL_RIGHTS_RESERVED,
    ];

    /** @readonly */
    public string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function tryFrom(string $value): ?self
    {
        return in_array($value, self::ALL, true) ? new self($value) : null;
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return array_map(static fn(string $value): self => new self($value), self::ALL);
    }

    /**
     * 下拉框选项
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $license) {
            $options[$license->value] = $license->title();
        }

        return $options;
    }

    /**
     * 协议名，如 CC BY-NC-SA 4.0
     */
    public function title(): string
    {
        if ($this->value === self::CC0) {
            return 'CC0 1.0';
        }

        if ($this->isAllRightsReserved()) {
            return _t('保留所有权利');
        }

        return 'CC ' . strtoupper($this->value) . ' 4.0';
    }

    /**
     * 协议要求的白话说明
     */
    public function summary(): string
    {
        $summaries = [
            self::CC0                 => _t('无需署名，可以商用，可以改编，放弃权利进入公有领域'),
            self::BY                  => _t('须署名，可以商用，可以改编'),
            self::BY_SA               => _t('须署名，可以商用，改编后须以相同协议分享'),
            self::BY_NC               => _t('须署名，不得商用，可以改编'),
            self::BY_NC_SA            => _t('须署名，不得商用，改编后须以相同协议分享'),
            self::BY_ND               => _t('须署名，可以商用，不得改编'),
            self::BY_NC_ND            => _t('须署名，不得商用，不得改编'),
            self::ALL_RIGHTS_RESERVED => _t('未经许可，不得转载、商用或改编'),
        ];

        return $summaries[$this->value];
    }

    /**
     * 保留所有权利不是一种许可，而是不授予任何许可
     */
    public function isAllRightsReserved(): bool
    {
        return $this->value === self::ALL_RIGHTS_RESERVED;
    }

    /**
     * 协议说明页（deed）的地址，保留所有权利时没有
     */
    public function deedUrl(): ?string
    {
        if ($this->value === self::CC0) {
            return 'https://creativecommons.org/publicdomain/zero/1.0/';
        }

        if ($this->isAllRightsReserved()) {
            return null;
        }

        return "https://creativecommons.org/licenses/{$this->value}/4.0/";
    }
}
