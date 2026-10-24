<?php

/**
 * PHP 内置服务器的路由：静态文件直接返回，其余请求交给 Typecho
 *
 * php -S 127.0.0.1:8090 -t <站点目录> tests/router.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    exit;
}

$root = (string) $_SERVER['DOCUMENT_ROOT'];
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 发布后 Typecho 把要通告的链接交给自己的 /action/service 去发送。测试只记下这些链接，不真的访问外部网站
$pingLog = getenv('COPYRIGHT_PING_LOG');
if ($pingLog !== false && substr($path, -strlen('/action/service')) === '/action/service') {
    // Typecho 1.3 起以 JSON 发送，1.2 以表单发送
    $body = (string) file_get_contents('php://input');
    file_put_contents($pingLog, ($body !== '' ? $body : json_encode($_POST)) . "\n", FILE_APPEND);
    header('Content-Type: application/json');
    echo '{}';
    return true;
}
$script = is_file($root . $path) ? $path : '/index.php';

if (pathinfo($script, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

// 后台页面按脚本名判断当前位置，例如 write-page.php
$_SERVER['SCRIPT_NAME'] = $script;
chdir(dirname($root . $script));

require $root . $script;
