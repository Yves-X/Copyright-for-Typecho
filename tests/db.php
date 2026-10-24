<?php

/**
 * 直接读写测试站点的 SQLite 数据库
 *
 * php db.php <数据库> option <名称> [<值>]       读取或原样写入 typecho_options
 * php db.php <数据库> config <名称> <JSON>       按站点的格式写入插件设置：1.2 用 serialize，1.3 起用 JSON
 * php db.php <数据库> field <cid> <名称> [<值>]   写入字符串类型的自定义字段，省略值则删除
 * php db.php <数据库> sql <语句>                 执行语句并以 JSON 输出结果
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

[, $file, $command] = $argv;
$arguments = array_slice($argv, 3);

$db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$query = static function (string $sql, array $parameters = []) use ($db): PDOStatement {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);

    return $statement;
};

$option = static fn(string $name): string
    => (string) $query('SELECT value FROM typecho_options WHERE name = ? AND user = 0', [$name])->fetchColumn();

$saveOption = static function (string $name, string $value) use ($query): void {
    $query('DELETE FROM typecho_options WHERE name = ? AND user = 0', [$name]);
    $query('INSERT INTO typecho_options (name, user, value) VALUES (?, 0, ?)', [$name, $value]);
};

switch ($command) {
    case 'option':
        [$name] = $arguments;
        if (isset($arguments[1])) {
            $saveOption($name, $arguments[1]);
        } else {
            echo $option($name), "\n";
        }
        break;

    case 'config':
        [$name, $json] = $arguments;
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $isSerialized = strpos($option('plugins'), 'a:') === 0;
        $saveOption($name, $isSerialized ? serialize($value) : json_encode($value, JSON_THROW_ON_ERROR));
        break;

    case 'field':
        [$cid, $name] = $arguments;
        $query('DELETE FROM typecho_fields WHERE cid = ? AND name = ?', [$cid, $name]);
        if (isset($arguments[2])) {
            $query(
                "INSERT INTO typecho_fields (cid, name, type, str_value, int_value, float_value) VALUES (?, ?, 'str', ?, 0, 0)",
                [$cid, $name, $arguments[2]]
            );
        }
        break;

    case 'sql':
        // PHP 8.1 以前的 pdo_sqlite 把整数列也取成字符串，统一成整数，输出才与版本无关
        $rows = array_map(
            static function (array $row): array {
                foreach (['cid', 'int_value'] as $column) {
                    if (isset($row[$column])) {
                        $row[$column] = (int) $row[$column];
                    }
                }

                return $row;
            },
            $query($arguments[0])->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
        echo json_encode($rows, JSON_UNESCAPED_UNICODE), "\n";
        break;

    default:
        fwrite(STDERR, "未知命令：{$command}\n");
        exit(1);
}
