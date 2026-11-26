<?php
declare(strict_types=1);

use phpet\db\Connection;
use phpet\db\Migrator;

/**
 * 拼一个迁移文件的内容。$up / $down 是直接执行的 SQL 语句数组。
 *
 * @param string[] $up
 * @param string[] $down
 */
function migSource(array $up, array $down): string
{
    $render = static function (array $statements): string {
        $lines = array_map(
            static fn(string $sql): string => "        \$db->execute(" . var_export($sql, true) . ');',
            $statements
        );

        return implode(PHP_EOL, $lines);
    };

    return "<?php\n\nuse phpet\\db\\Connection;\nuse phpet\\db\\Migration;\n\n"
        . "return new class extends Migration {\n"
        . "    public function up(Connection \$db): void\n    {\n" . $render($up) . "\n    }\n\n"
        . "    public function down(Connection \$db): void\n    {\n" . $render($down) . "\n    }\n"
        . "};\n";
}

/**
 * 在临时目录里铺迁移文件，返回 [目录, 清理回调]。
 *
 * @param array<string, string> $files 文件名（不含 .php）=> 内容
 * @return array{0: string, 1: callable(): void}
 */
function migDir(array $files): array
{
    $dir = mktmp('phpet-mig-');

    foreach ($files as $name => $source) {
        // 带扩展名的键按完整文件名处理（用来铺 README.md 这类干扰文件）
        $file = str_contains($name, '.') ? $name : $name . '.php';
        file_put_contents($dir . '/' . $file, $source);
    }

    return [$dir, static fn() => rmtree($dir)];
}

function migUsers(string $table = 't1'): string
{
    return migSource(
        ["create table {$table} (id integer primary key, name text)"],
        ["drop table {$table}"]
    );
}

function tableExists(Connection $db, string $table): bool
{
    return (int)$db->scalar(
        "select count(*) from sqlite_master where type = 'table' and name = ?",
        [$table]
    ) > 0;
}

/* ------------------------------ files ------------------------------ */

