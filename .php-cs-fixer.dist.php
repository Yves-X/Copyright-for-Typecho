<?php

// 代码风格：PER Coding Style 2.0，并约束到 PHP 7.4 可用的写法
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    // CI 把 Typecho 源码检出到 typecho/，供 PHPStan 分析
    ->exclude(['tests/fixtures', 'typecho', 'vendor']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PHP74Migration' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setFinder($finder);
