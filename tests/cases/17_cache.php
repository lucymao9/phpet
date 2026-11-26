<?php
declare(strict_types=1);

use phpet\cache\CacheException;
use phpet\cache\FileCache;

/**
 * 在临时目录里跑一段缓存逻辑，结束后清理。
 */
function cacheIn(callable $fn): void
{
    $dir = mktmp('phpet-cache-');

    try {
        $fn(new FileCache($dir), $dir);
    } finally {
        rmtree($dir);
    }
}

/* ------------------------------ 基本 ------------------------------- */

test('set 之后 get 拿回同一个值', function () {
    cacheIn(function (FileCache $cache) {
        // 键不能含 PSR-16 的保留字符（: { } ( ) / \ @），所以用 user.1 而不是 user:1
        assertTrue($cache->set('user.1', ['name' => 'alice', 'age' => 30]));

        assertSame(['name' => 'alice', 'age' => 30], $cache->get('user.1'));
        assertTrue($cache->has('user.1'));
    });
});

test('未命中的键返回默认值', function () {
    cacheIn(function (FileCache $cache) {
        assertNull($cache->get('missing'));
        assertSame('fallback', $cache->get('missing', 'fallback'));
        assertFalse($cache->has('missing'));
    });
});

test('存 false 时返回 false，不能退回默认值', function () {
    cacheIn(function (FileCache $cache) {
        $cache->set('off', false);

        // 这里如果按「取不到就用默认值」实现就会错，false 是合法值
        assertSame(false, $cache->get('off', 'default-should-not-be-used'));
        assertTrue($cache->has('off'));
    });
});

test('对象能原样往返，类型不变', function () {
    cacheIn(function (FileCache $cache) {
        $object = new ArrayObject([1, 2, 3]);
        $cache->set('obj', $object);

        $restored = $cache->get('obj');

        assertInstanceOf(ArrayObject::class, $restored);
        assertSame([1, 2, 3], $restored->getArrayCopy());
    });
});

test('读取时目录不存在也不报错', function () {
    cacheIn(function (FileCache $cache, string $dir) {
        rmdir($dir);

        assertNull($cache->get('anything'));
        assertFalse($cache->has('anything'));
    });
});

/* ------------------------------- TTL ------------------------------- */

test('非正数 TTL 视为立刻过期', function () {
    cacheIn(function (FileCache $cache) {
        $cache->set('short', 'v', -1);

        assertSame('gone', $cache->get('short', 'gone'));
        assertFalse($cache->has('short'));
    });
});

test('DateInterval 形式的 TTL 可以接受', function () {
    cacheIn(function (FileCache $cache) {
        $cache->set('hour', 'v', new DateInterval('PT1H'));

        assertSame('v', $cache->get('hour'));
    });
});

test('prune 只删已过期的，永久键保留', function () {
    cacheIn(function (FileCache $cache, string $dir) {
        $cache->set('forever', 'v');
        $cache->set('soon', 'v', 1);

        // 把时钟往后拨 5 秒，避免真的 sleep
        assertSame(1, $cache->prune(time() + 5));
        assertSame(1, count(glob($dir . '/*.cache')));

        assertSame('v', $cache->get('forever'));
        assertNull($cache->get('soon'));
    });
});

/* ------------------------------ 多键 ------------------------------- */

test('getMultiple 按原键返回，缺的用默认值', function () {
    cacheIn(function (FileCache $cache) {
        $cache->set('a', 1);
        $cache->set('b', 2);

        $result = iterator_to_array($cache->getMultiple(['a', 'b', 'c'], 'none'));

        assertSame(['a' => 1, 'b' => 2, 'c' => 'none'], $result);
    });
});

test('setMultiple 一次写多个键', function () {
    cacheIn(function (FileCache $cache) {
        assertTrue($cache->setMultiple(['x' => 1, 'y' => 2]));

        assertSame(1, $cache->get('x'));
        assertSame(2, $cache->get('y'));
    });
});

test('deleteMultiple 与 delete 不存在的键都返回 true', function () {
    cacheIn(function (FileCache $cache) {
        $cache->setMultiple(['x' => 1, 'y' => 2]);

        assertTrue($cache->deleteMultiple(['x', 'y']));
        assertFalse($cache->has('x'));
        assertFalse($cache->has('y'));

        // PSR-16：删不存在的键也算成功
        assertTrue($cache->delete('never-existed'));
    });
});

/* ------------------------------ 清理 ------------------------------- */

test('clear 清空整个目录', function () {
    cacheIn(function (FileCache $cache, string $dir) {
        $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
        assertCount(3, glob($dir . '/*.cache'));

        assertTrue($cache->clear());
        assertSame([], glob($dir . '/*.cache') ?: []);
    });
});

/* ---------------------------- 异常与边界 ---------------------------- */

test('保留字符出现在键里时报错', function () {
    cacheIn(function (FileCache $cache) {
        foreach (['a{b}', 'a(b)', 'a/b', 'a\\b', 'a@b', 'a:b'] as $key) {
            assertThrows(CacheException::class, fn() => $cache->get($key));
        }
    });
});

test('空键报错', function () {
    cacheIn(function (FileCache $cache) {
        $e = assertThrows(CacheException::class, fn() => $cache->set('', 1));

        assertStringContains('empty', $e->getMessage());
    });
});

test('CacheException 是 InvalidArgumentException 的子类', function () {
    cacheIn(function (FileCache $cache) {
        assertThrows(\InvalidArgumentException::class, fn() => $cache->get('bad{key}'));
    });
});

test('写入是原子的：目录里不留临时文件', function () {
    cacheIn(function (FileCache $cache, string $dir) {
        $cache->set('a', str_repeat('x', 10000));

        assertSame([], glob($dir . '/*.tmp') ?: []);
        assertCount(1, glob($dir . '/*.cache'));
    });
});

test('文件损坏时当作未命中，且不抛异常', function () {
    cacheIn(function (FileCache $cache, string $dir) {
        $cache->set('k', 'v');
        $file = (string)glob($dir . '/*.cache')[0];

        file_put_contents($file, 'not a serialized payload');

        assertNull($cache->get('k'));
        assertSame('default', $cache->get('k', 'default'));
    });
});

test('键只差一个字符也不会互相覆盖', function () {
    cacheIn(function (FileCache $cache) {
        $cache->set('user.1', 'alice');
        $cache->set('user.2', 'bob');

        assertSame('alice', $cache->get('user.1'));
        assertSame('bob', $cache->get('user.2'));
    });
});
