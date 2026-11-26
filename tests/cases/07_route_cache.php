<?php
declare(strict_types=1);

use fixtures\MemoryCache;
use phpet\cache\FileCache;
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

/**
 * 文件驱动下的路由缓存，顺带把底层的 FileCache 交出来方便断言与破坏。
 *
 * @return array{0: RouteCache, 1: FileCache}
 */
function routeCacheIn(string $dir): array
{
    $store = new FileCache($dir . '/store');

    return [new RouteCache($store), $store];
}

test('空目录的签名是 files=0', function () {
    withCacheDir(function (string $dir) {
        [$cache] = routeCacheIn($dir);
        $signature = $cache->signature($dir);

        assertSame(0, $signature['files']);
        assertSame('', $signature['hash']);
    });
});

test('不存在的目录同样返回空签名', function () {
    [$cache] = routeCacheIn(sys_get_temp_dir());
    $signature = $cache->signature('/tmp/phpet-no-such-dir');

    assertSame(0, $signature['files']);
});

test('签名只统计 php 文件', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'A.php');
        writePhp($dir, 'readme.txt', 'text');
        writePhp($dir, 'notes.md', '#');

        [$cache] = routeCacheIn($dir);

        assertSame(1, $cache->signature($dir)['files']);
    });
});

test('签名递归统计子目录', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'A.php');
        writePhp($dir, 'sub/B.php');
        writePhp($dir, 'sub/deep/C.php');

        [$cache] = routeCacheIn($dir);

        assertSame(3, $cache->signature($dir)['files']);
    });
});

test('保存后即可命中，并能读回路由定义', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        [$cache, $store] = routeCacheIn($dir);
        $signature = $cache->signature($dir . '/src');
        $routes = [cacheRoute(), cacheRoute('/api/user/{id}')];

        assertFalse($cache->isFresh($signature), '还没写入缓存');

        assertTrue($cache->save($signature, $routes));
        assertTrue($store->has(RouteCache::DEFAULT_KEY), '落进了通用缓存，不再是独立文件');

        assertTrue($cache->isFresh($signature));
        assertSame($routes, $cache->load());
    });
});

test('源文件 mtime 变化后缓存失效', function () {
    withCacheDir(function (string $dir) {
        $file = writePhp($dir, 'src/A.php');

        [$cache] = routeCacheIn($dir);
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

        [$cache] = routeCacheIn($dir);
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

        [$cache] = routeCacheIn($dir);
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

        [$cache] = routeCacheIn($dir);
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
        writePhp($dir, 'src/A.php');

        [$cache, $store] = routeCacheIn($dir);
        $signature = $cache->signature($dir . '/src');

        // 模拟一份老版本框架写下的缓存
        $store->set(RouteCache::DEFAULT_KEY, [
            'version'   => 1,
            'signature' => $signature,
            'routes'    => [cacheRoute()],
        ]);

        assertFalse($cache->isFresh($signature), '版本号不匹配必须失效');
    });
});

test('存进去的不是数组时视为失效', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        [$cache, $store] = routeCacheIn($dir);
        $signature = $cache->signature($dir . '/src');

        $store->set(RouteCache::DEFAULT_KEY, 'just a string');
        assertFalse($cache->isFresh($signature));
        assertSame([], $cache->load());

        $store->set(RouteCache::DEFAULT_KEY, ['routes' => 'not an array']);
        assertFalse($cache->isFresh($signature));
        assertSame([], $cache->load());
    });
});

test('缓存被写坏时当作失效并自动重建', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        [$cache, $store] = routeCacheIn($dir);
        $signature = $cache->signature($dir . '/src');
        $cache->save($signature, [cacheRoute()]);

        // 直接把底层文件写坏：文件驱动会当作未命中
        $file = $store->dir() . '/' . hash('sha256', RouteCache::DEFAULT_KEY) . '.cache';
        file_put_contents($file, 'corrupted');

        assertFalse($cache->isFresh($signature), '读不出来应视为失效');
        assertSame([], $cache->load(), '读不出来时返回空数组而不是抛异常');

        assertTrue($cache->save($signature, [cacheRoute()]));
        assertTrue($cache->isFresh($signature));
    });
});