test('files 按文件名排序且忽略非 php 文件', function () {
    [$dir, $cleanup] = migDir([
        '2026_01_02_000000_b' => migUsers('b'),
        '2026_01_01_000000_a' => migUsers('a'),
        'README.md'           => 'not a migration',
    ]);

    try {
        assertSame(['2026_01_01_000000_a', '2026_01_02_000000_b'], (new Migrator(Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']), $dir))->files());
    } finally {
        $cleanup();
    }
});

test('目录不存在时 files 返回空数组', function () {
    $migrator = new Migrator(Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']), '/nonexistent/' . bin2hex(random_bytes(4)));

    assertSame([], $migrator->files());
});

/* ----------------------------- migrate ----------------------------- */

test('migrate 按文件名顺序执行并返回名单', function () {
    [$dir, $cleanup] = migDir([
        '2026_01_02_000000_second' => migUsers('second'),
        '2026_01_01_000000_first'  => migUsers('first'),
    ]);

    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);

    try {
        $applied = (new Migrator($db, $dir))->migrate();

        assertSame(['2026_01_01_000000_first', '2026_01_02_000000_second'], $applied);
        assertTrue(tableExists($db, 'first'));
        assertTrue(tableExists($db, 'second'));
    } finally {
        $cleanup();
    }
});

test('已执行的迁移不会被重复执行', function () {
    [$dir, $cleanup] = migDir(['2026_01_01_000000_only' => migUsers()]);
    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($db, $dir);

    try {
        assertCount(1, $migrator->migrate());
        assertSame([], $migrator->migrate());
        assertSame([], $migrator->pending());
    } finally {
        $cleanup();
    }
});

test('分批执行时批次号递增', function () {
    [$dir, $cleanup] = migDir(['2026_01_01_000000_first' => migUsers('first')]);
    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($db, $dir);

    try {
        $migrator->migrate();

        // 第二批：新增一个文件后再跑
        file_put_contents($dir . '/2026_01_02_000000_second.php', migUsers('second'));
        $migrator->migrate();

        $applied = $migrator->applied();

        assertSame(1, $applied['2026_01_01_000000_first']['batch']);
        assertSame(2, $applied['2026_01_02_000000_second']['batch']);
    } finally {
        $cleanup();
    }
});

/* ---------------------------- rollback ----------------------------- */

test('rollback 回滚最近一个批次并执行 down', function () {
    [$dir, $cleanup] = migDir(['2026_01_01_000000_only' => migUsers()]);
    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($db, $dir);

    try {
        $migrator->migrate();
        $rolledBack = $migrator->rollback();

        assertSame(['2026_01_01_000000_only'], $rolledBack);
        assertFalse(tableExists($db, 't1'));
        assertSame([], $migrator->applied());
    } finally {
        $cleanup();
    }
});

test('rollback 按批次而不是按个数：同一批里的多个迁移一起回滚', function () {
    [$dir, $cleanup] = migDir([
        '2026_01_01_000000_a' => migUsers('a'),
        '2026_01_01_000001_b' => migUsers('b'),
    ]);
    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($db, $dir);

    try {
        $migrator->migrate();
        $rolledBack = $migrator->rollback();

        // 同一批 → 两条一起回滚，且顺序与执行相反
        assertSame(['2026_01_01_000001_b', '2026_01_01_000000_a'], $rolledBack);
        assertFalse(tableExists($db, 'a'));
        assertFalse(tableExists($db, 'b'));
    } finally {
        $cleanup();
    }
});

test('rollback --steps 回滚多个批次', function () {
    [$dir, $cleanup] = migDir(['2026_01_01_000000_first' => migUsers('first')]);
    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($db, $dir);

    try {
        $migrator->migrate();
        file_put_contents($dir . '/2026_01_02_000000_second.php', migUsers('second'));
        $migrator->migrate();
        file_put_contents($dir . '/2026_01_03_000000_third.php', migUsers('third'));
        $migrator->migrate();

        $rolledBack = $migrator->rollback(2);

        assertCount(2, $rolledBack);
        assertSame(['2026_01_01_000000_first'], array_keys($migrator->applied()));
        assertTrue(tableExists($db, 'first'));
    } finally {
        $cleanup();
    }
});

test('没有记录时 rollback 返回空数组', function () {
    [$dir, $cleanup] = migDir([]);
    $migrator = new Migrator(Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']), $dir);

    try {
        assertSame([], $migrator->rollback());
    } finally {
        $cleanup();
    }
});

test('rollback 步数小于 1 时报错', function () {
    $migrator = new Migrator(Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']), '/tmp');

    assertThrows(\InvalidArgumentException::class, fn() => $migrator->rollback(0));
});

/* ----------------------------- status ------------------------------ */

test('status 同时列出已执行与待执行，待执行的批次为 null', function () {
    [$dir, $cleanup] = migDir(['2026_01_01_000000_done' => migUsers('done')]);
    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($db, $dir);

    try {
        $migrator->migrate();
        file_put_contents($dir . '/2026_01_02_000000_todo.php', migUsers('todo'));

        $status = $migrator->status();

        assertCount(2, $status);
        assertSame('2026_01_01_000000_done', $status[0]['name']);
        assertSame(1, $status[0]['batch']);
        assertNotNull($status[0]['applied_at']);
        assertSame('2026_01_02_000000_todo', $status[1]['name']);
        assertNull($status[1]['batch']);
        assertNull($status[1]['applied_at']);
    } finally {
        $cleanup();
    }
});

/* ------------------------------ 异常 ------------------------------- */

test('迁移文件不返回 Migration 实例时报错', function () {
    [$dir, $cleanup] = migDir(['2026_01_01_000000_bad' => "<?php\nreturn ['not' => 'a migration'];\n"]);
    $migrator = new Migrator(Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']), $dir);

    try {
        $e = assertThrows(\RuntimeException::class, fn() => $migrator->migrate());
        assertStringContains('Migration', $e->getMessage());
    } finally {
        $cleanup();
    }
});

test('up 抛异常时不写入记录，下次仍会重试', function () {
    $broken = migSource(['this is not valid sql'], []);
    [$dir, $cleanup] = migDir(['2026_01_01_000000_broken' => $broken]);
    $migrator = new Migrator(Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']), $dir);

    try {
        assertThrows(\PDOException::class, fn() => $migrator->migrate());
        assertSame(['2026_01_01_000000_broken'], $migrator->pending());
    } finally {
        $cleanup();
    }
});

test('ensureRepository 可重复调用', function () {
    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $migrator = new Migrator($db, '/tmp');

    $migrator->ensureRepository();
    $migrator->ensureRepository();

    assertTrue(tableExists($db, 'migrations'));
});
