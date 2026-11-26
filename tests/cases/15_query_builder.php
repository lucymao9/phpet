<?php
declare(strict_types=1);

use phpet\db\Connection;
use phpet\db\QueryBuilder;

function qbMem(): Connection
{
    $db = Connection::fromConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $db->execute('create table users (id integer primary key autoincrement, name text not null, city text, age integer)');
    $db->execute('create table orders (id integer primary key autoincrement, user_id integer not null, amount integer not null)');

    return $db;
}

function qbSeed(Connection $db): void
{
    $db->table('users')->insert(['name' => 'alice', 'city' => 'SH', 'age' => 30]);
    $db->table('users')->insert(['name' => 'bob', 'city' => 'BJ', 'age' => 25]);
    $db->table('users')->insert(['name' => 'carol', 'city' => 'SH', 'age' => null]);
    $db->table('orders')->insert(['user_id' => 1, 'amount' => 100]);
    $db->table('orders')->insert(['user_id' => 1, 'amount' => 50]);
    $db->table('orders')->insert(['user_id' => 2, 'amount' => 70]);
}

/* ----------------------------- SQL 生成 ---------------------------- */

test('默认生成 select *', function () {
    assertSame('select * from `users`', qbMem()->table('users')->toSql());
});

test('指定列时逐列加反引号，表达式原样通过', function () {
    $sql = qbMem()->table('users')->select('id', 'name', 'count(*) as total')->toSql();

    assertSame('select `id`, `name`, count(*) as total from `users`', $sql);
});

test('表别名写成 as', function () {
    $sql = qbMem()->table('users', 'u')->select('u.id')->toSql();

    assertSame('select `u`.`id` from `users` as `u`', $sql);
});

test('两参 where 等价于等号，三参 where 用指定操作符', function () {
    $sql = qbMem()->table('users')->where('id', 5)->where('age', '>=', 18)->toSql();

    assertSame('select * from `users` where `id` = ? and `age` >= ?', $sql);
    assertSame([5, 18], qbMem()->table('users')->where('id', 5)->where('age', '>=', 18)->bindings());
});

test('单参 where 直接报错，提示改用 whereNull', function () {
    $e = assertThrows(\InvalidArgumentException::class, fn() => qbMem()->table('users')->where('deleted_at'));

    assertStringContains('whereNull', $e->getMessage());
});

test('不支持的操作符被拒绝', function () {
    $e = assertThrows(
        \InvalidArgumentException::class,
        fn() => qbMem()->table('users')->where('id', 'or 1=1', 1)
    );

    assertStringContains('operator', $e->getMessage());
});

test('null 值自动转成 is null / is not null', function () {
    assertSame(
        'select * from `users` where `age` is null',
        qbMem()->table('users')->where('age', null)->toSql()
    );

    assertSame(
        'select * from `users` where `age` is not null',
        qbMem()->table('users')->where('age', '!=', null)->toSql()
    );
});

test('whereIn 空数组生成恒假条件，不会拼出 in ()', function () {
    assertSame(
        'select * from `users` where 1 = 0',
        qbMem()->table('users')->whereIn('id', [])->toSql()
    );

    assertSame(
        'select * from `users` where 1 = 1',
        qbMem()->table('users')->whereNotIn('id', [])->toSql()
    );
});

test('whereGroup 生成括号分组，orWhere 接在后面', function () {
    $sql = qbMem()->table('users')
        ->where('age', '>', 18)
        ->whereGroup(fn(QueryBuilder $q) => $q->where('city', 'SH')->orWhere('city', 'BJ'))
        ->toSql();

    assertSame(
        'select * from `users` where `age` > ? and (`city` = ? or `city` = ?)',
        $sql
    );
});

test('orWhere 两参写法不会被误认成三参', function () {
    // 回归用例：早前 orWhere 用 array_merge 展开转发，func_num_args()
    // 在 where() 里看到的是 3，于是把值当成了操作符。
    $q = qbMem()->table('users')->where('city', 'SH')->orWhere('city', 'BJ');

    assertSame(
        'select * from `users` where `city` = ? or `city` = ?',
        $q->toSql()
    );
    assertSame(['SH', 'BJ'], $q->bindings());

    $q3 = qbMem()->table('users')->where('age', '>', 18)->orWhere('age', '<', 10);
    assertSame(
        'select * from `users` where `age` > ? or `age` < ?',
        $q3->toSql()
    );

    $qn = qbMem()->table('users')->where('id', 1)->orWhere('deleted_at', null);
    assertSame(
        'select * from `users` where `id` = ? or `deleted_at` is null',
        $qn->toSql()
    );
});

test('whereBetween 与 whereLike', function () {
    $sql = qbMem()->table('users')
        ->whereBetween('age', 18, 30)
        ->whereLike('name', 'a%')
        ->toSql();

    assertSame(
        'select * from `users` where (`age` between ? and ?) and (`name` like ?)',
        $sql
    );
});

test('join 用列名而不是绑定参数', function () {
    $sql = qbMem()->table('users', 'u')
        ->select('u.name', 'o.amount')
        ->join('orders as o', 'o.user_id', '=', 'u.id')
        ->toSql();

    assertSame(
        'select `u`.`name`, `o`.`amount` from `users` as `u` inner join `orders` as `o` on `o`.`user_id` = `u`.`id`',
        $sql
    );
});

test('join 类型受白名单限制', function () {
    assertThrows(
        \InvalidArgumentException::class,
        fn() => qbMem()->table('users')->join('orders', 'a', '=', 'b', 'full')
    );
});

