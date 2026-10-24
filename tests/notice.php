<?php

/**
 * 从 curl 的 Cookie 文件中读出 Typecho 最近一次的后台提示
 *
 * php notice.php <Cookie 文件>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$messages = [];

foreach (file($argv[1], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    // Netscape 格式：域名、子域、路径、安全、过期时间、名称、值，以制表符分隔
    $cookie = explode("\t", $line);
    if (count($cookie) === 7 && substr($cookie[5], -16) === '__typecho_notice') {
        $messages = (array) json_decode(urldecode($cookie[6]), true);
    }
}

echo implode("\n", $messages), "\n";
