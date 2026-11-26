<?php
declare(strict_types=1);

use phpet\core\RouteCache;

/**
 * 一条形状完整的路由定义，与 ControllerScanner::definitions() 保持一致。
 */
function cacheRoute(string $path = '/api/users'): array
{
    return [
        'path'        => $path,
        'methods'     => ['GET'],
        'controller'  => 'app\controller\UserController',
        'action'      => 'index',
        'middlewares' => ['auth'],
    ];
}

/**
 * 建一个临时目录跑完用例后清理。
 */
function withCacheDir(callable $fn)
{
    $dir = mktmp('phpet-cache-');

    try {
        return $fn($dir);
    } finally {
        rmtree($dir);
    }
}

function writePhp(string $dir, string $name, string $content = '<?php'): string
{
    $file = $dir . '/' . $name;
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    file_put_contents($file, $content);

    return $file;
}

test('空目录的签名是 files=0', function () {
    withCacheDir(function (string $dir) {
        $signature = (new RouteCache($dir . '/cache.php'))->signature($dir);

        assertSame(0, $signature['files']);
        assertSame('', $signature['hash']);
    });
});

test('不存在的目录同样返回空签名', function () {
    $signature = (new RouteCache('/tmp/phpet-not-exists.php'))->signature('/tmp/phpet-no-such-dir');

    assertSame(0, $signature['files']);
});

test('签名只统计 php 文件', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'A.php');
        writePhp($dir, 'readme.txt', 'text');
        writePhp($dir, 'notes.md', '#');

        assertSame(1, (new RouteCache($dir . '/cache.php'))->signature($dir)['files']);
    });
});

test('签名递归统计子目录', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'A.php');
        writePhp($dir, 'sub/B.php');
        writePhp($dir, 'sub/deep/C.php');

        assertSame(3, (new RouteCache($dir . '/cache.php'))->signature($dir)['files']);
    });
});

test('保存后即可命中，并能读回路由定义', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $cache = new RouteCache($dir . '/runtime/cache/routes.php');
        $signature = $cache->signature($dir . '/src');
        $routes = [cacheRoute(), cacheRoute('/api/user/{id}')];

        assertFalse($cache->isFresh($signature), '还没写入缓存');

        assertTrue($cache->save($signature, $routes));
        assertFileExists($cache->path());

        assertTrue($cache->isFresh($signature));
        assertSame($routes, $cache->load());
    });
});

test('源文件 mtime 变化后缓存失效', function () {
    withCacheDir(function (string $dir) {
        $file = writePhp($dir, 'src/A.php');

        $cache = new RouteCache($dir . '/cache.php');
        $signature = $cache->signature($dir . '/src');
        $cache->save($signature, [cacheRoute()]);
        assertTrue($cache->isFresh($signature));

        touch($file, time() + 10);
        clearstatcache();

        $fresh = $cache->signature($dir . '/src');
        assertNotSame($signature['hash'], $fresh['hash']);
        assertFalse($cache->isFresh($fresh));
    });
});

test('源文件大小变化后缓存失效', function () {
    withCacheDir(function (string $dir) {
        $file = writePhp($dir, 'src/A.php', '<?php // 1');

        $cache = new RouteCache($dir . '/cache.php');
        $signature = $cache->signature($dir . '/src');
        $cache->save($signature, [cacheRoute()]);

        // 保持 mtime 不变，只改大小
        file_put_contents($file, '<?php // 1234567890');
        touch($file, $signature['hash'] === '' ? time() : filemtime($file));
        clearstatcache();

        assertFalse(
            $cache->isFresh($cache->signature($dir . '/src')),
            '内容变长后签名必须不同'
        );
    });
});

test('新增控制器后缓存失效', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $cache = new RouteCache($dir . '/cache.php');
        $signature = $cache->signature($dir . '/src');
        $cache->save($signature, [cacheRoute()]);

        writePhp($dir, 'src/B.php');

        $fresh = $cache->signature($dir . '/src');
        assertSame(2, $fresh['files']);
        assertFalse($cache->isFresh($fresh));
    });
});

