<?php
declare(strict_types=1);

use fixtures\NestedRootDto;
use fixtures\ScalarDto;
use fixtures\TypedDto;
use phpet\core\DtoResolver;
use phpet\core\Request;

/**
 * 用给定 body / query 构造一个 DTO。
 */
function resolveDto(string $class, array $body = [], array $query = [])
{
    $resolver = new DtoResolver(new Request($query, $body, [], []));

    return $resolver->resolve($class);
}

test('从请求体取值', function () {
    $dto = resolveDto(ScalarDto::class, ['name' => 'tom', 'age' => 20]);

    assertSame('tom', $dto->name);
    assertSame(20, $dto->age);
});

test('请求体缺失时回落到 query', function () {
    $dto = resolveDto(ScalarDto::class, [], ['name' => 'from-query']);

    assertSame('from-query', $dto->name);
});

test('请求体优先于 query', function () {
    $dto = resolveDto(
        ScalarDto::class,
        ['name' => 'from-body'],
        ['name' => 'from-query']
    );

    assertSame('from-body', $dto->name);
});

test('int 属性把数字字符串转成整数', function () {
    $dto = resolveDto(TypedDto::class, ['count' => '42']);

    assertSame(42, $dto->count);
});

test('float 属性同样按数字转换', function () {
    $dto = resolveDto(TypedDto::class, ['ratio' => '0.25']);

    assertSame(0.25, $dto->ratio);
});

test('bool 属性接受常见真值写法', function () {
    assertTrue(resolveDto(TypedDto::class, ['enabled' => 'true'])->enabled);
    assertTrue(resolveDto(TypedDto::class, ['enabled' => '1'])->enabled);
    assertTrue(resolveDto(TypedDto::class, ['enabled' => 'yes'])->enabled);
    assertFalse(resolveDto(TypedDto::class, ['enabled' => 'false'])->enabled);
    assertFalse(resolveDto(TypedDto::class, ['enabled' => '0'])->enabled);
});

test('string 属性把标量转成字符串', function () {
    $dto = resolveDto(ScalarDto::class, ['name' => 123]);

    assertSame('123', $dto->name);
});

test('array 属性只在值确实是数组时赋值', function () {
    $dto = resolveDto(ScalarDto::class, ['tags' => ['a', 'b']]);
    assertSame(['a', 'b'], $dto->tags);

    $bad = resolveDto(ScalarDto::class, ['tags' => 'not-an-array']);
    assertSame([], $bad->tags, '类型不符时保留默认值，交给校验处理');
});

test('int 属性遇到非数字时保留默认值', function () {
    $dto = resolveDto(TypedDto::class, ['count' => 'abc']);

    assertSame(7, $dto->count);
});

test('嵌套 DTO 递归解析', function () {
    $dto = resolveDto(NestedRootDto::class, [
        'city'  => '顶层城市',
        'child' => ['city' => '子级城市'],
    ]);

    assertInstanceOf(fixtures\ChildDto::class, $dto->child);
    assertSame('子级城市', $dto->child->city);
    assertSame('顶层城市', $dto->city);
});

test('嵌套 DTO 只从自己的数组取数，不串用顶层数据', function () {
    $dto = resolveDto(NestedRootDto::class, [
        'city'  => '顶层城市',
        'child' => [],            // 子级没有 city
    ]);

    assertInstanceOf(fixtures\ChildDto::class, $dto->child);
    assertNull($dto->child->city, '子级的 city 不应取自顶层');
});

test('嵌套字段值已是对象时直接采用', function () {
    $child = new fixtures\ChildDto();
    $child->city = 'direct';

    $dto = resolveDto(NestedRootDto::class, ['child' => $child]);

    assertSame($child, $dto->child);
});

test('嵌套字段值不是数组也不是对象时跳过', function () {
    $dto = resolveDto(NestedRootDto::class, ['child' => 'not-array']);

    assertNull($dto->child);
});

test('未声明的键被忽略', function () {
    $dto = resolveDto(ScalarDto::class, ['unknown' => 'x', 'name' => 'ok']);

    assertSame('ok', $dto->name);
    assertFalse(property_exists($dto, 'unknown'));
});

test('显式传入数据时完全不读请求', function () {
    $resolver = new DtoResolver(new Request([], ['name' => 'from-request'], [], []));

    $dto = $resolver->resolve(ScalarDto::class, ['name' => 'explicit']);

    assertSame('explicit', $dto->name);
});

test('null 值不覆盖默认值', function () {
    $dto = resolveDto(TypedDto::class, ['count' => null]);

    assertSame(7, $dto->count);
});