test('驱动抛异常时当作失效，不拖垮请求', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $store = new MemoryCache();
        $store->failOnGet = true;

        $cache = new RouteCache($store);
        $signature = $cache->signature($dir . '/src');

        assertFalse($cache->isFresh($signature));
        assertSame([], $cache->load());
    });
});

test('空路由列表不构成可用缓存', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        [$cache] = routeCacheIn($dir);
        $signature = $cache->signature($dir . '/src');

        $cache->save($signature, []);

        assertFalse($cache->isFresh($signature), '没有路由的缓存没有意义');
    });
});

test('源目录为空时不使用缓存', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        [$cache] = routeCacheIn($dir);
        $cache->save($cache->signature($dir . '/src'), [cacheRoute()]);

        $empty = $cache->signature($dir . '/empty');
        assertSame(0, $empty['files']);
        assertFalse($cache->isFresh($empty), '目录空/不存在时交给扫描阶段报错');
    });
});

test('clear 删掉的是那个键，不是整个缓存目录', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        [$cache, $store] = routeCacheIn($dir);
        $cache->save($cache->signature($dir . '/src'), [cacheRoute()]);
        $store->set('user.1', ['name' => 'ann']);

        assertTrue($cache->clear());
        assertFalse($store->has(RouteCache::DEFAULT_KEY));
        assertTrue($store->has('user.1'), '业务缓存不该被路由缓存清掉');

        // 本来就不存在时也算成功
        assertTrue($cache->clear());
    });
});

test('写入失败时返回 false 而不抛异常', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        // 用一个普通文件当父「目录」，mkdir 必然失败
        $blocker = $dir . '/blocker';
        file_put_contents($blocker, 'x');

        $cache = new RouteCache(new FileCache($blocker . '/store'));
        $signature = $cache->signature($dir . '/src');

        assertFalse($cache->save($signature, [cacheRoute()]));
        assertFalse($cache->isFresh($signature));
    });
});

test('换驱动不需要改 RouteCache：内存驱动跑完整流程', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $store = new MemoryCache();
        $cache = new RouteCache($store);
        $signature = $cache->signature($dir . '/src');
        $routes = [cacheRoute()];

        assertFalse($cache->isFresh($signature));
        assertTrue($cache->save($signature, $routes));
        assertTrue($cache->isFresh($signature));
        assertSame($routes, $cache->load());

        assertTrue($cache->clear());
        assertFalse($cache->isFresh($signature));
    });
});

test('命中时不再产生写操作', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $store = new MemoryCache();
        $cache = new RouteCache($store);
        $signature = $cache->signature($dir . '/src');

        $cache->save($signature, [cacheRoute()]);
        $writes = $store->writes;

        assertTrue($cache->isFresh($signature));
        assertSame($writes, $store->writes, '命中路径必须只读不写');
    });
});

test('可以指定自定义键，与应用缓存互不干扰', function () {
    withCacheDir(function (string $dir) {
        writePhp($dir, 'src/A.php');

        $store = new MemoryCache();
        $default = new RouteCache($store);
        $custom = new RouteCache($store, 'routes.staging');

        $signature = $default->signature($dir . '/src');
        $custom->save($signature, [cacheRoute('/only/staging')]);

        assertTrue($custom->isFresh($signature));
        assertFalse($default->isFresh($signature), '不同键之间不该互相命中');
        assertSame('/only/staging', $custom->load()[0]['path']);
    });
});

test('默认键名不含 PSR-16 保留字符', function () {
    assertSame(
        0,
        preg_match('/[{}()\/\\\\@:]/', RouteCache::DEFAULT_KEY),
        '换 Redis 驱动时这个键也必须合法'
    );
});
