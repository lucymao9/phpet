<?php
declare(strict_types=1);

use phpet\console\Command;
use phpet\console\Console;
use phpet\console\Input;
use phpet\console\Output;
use phpet\core\Application;

/**
 * 在临时项目里跑一条控制台命令。
 *
 * @param string[]             $argv
 * @param array<string, mixed> $overrides 覆盖 Application 选项
 * @param callable|null        $before    命令执行前调用，用来铺初始文件
 * @param callable|null        $inspect   清理临时目录之前调用，返回值放进结果的第三项
 * @return array{0: int, 1: string, 2: mixed} [退出码, 输出, inspect 的返回值]
 */
function consoleRun(
    array $argv,
    array $overrides = [],
    ?callable $before = null,
    ?callable $inspect = null
): array {
    $tmp = mktmp('phpet-console-');

    $app = new Application($overrides + [
        'basePath'            => PHPET_ROOT,
        'controllerDir'       => PHPET_TESTS . '/fixtures/controllers',
        'controllerNamespace' => 'fixtures\controllers',
        'cache'               => false,
        'logDir'              => $tmp . '/logs',
        'db'                  => ['driver' => 'sqlite', 'database' => ':memory:'],
        'migrations'          => $tmp . '/migrations',
        'cacheDir'            => $tmp . '/cache',
    ]);

    if ($before !== null) {
        $before($tmp);
    }

    ob_start();

    try {
        $code = (new Console($app))->run($argv, new Output(false));
        $out = (string)ob_get_clean();
        $extra = $inspect === null ? null : $inspect($tmp);
    } catch (\Throwable $e) {
        ob_end_clean();
        rmtree($tmp);

        throw $e;
    }

    rmtree($tmp);

    return [$code, $out, $extra];
}

/* ------------------------------ Input ------------------------------ */

test('Input 解析命令名与位置参数', function () {
    $input = Input::fromArgv(['console', 'db:rollback', '3', 'extra']);

    assertSame('db:rollback', $input->command());
    assertSame(['3', 'extra'], $input->arguments());
    assertSame('3', $input->argument(0));
    assertNull($input->argument(9));
    assertSame('fallback', $input->argument(9, 'fallback'));
});

test('Input 解析 --key=value 与 --flag', function () {
    $input = Input::fromArgv(['console', 'route:list', '--method=GET', '--all']);

    assertSame('route:list', $input->command());
    assertSame('GET', $input->option('method'));
    assertTrue($input->option('all'));
    assertTrue($input->hasOption('all'));
    assertFalse($input->hasOption('missing'));
});

test('Input 中 -- 之后的 token 全部按位置参数处理', function () {
    $input = Input::fromArgv(['console', 'run', '--', '--not-an-option', 'x']);

    assertSame(['--not-an-option', 'x'], $input->arguments());
    assertFalse($input->hasOption('not-an-option'));
});

test('Input::intOption 拒绝非数字而不是静默退回默认值', function () {
    $input = Input::fromArgv(['console', 'db:rollback', '--steps=abc']);

    $e = assertThrows(\InvalidArgumentException::class, fn() => $input->intOption('steps', 1));

    assertStringContains('steps', $e->getMessage());
});

test('Input::intOption 缺省时返回默认值', function () {
    $input = Input::fromArgv(['console', 'db:rollback']);

    assertSame(1, $input->intOption('steps', 1));
    assertSame(3, Input::fromArgv(['console', 'x', '--steps=3'])->intOption('steps'));
});

/* ------------------------------ Output ----------------------------- */

test('表格按显示宽度对齐，中文不错位', function () {
    $output = new Output(false);

    ob_start();
    $output->table(['k', 'v'], [['a', '1'], ['中文', '2']]);
    $rendered = (string)ob_get_clean();

    $lines = array_values(array_filter(explode(PHP_EOL, $rendered), static fn(string $l): bool => $l !== ''));

    assertCount(3, $lines);

    // 三行的总显示宽度必须一致，否则中文那一行会顶歪
    $widths = array_map(static fn(string $line): int => mb_strwidth($line, 'UTF-8'), $lines);
    assertSame([$widths[0], $widths[0], $widths[0]], $widths);
});

test('关闭着色后不输出任何转义序列', function () {
    $plain = new Output(false);
    $colored = new Output(true);

    ob_start();
    $plain->success('ok');
    $plain->error('bad');
    $plainText = (string)ob_get_clean();

    ob_start();
    $colored->success('ok');
    $coloredText = (string)ob_get_clean();

    assertStringNotContains("\033", $plainText);
    assertStringContains("\033", $coloredText);
    assertStringContains('ok', $plainText);
});

/* ----------------------------- Console ----------------------------- */

test('不带参数时默认执行 list', function () {
    [$code, $out] = consoleRun(['console']);

    assertSame(0, $code);
    assertStringContains('phpet console', $out);
    assertStringContains('route:list', $out);
    assertStringContains('db:migrate', $out);
});

test('list 列出全部内置命令', function () {
    [$code, $out] = consoleRun(['console', 'list']);

    assertSame(0, $code);

    foreach (['list', 'route:list', 'db:migrate', 'db:rollback', 'db:status', 'make:migration', 'cache:clear'] as $name) {
        assertStringContains($name, $out);
    }
});

