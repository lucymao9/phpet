<?php
declare(strict_types=1);

use fixtures\AbstractThing;
use fixtures\CircularA;
use fixtures\Contract;
use fixtures\DepA;
use fixtures\DepB;
use fixtures\Impl;
use fixtures\Impl2;
use fixtures\NeedsScalar;
use fixtures\NoDeps;
use fixtures\OptionalScalar;
use phpet\core\Container;

test('无构造参数时直接实例化', function () {
    $container = new Container();

    $instance = $container->get(NoDeps::class);

    assertInstanceOf(NoDeps::class, $instance);
    assertNotEmpty($instance->id);
});

test('get 在同一容器内返回同一实例', function () {
    $container = new Container();

    assertSame($container->get(NoDeps::class), $container->get(NoDeps::class));
});

test('make 每次返回新实例', function () {
    $container = new Container();

    assertNotSame($container->make(NoDeps::class), $container->make(NoDeps::class));
});

test('自动装配构造函数依赖', function () {
    $container = new Container();

    $instance = $container->get(DepB::class);

    assertInstanceOf(DepB::class, $instance);
    assertInstanceOf(DepA::class, $instance->a);
});

test('依赖链上的对象同样是单例', function () {
    $container = new Container();

    $first = $container->get(DepB::class);
    $second = $container->get(DepB::class);

    assertSame($first, $second);
    assertSame($first->a, $container->get(DepA::class), 'DepA 应与 DepB 内注入的是同一实例');
});

test('bind 把抽象绑定到具体实现', function () {
    $container = new Container();
    $container->bind(Contract::class, Impl::class);

    $instance = $container->get(Contract::class);

    assertInstanceOf(Impl::class, $instance);
    assertSame('impl', $instance->name());
});

test('重新 bind 后旧实例失效', function () {
    $container = new Container();
    $container->bind(Contract::class, Impl::class);
    $first = $container->get(Contract::class);

    $container->bind(Contract::class, Impl2::class);
    $second = $container->get(Contract::class);

    assertInstanceOf(Impl2::class, $second);
    assertNotSame($first, $second);
});

test('singleton 注册已有实例', function () {
    $container = new Container();
    $instance = new Impl();
    $container->singleton(Contract::class, $instance);

    assertSame($instance, $container->get(Contract::class));
});

test('forget 后重新构建', function () {
    $container = new Container();
    $first = $container->get(NoDeps::class);

    $container->forget(NoDeps::class);
    $second = $container->get(NoDeps::class);

    assertNotSame($first, $second);
});

test('has 反映实例、绑定与类是否存在', function () {
    $container = new Container();

    assertTrue($container->has(NoDeps::class), '存在的类');
    assertFalse($container->has(Contract::class), '未绑定的接口');

    $container->bind(Contract::class, Impl::class);
    assertTrue($container->has(Contract::class), '绑定后');

    assertFalse($container->has('fixtures\NotExists'));
});

test('循环依赖被检测并抛错', function () {
    $container = new Container();

    $e = assertThrows(
        RuntimeException::class,
        fn() => $container->get(CircularA::class),
        'Circular dependency'
    );

    assertStringContains(CircularA::class, $e->getMessage());
});

test('类不存在时报错', function () {
    $container = new Container();

    assertThrows(
        RuntimeException::class,
        fn() => $container->get('fixtures\NotExists'),
        'not found'
    );
});

test('抽象类不可实例化时报错', function () {
    $container = new Container();

    assertThrows(
        RuntimeException::class,
        fn() => $container->get(AbstractThing::class),
        'not instantiable'
    );
});

test('内置类型参数无默认值时无法解析', function () {
    $container = new Container();

    assertThrows(
        RuntimeException::class,
        fn() => $container->get(NeedsScalar::class),
        'Cannot resolve parameter $token'
    );
});

test('内置类型参数有默认值时使用默认值', function () {
    $container = new Container();

    assertSame('fallback', $container->get(OptionalScalar::class)->token);
});
