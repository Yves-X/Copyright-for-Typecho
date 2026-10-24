<?php

declare(strict_types=1);

/**
 * PHPStan 的引导文件：定义 Typecho 运行时才有的常量和函数
 */

define('__TYPECHO_ROOT_DIR__', getenv('TYPECHO_DIR'));

if (!function_exists('_t')) {
    /**
     * @param mixed ...$args
     */
    function _t(string $string, ...$args): string
    {
        return $args === [] ? $string : vsprintf($string, $args);
    }
}
