<?php
declare(strict_types=1);

/**
 * 测试引导：加载类、注册测试专用自动加载、把日志重定向到临时目录。
 */

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "phpet 需要 PHP >= 8.1，当前 " . PHP_VERSION . "\n");
    exit(1);
}

define('PHPET_ROOT', dirname(__DIR__));
define('PHPET_TESTS', __DIR__);

date_default_timezone_set('Asia/Shanghai');

$composer = PHPET_ROOT . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    // 没有 composer 时退回最小 PSR-4 加载器，
    // 让 clone 下来不装依赖也能直接跑测试
    spl_autoload_register(static function (string $class): void {
        $map = [
            'phpet\\' => 'src/',
            'app\\'   => 'app/',
        ];

        foreach ($map as $prefix => $dir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $file = PHPET_ROOT . '/' . $dir
                . str_replace('\\', '/', substr($class, strlen($prefix)))
                . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    });
}

// 测试专用类（fixtures\*）不在 composer 的 PSR-4 映射里，单独注册。
// 扫描器需要按「命名空间 + 文件路径」推出类名再 class_exists()，
// 所以这些 fixture 必须能按 PSR-4 规则加载到。
spl_autoload_register(static function (string $class): void {
    $prefix = 'fixtures\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = PHPET_TESTS . '/fixtures/'
        . str_replace('\\', '/', substr($class, strlen($prefix)))
        . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// 大量测试替身（DTO、中间件）放在同一个文件里，一次性声明
require PHPET_TESTS . '/fixtures/objects.php';

// 日志写到临时目录，避免测试过程污染 runtime/logs
\phpet\core\Log\Logger::init(
    sys_get_temp_dir() . '/phpet-tests-logs',
    \phpet\core\Log\Logger::DEBUG
);
