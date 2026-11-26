<?php
declare(strict_types=1);

use phpet\db\Connection;

function dbMem(array $overrides = []): Connection
{
    return Connection::fromConfig($overrides + ['driver' => 'sqlite', 'database' => ':memory:']);
}

function dbUsers(): Connection
{
    $db = dbMem();
    $db->execute(
        'create table users (id integer primary key autoincrement, name text not null, age integer)'
    );

    return $db;
}

/* ------------------------------- DSN ------------------------------- */

test('sqlite 的 DSN 直接取 database 字段', function () {
    assertSame('sqlite:/tmp/a.sqlite', dbMem(['database' => '/tmp/a.sqlite'])->dsn());
});

test('mysql 的 DSN 由 host / port / database / charset 拼成', function () {
    $db = dbMem(['driver' => 'mysql', 'database' => 'app', 'port' => 3307, 'charset' => 'utf8mb4']);

    assertSame('mysql:host=127.0.0.1;port=3307;dbname=app;charset=utf8mb4', $db->dsn());
});

test('显式 dsn 优先于拆开的字段', function () {
    $db = dbMem(['driver' => 'mysql', 'database' => 'app', 'dsn' => 'mysql:unix_socket=/tmp/mysql.sock;dbname=app']);

    assertSame('mysql:unix_socket=/tmp/mysql.sock;dbname=app', $db->dsn());
});

test('缺 database 且没有 dsn 时报错', function () {
    $db = Connection::fromConfig(['driver' => 'sqlite']);

    $e = assertThrows(\InvalidArgumentException::class, fn() => $db->dsn());
    assertStringContains('database', $e->getMessage());
});

test('不支持的驱动报错并指出驱动名', function () {
    $db = Connection::fromConfig(['driver' => 'oracle', 'database' => 'x']);

    $e = assertThrows(\InvalidArgumentException::class, fn() => $db->dsn());
    assertStringContains('oracle', $e->getMessage());
});

/* ----------------------------- 连接 ------------------------------- */

test('连接是惰性的：构造时不连，pdo() 才连', function () {
    $db = dbMem();

    assertFalse($db->isConnected());
    $db->pdo();
    assertTrue($db->isConnected());
});

test('sqlite 文件库会自动创建所在目录', function () {
    $dir = mktmp('phpet-db-');
    $path = $dir . '/nested/app.sqlite';

    try {
        $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => $path]);
        $db->execute('create table t (id integer)');

        assertFileExists($path);
    } finally {
        rmtree($dir);
    }
});

/* ----------------------------- 查询 ------------------------------- */

test('select / selectOne / scalar / execute 四种取数方式', function () {
    $db = dbUsers();
    $db->execute('insert into users (name, age) values (?, ?)', ['alice', 30]);
    $db->execute('insert into users (name, age) values (?, ?)', ['bob', 25]);

    assertCount(2, $db->select('select * from users order by id'));
    assertSame('alice', $db->selectOne('select * from users where id = ?', [1])['name']);
    assertNull($db->selectOne('select * from users where id = ?', [99]));
    assertSame(2, (int)$db->scalar('select count(*) from users'));
    assertSame(1, $db->execute('update users set age = ? where name = ?', [26, 'bob']));
});

test('lastInsertId 返回自增主键', function () {
    $db = dbUsers();
    $db->execute('insert into users (name) values (?)', ['carol']);

    assertSame('1', $db->lastInsertId());
});

test('参数绑定：值里的引号不会变成 SQL', function () {
    $db = dbUsers();
    $payload = "x'); drop table users; --";

    $db->execute('insert into users (name) values (?)', [$payload]);

    assertSame($payload, $db->selectOne('select name from users where id = 1')['name']);
    // 表还在
    assertSame(1, (int)$db->scalar('select count(*) from users'));
});

test('int 与 bool 按声明类型绑定，取回仍是原生类型', function () {
    $db = dbMem();
    $db->execute('create table flags (id integer primary key, n integer, on_off integer)');
    $db->execute('insert into flags (n, on_off) values (?, ?)', [42, true]);

    $row = $db->selectOne('select n, on_off from flags');

    assertSame(42, $row['n']);
    assertSame(1, $row['on_off']);
});

/* ----------------------------- 事务 ------------------------------- */

test('transaction 正常结束时提交', function () {
    $db = dbUsers();

    $result = $db->transaction(function (Connection $db): string {
        $db->execute('insert into users (name) values (?)', ['alice']);

        return 'done';
    });

    assertSame('done', $result);
    assertSame(1, (int)$db->scalar('select count(*) from users'));
});

test('transaction 内抛异常时回滚，并把异常透传出去', function () {
    $db = dbUsers();

    $e = assertThrows(\RuntimeException::class, function () use ($db) {
        $db->transaction(function (Connection $db): void {
            $db->execute('insert into users (name) values (?)', ['tmp']);
            throw new \RuntimeException('boom');
        });
    });

    assertSame('boom', $e->getMessage());
    assertSame(0, (int)$db->scalar('select count(*) from users'));
    assertSame(0, $db->transactionLevel());
});

test('嵌套事务用 savepoint，内层回滚不影响外层已写数据', function () {
    $db = dbUsers();

    $db->transaction(function (Connection $db): void {
        $db->execute('insert into users (name) values (?)', ['outer']);

        try {
            $db->transaction(function (Connection $db): void {
                $db->execute('insert into users (name) values (?)', ['inner']);
                throw new \RuntimeException('inner fail');
            });
        } catch (\RuntimeException) {
            // 内层失败被外层吞掉，外层继续
        }

        $db->execute('insert into users (name) values (?)', ['after']);
    });

    assertSame('outer', $db->selectOne('select name from users where id = 1')['name']);
    assertSame(2, (int)$db->scalar('select count(*) from users'));
});

test('transactionLevel 反映嵌套深度', function () {
    $db = dbMem();
    $levels = [];

    $db->transaction(function (Connection $db) use (&$levels): void {
        $levels[] = $db->transactionLevel();

        $db->transaction(function (Connection $db) use (&$levels): void {
            $levels[] = $db->transactionLevel();
        });
    });

    $levels[] = $db->transactionLevel();

    assertSame([1, 2, 0], $levels);
});

test('没有活动事务时 commit / rollBack 是空操作', function () {
    $db = dbMem();

    $db->commit();
    $db->rollBack();

    assertSame(0, $db->transactionLevel());
});

test('disconnect 之后可以重连', function () {
    $db = dbUsers();
    $db->disconnect();

    assertFalse($db->isConnected());
    // 内存库断开后数据没了，但连接本身应能重建
    assertTrue($db->pdo() instanceof PDO);
});
