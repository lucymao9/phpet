<?php
declare(strict_types=1);

/**
 * 自动加载入口，public/index.php 与测试都从这里进。
 *
 * 有 composer 产物就用它；没有也能跑——内置一份最小 PSR-4 加载器，
 * 这样 clone 下来直接 php -S 就能启动，不必先 composer install。
 */

$composer = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($composer)) {
    require $composer;

    return;
}

spl_autoload_register(static function (string $class): void {
    $map = [
        'phpet\\' => 'src/',
        'app\\'   => 'app/',
    ];

    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = dirname(__DIR__) . '/' . $dir
            . str_replace('\\', '/', substr($class, strlen($prefix)))
            . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