test('删除控制器后缓存失效', function () {
    withCacheDir(function (string $dir) {
        $file = writePhp($dir, 'src/A.php');
        writePhp($dir, 'src/B.php');

        $cache = new RouteCache($dir . '/cache.php');
        $signature = $cache->signature($dir . '/src');
        $cache->save($signature, [cacheRoute()]);
        assertTrue($cache->isFresh($signature));

        unlink($file);
        clearstatcache();

        $fresh = $cache->signature($dir . '/src');
        assertSame(1, $fresh['files']);
        assertFalse($cache->isFresh($fresh));
    });
});

test('结构版本号不同则缓存作废', function () {
    withCacheDir(function (string $dir) {
        $cacheFile = $dir . '/cache.php';
        writePhp($dir, 'src/A.php');

        $signature = (new RouteCache($cacheFile))->signature($dir . '/src');

        // 手写一份旧版本缓存
        file_put_contents($cacheFile, '<?php return ' . var_export([
            'version'   => 1,
            'signature' => $signature,
            'routes'    => [cacheRoute()],
        ], true) . ';');

        assertFalse((new RouteCache($cacheFile))->isFresh($signature));
    });
});

test('缓存文件被写坏时当作失效并自动重建', function () {
    withCacheDir(function (string $dir) {
        $cacheFile = $dir . '/cache.php';
        writePhp($dir, 'src/A.php');

        $cache = new RouteCache($cacheFile);
        $signature = $cache->signature($dir . '/src');

        file_put_contents($cacheFile, '<?php this is not valid php');

        assertFalse($cache->isFresh($signature), '语法错误应视为失效');
        assertSame([], $cache->load(), '读不出来时返回空数组而不是抛异常');

        // 重建后恢复可用
        assertTrue($cache->save($signature, [cacheRoute()]));
        assertTrue($cache->isFresh($signature));
    });
});

test('缓存文件返回非数组时视为失效', function () {
    withCacheDir(function (string $dir) {
        $cacheFile = $dir . '/cache.php';
        writePhp($dir, 'src/A.php');

        $cache = new RouteCache($cacheFile);
        $signature = $cache->signature($dir . '/src');

        file_put_contents($cacheFile, '<?php return "just a string";');

        assertFalse($cache->isFresh($signature));
        assertSame([], $cache->load());
    });
});

test('空路由列表不构成可用缓存', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $cache = new RouteCache($dir . '/cache.php');
        $signature = $cache->signature($dir . '/src');

        $cache->save($signature, []);

        assertFalse($cache->isFresh($signature), '没有路由的缓存没有意义');
    });
});

test('源目录为空时不使用缓存', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $cache = new RouteCache($dir . '/cache.php');
        $cache->save($cache->signature($dir . '/src'), [cacheRoute()]);

        $empty = $cache->signature($dir . '/empty');
        assertSame(0, $empty['files']);
        assertFalse($cache->isFresh($empty), '目录空/不存在时交给扫描阶段报错');
    });
});

test('clear 删除缓存文件', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $cache = new RouteCache($dir . '/cache.php');
        $cache->save($cache->signature($dir . '/src'), [cacheRoute()]);
        assertFileExists($cache->path());

        assertTrue($cache->clear());
        assertFalse(is_file($cache->path()));

        // 本来就不存在时也算成功
        assertTrue($cache->clear());
    });
});

test('保存到不可写位置时返回 false 而不抛异常', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        // 用一个普通文件当父「目录」，mkdir 必然失败
        $blocker = $dir . '/blocker';
        file_put_contents($blocker, 'x');

        $cache = new RouteCache($blocker . '/routes.php');

        assertFalse($cache->save($cache->signature($dir . '/src'), [cacheRoute()]));
    });
});

test('defaultPath 从项目根推导出 runtime/cache', function () {
    assertSame(
        PHPET_ROOT . '/runtime/cache/routes.php',
        RouteCache::defaultPath(PHPET_ROOT)
    );

    // 末尾斜杠不该产生双斜杠
    assertSame(
        '/srv/app/runtime/cache/routes.php',
        RouteCache::defaultPath('/srv/app/')
    );
});