test('orderBy 方向非法时报错', function () {
    $e = assertThrows(\InvalidArgumentException::class, fn() => qbMem()->table('users')->orderBy('id', 'sideways'));

    assertStringContains('asc or desc', $e->getMessage());
});

test('只给 offset 不给 limit 时补一个大 limit', function () {
    $sql = qbMem()->table('users')->offset(5)->toSql();

    assertStringContains('limit 18446744073709551615 offset 5', $sql);
});

test('group by + having', function () {
    $sql = qbMem()->table('orders')
        ->select('user_id', 'count(*) as total')
        ->groupBy('user_id')
        ->having('count(*)', '>', 1)
        ->toSql();

    assertSame(
        'select `user_id`, count(*) as total from `orders` group by `user_id` having count(*) > ?',
        $sql
    );
});

/* ------------------------------ 执行 ------------------------------- */

test('insert 与 insertGetId', function () {
    $db = qbMem();

    assertSame(1, $db->table('users')->insert(['name' => 'alice', 'city' => 'SH']));
    assertSame('2', (string)$db->table('users')->insertGetId(['name' => 'bob', 'city' => 'BJ']));
    assertSame(2, $db->table('users')->count());
});

test('get / first / find / value / pluck', function () {
    $db = qbMem();
    qbSeed($db);

    $rows = $db->table('users')->orderBy('id')->get();
    assertCount(3, $rows);
    assertSame('alice', $rows[0]['name']);

    assertSame('bob', $db->table('users')->where('city', 'BJ')->first()['name']);
    assertNull($db->table('users')->where('city', 'XX')->first());

    assertSame('carol', $db->table('users')->find(3)['name']);
    assertSame('alice', $db->table('users')->find('alice', 'name')['name']);

    assertSame(30, $db->table('users')->where('name', 'alice')->value('age'));
    assertSame(['alice', 'bob', 'carol'], $db->table('users')->orderBy('id')->pluck('name'));
    assertSame([1 => 'alice', 2 => 'bob', 3 => 'carol'], $db->table('users')->orderBy('id')->pluck('name', 'id'));
});

test('count 忽略 order / limit / offset', function () {
    $db = qbMem();
    qbSeed($db);

    assertSame(3, $db->table('users')->orderBy('id', 'desc')->limit(1)->count());
});

test('group by 时 count 走子查询数分组', function () {
    $db = qbMem();
    qbSeed($db);

    // SH 有 2 人，BJ 有 1 人 → 2 个分组
    assertSame(2, $db->table('users')->groupBy('city')->count());
});

test('exists 在空结果时为 false', function () {
    $db = qbMem();
    qbSeed($db);

    assertTrue($db->table('users')->where('name', 'alice')->exists());
    assertFalse($db->table('users')->where('name', 'nobody')->exists());
});

test('链式条件不会互相污染：克隆后条件独立', function () {
    $db = qbMem();
    qbSeed($db);

    $base = $db->table('users');
    $sh = (clone $base)->where('city', 'SH');

    assertSame(3, $base->count());
    assertSame(2, $sh->count());
});

test('update 与 increment / decrement', function () {
    $db = qbMem();
    qbSeed($db);

    assertSame(1, $db->table('users')->where('name', 'alice')->update(['age' => 31]));
    assertSame(31, $db->table('users')->where('name', 'alice')->value('age'));

    assertSame(1, $db->table('users')->where('name', 'alice')->increment('age', 9));
    assertSame(40, $db->table('users')->where('name', 'alice')->value('age'));

    assertSame(1, $db->table('users')->where('name', 'alice')->decrement('age', 10));
    assertSame(30, $db->table('users')->where('name', 'alice')->value('age'));
});

test('delete 按条件删除', function () {
    $db = qbMem();
    qbSeed($db);

    assertSame(2, $db->table('users')->where('city', 'SH')->delete());
    assertSame(1, $db->table('users')->count());
});

test('chunk 分页遍历每批不过量，回调返回 false 提前结束', function () {
    $db = qbMem();

    for ($i = 1; $i <= 5; $i++) {
        $db->table('users')->insert(['name' => 'u' . $i, 'city' => 'SH']);
    }

    $sizes = [];
    $db->table('users')->orderBy('id')->chunk(2, function (array $rows) use (&$sizes): void {
        $sizes[] = count($rows);
    });
    assertSame([2, 2, 1], $sizes);

    $visited = 0;
    $db->table('users')->orderBy('id')->chunk(2, function () use (&$visited): bool {
        $visited++;

        return false;
    });
    assertSame(1, $visited);
});

test('join 查询能取到关联列', function () {
    $db = qbMem();
    qbSeed($db);

    $rows = $db->table('users', 'u')
        ->select('u.name', 'o.amount')
        ->join('orders as o', 'o.user_id', '=', 'u.id')
        ->orderBy('o.amount', 'desc')
        ->get();

    assertCount(3, $rows);
    assertSame('alice', $rows[0]['name']);
    assertSame(100, $rows[0]['amount']);
});

test('insert 空数组与 update 空数组直接报错', function () {
    $db = qbMem();

    assertThrows(\InvalidArgumentException::class, fn() => $db->table('users')->insert([]));
    assertThrows(\InvalidArgumentException::class, fn() => $db->table('users')->update([]));
});

test('chunk 大小非法时报错', function () {
    assertThrows(\InvalidArgumentException::class, fn() => qbMem()->table('users')->chunk(0, static fn() => null));
});
