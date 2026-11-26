<?php
declare(strict_types=1);

/**
 * 测试入口：依次执行 tests/cases/ 下的用例文件。
 *
 *   php tests/run.php                 跑全部
 *   php tests/run.php router          只跑名称或文件名含 "router" 的用例
 *   php tests/run.php --no-color      关闭颜色
 *
 * 退出码：全部通过 0，有失败 1。
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$filter = null;
$noColor = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-color') {
        $noColor = true;
        continue;
    }
    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, "未知参数：{$arg}\n");
        exit(2);
    }
    $filter = $arg;
}

Harness::$filter = $filter;
Harness::$color = !$noColor
    && getenv('NO_COLOR') === false
    && function_exists('stream_isatty')
    && @stream_isatty(STDOUT);

$files = glob(__DIR__ . '/cases/*.php') ?: [];
sort($files);

printf(
    "%s\n",
    Harness::color('phpet 测试套件  ·  PHP ' . PHP_VERSION . '  ·  ' . count($files) . ' 个用例文件', '1')
);
printf("%s\n\n", Harness::color(str_repeat('─', 64), '90'));

$overallStart = microtime(true);

foreach ($files as $file) {
    Harness::$file = basename($file);
    Harness::$headerPrinted = false;

    $before = count(Harness::$results);

    try {
        require $file;
    } catch (\Throwable $e) {
        if (!Harness::$headerPrinted) {
            Harness::$headerPrinted = true;
            printf("%s\n", Harness::color('▸ ' . basename($file), '36'));
        }
        // 用例文件级的错误单独记一笔，不然整个套件会静默少跑一批用例
        Harness::$failures++;
        Harness::$results[] = [
            'file'    => basename($file),
            'name'    => '(用例文件加载失败)',
            'ok'      => false,
            'skipped' => false,
            'message' => get_class($e) . ': ' . $e->getMessage(),
            'ms'      => 0.0,
        ];
        printf("  %s %s\n", Harness::color('✘', '31'), Harness::color(get_class($e) . ': ' . $e->getMessage(), '31'));
    }

    // 全部用例被过滤掉时不输出空行，标题也由 Harness 惰性输出
    if (count(Harness::$results) > $before) {
        echo "\n";
    }
}

exit(report_summary($overallStart));