test('未知命令返回退出码 2 并给出相近建议', function () {
    [$code, $out] = consoleRun(['console', 'route:lis']);

    assertSame(2, $code);
    assertStringContains('Unknown command', $out);
    assertStringContains('route:list', $out);
});

test('--version 输出版本号', function () {
    [$code, $out] = consoleRun(['console', '--version']);

    assertSame(0, $code);
    assertStringContains('phpet console ' . Console::VERSION, $out);
});

test('help 打印命令描述与用法', function () {
    [$code, $out] = consoleRun(['console', 'help', 'db:rollback']);

    assertSame(0, $code);
    assertStringContains('db:rollback', $out);
    assertStringContains('--steps', $out);
});

test('参数非法时返回用法错误码 2', function () {
    [$code, $out] = consoleRun(['console', 'make:migration', 'Bad-Name']);

    assertSame(2, $code);
    assertStringContains('a-z0-9_', $out);
});

test('命令抛异常时返回 1，且默认不打印堆栈', function () {
    $app = new Application([
        'basePath'      => PHPET_ROOT,
        'cache'         => false,
        'debug'         => false,
        'logDir'        => sys_get_temp_dir() . '/phpet-tests-logs',
    ]);

    $boom = new class extends Command {
        public function name(): string
        {
            return 'boom';
        }

        public function description(): string
        {
            return 'always throws';
        }

        public function run(Input $input, Output $output): int
        {
            throw new \RuntimeException('kaboom');
        }
    };

    $console = new Console($app);
    $console->add($boom);

    ob_start();
    $code = $console->run(['console', 'boom'], new Output(false));
    $out = (string)ob_get_clean();

    assertSame(1, $code);
    assertStringContains('kaboom', $out);
    assertStringNotContains('Stack trace', $out);
});

test('重复注册同名命令直接抛异常', function () {
    $app = new Application(['basePath' => PHPET_ROOT, 'cache' => false]);
    $console = new Console($app);

    $duplicate = new class extends Command {
        public function name(): string
        {
            return 'list';
        }

        public function description(): string
        {
            return 'duplicate';
        }

        public function run(Input $input, Output $output): int
        {
            return 0;
        }
    };

    assertThrows(\InvalidArgumentException::class, fn() => $console->add($duplicate));
});

/* --------------------------- route:list ---------------------------- */

test('route:list 显示「控制器::方法」而不是 Closure', function () {
    [$code, $out] = consoleRun(['console', 'route:list']);

    assertSame(0, $code);
    assertStringContains('ApiController::show', $out);
    assertStringContains('/api/items/{id}', $out);
    assertStringNotContains('Closure', $out);
});

test('route:list 默认隐藏自动生成的 OPTIONS，--all 时显示', function () {
    [, $default] = consoleRun(['console', 'route:list']);
    [, $withAll] = consoleRun(['console', 'route:list', '--all']);

    // 表格行的动词在行首，这样能区分「真实 OPTIONS 路由」与提示文案里的字样
    assertStringNotContains(PHP_EOL . 'OPTIONS', $default);
    assertStringContains('hidden', $default);
    assertStringContains(PHP_EOL . 'OPTIONS', $withAll);
});

test('route:list --method 只列指定动词', function () {
    [, $out] = consoleRun(['console', 'route:list', '--method=POST']);

    assertStringContains('POST', $out);
    assertStringNotContains('ApiController::show', $out);
});

/* ------------------------ 文件类命令 ------------------------ */

test('make:migration 生成带时间戳的迁移文件', function () {
    [$code, $out, $files] = consoleRun(
        ['console', 'make:migration', 'create_users'],
        [],
        null,
        // 临时目录在 helper 返回后就被删了，文件内容必须在这里读出来
        static function (string $tmp): array {
            $found = glob($tmp . '/migrations/*.php') ?: [];

            return array_map(
                static fn(string $path): array => [
                    'path'    => $path,
                    'content' => (string)file_get_contents($path),
                ],
                $found
            );
        }
    );

    assertSame(0, $code);
    assertStringContains('create_users', $out);
    assertCount(1, $files);
    assertMatches('/\d{4}_\d{2}_\d{2}_\d{6}_create_users\.php$/', $files[0]['path']);
    assertStringContains('extends Migration', $files[0]['content']);
    assertStringContains('public function down', $files[0]['content']);
});

test('make:migration 拒绝会变成路径的名字', function () {
    [$code, $out] = consoleRun(['console', 'make:migration', '../evil']);

    assertSame(2, $code);
    assertStringContains('a-z0-9_', $out);
});

test('db:status 在没有迁移文件时给出提示而不是报错', function () {
    [$code, $out] = consoleRun(['console', 'db:status']);

    assertSame(0, $code);
    assertStringContains('No migration files', $out);
});

test('cache:clear 清空缓存目录', function () {
    [$code, $out, $left] = consoleRun(
        ['console', 'cache:clear'],
        [],
        static function (string $tmp): void {
            @mkdir($tmp . '/cache', 0775, true);
            file_put_contents($tmp . '/cache/demo.cache', 'x');
        },
        static fn(string $tmp): array => glob($tmp . '/cache/*.cache') ?: []
    );

    assertSame(0, $code);
    assertStringContains('Cache cleared', $out);
    assertSame([], $left);
});
