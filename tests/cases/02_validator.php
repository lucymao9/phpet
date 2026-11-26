<?php
declare(strict_types=1);

use fixtures\CustomMsgDto;
use fixtures\FormatDto;
use fixtures\GroupDto;
use fixtures\ItemsDto;
use fixtures\ItemDto;
use fixtures\LengthDto;
use fixtures\LooseRequiredDto;
use fixtures\MultiDto;
use fixtures\ParentDto;
use fixtures\RequiredDto;
use fixtures\ChildDto;
use fixtures\RangeDto;
use fixtures\TagsDto;
use phpet\core\Exception\ValidationException;
use phpet\core\Validator;

/**
 * 校验并返回错误集合；通过则返回空数组。
 */
function vErrors(object $dto, string $group = 'default'): array
{
    try {
        (new Validator())->validate($dto, $group);
    } catch (ValidationException $e) {
        return $e->errors;
    }

    return [];
}

function vMessages(array $errors, string $key): array
{
    return $errors[$key] ?? [];
}

/* ------------------------------------------------------------------ *
 * Required
 * ------------------------------------------------------------------ */

test('Required：null 触发默认文案', function () {
    $errors = vErrors(new RequiredDto());

    assertArrayHasKey('name', $errors);
    assertSame(['The name field is required.'], vMessages($errors, 'name'));
});

test('Required：空字符串与空数组算「空」', function () {
    $empty = new LooseRequiredDto();
    $empty->value = '';
    assertArrayHasKey('value', vErrors($empty), '空字符串');

    $emptyArray = new LooseRequiredDto();
    $emptyArray->value = [];
    assertArrayHasKey('value', vErrors($emptyArray), '空数组');
});

test('Required：0 与 false 不算空', function () {
    $zero = new LooseRequiredDto();
    $zero->value = 0;
    assertSame([], vErrors($zero), '0 是有效值');

    $false = new LooseRequiredDto();
    $false->value = false;
    assertSame([], vErrors($false), 'false 是有效值');
});

test('Required：未初始化属性按 null 处理', function () {
    // LooseRequiredDto::$value 无默认值，never assigned
    assertArrayHasKey('value', vErrors(new LooseRequiredDto()));
});

test('Required：null 时不再触发同字段的其它规则', function () {
    // x 为 null，Length 会跳过，只剩 Required 一条
    assertCount(1, vMessages(vErrors(new MultiDto()), 'x'));
});

/* ------------------------------------------------------------------ *
 * Length
 * ------------------------------------------------------------------ */

test('Length：字符串按字符数计算（中文按 1 个字符）', function () {
    $dto = new LengthDto();
    $dto->nick = '张三';

    $messages = vMessages(vErrors($dto), 'nick');
    assertCount(1, $messages);
    assertSame('The nick length must be 3-5.', $messages[0]);
});

test('Length：数组按元素个数计算', function () {
    $dto = new LengthDto();

    $dto->tags = [];
    assertArrayHasKey('tags', vErrors($dto), '空数组长度 0，小于 min');

    $dto->tags = ['a', 'b'];
    assertArrayNotHasKey('tags', vErrors($dto), '长度 2，在 1-2 之间');

    $dto->tags = ['a', 'b', 'c'];
    assertArrayHasKey('tags', vErrors($dto), '长度 3，超出 max');
});

test('Length：非标量值报「必须为字符串或数组」', function () {
    $dto = new LengthDto();
    $dto->anything = new stdClass();

    assertSame(
        ['The anything must be a string or array.'],
        vMessages(vErrors($dto), 'anything')
    );
});

test('Length：边界值通过', function () {
    $dto = new LengthDto();

    $dto->nick = 'abc';
    assertSame([], vErrors($dto), '正好等于 min');

    $dto->nick = 'abcde';
    assertSame([], vErrors($dto), '正好等于 max');
});

/* ------------------------------------------------------------------ *
 * Range
 * ------------------------------------------------------------------ */

test('Range：非数字报「必须是数字」', function () {
    $dto = new RangeDto();
    $dto->age = 'abc';

    assertSame(['The age must be a number.'], vMessages(vErrors($dto), 'age'));
});

test('Range：越界使用 between 文案', function () {
    $dto = new RangeDto();
    $dto->age = 200;

    assertSame(
        ['The age must be between 1 and 120.'],
        vMessages(vErrors($dto), 'age')
    );
});

test('Range：上界未设置时用 at least 文案', function () {
    $dto = new RangeDto();
    $dto->atLeast = 3;

    assertSame(
        ['The atLeast must be at least 18.'],
        vMessages(vErrors($dto), 'atLeast')
    );
});

test('Range：下界为 0 时用 at most 文案', function () {
    $dto = new RangeDto();
    $dto->atMost = 11;

    assertSame(
        ['The atMost must be at most 10.'],
        vMessages(vErrors($dto), 'atMost')
    );
});

test('Range：区间内通过', function () {
    $dto = new RangeDto();
    $dto->age = 30;
    $dto->atLeast = 18;
    $dto->atMost = 0;

    assertSame([], vErrors($dto));
});

/* ------------------------------------------------------------------ *
 * Email / Integer / Date / Regex / In
 * ------------------------------------------------------------------ */

test('Email：非法与合法', function () {
    $dto = new FormatDto();

    $dto->email = 'not-an-email';
    assertSame(
        ['The email must be a valid email address.'],
        vMessages(vErrors($dto), 'email')
    );

    $dto->email = 'a@b.co';
    assertSame([], vErrors($dto));
});

test('Integer：小数与数字字符串', function () {
    $dto = new FormatDto();

    $dto->qty = '12.5';
    assertSame(['The qty must be an integer.'], vMessages(vErrors($dto), 'qty'));

    $dto->qty = '12';
    assertSame([], vErrors($dto), '数字字符串按整数接受');

    $dto->qty = 12;
    assertSame([], vErrors($dto));
});

test('Date：严格比对格式化回写结果', function () {
    $dto = new FormatDto();

    $dto->day = '2024-13-01';
    assertSame(
        ['The day must match the date format Y-m-d.'],
        vMessages(vErrors($dto), 'day')
    );

    // 2024-02-30 会被解析成 3 月 1 日，回写不一致即视为非法
    $dto->day = '2024-02-30';
    assertArrayHasKey('day', vErrors($dto), '不存在的日期');

    $dto->day = '2024-02-29';
    assertSame([], vErrors($dto), '闰年 2 月 29 日合法');
});

test('Regex：正则不匹配时报错', function () {
    $dto = new FormatDto();

    $dto->phone = '123';
    assertSame(
        ['The phone format is invalid.'],
        vMessages(vErrors($dto), 'phone')
    );

    $dto->phone = '13800138000';
    assertSame([], vErrors($dto));
});

test('In：枚举外取值报错并列出可选值', function () {
    $dto = new FormatDto();

    $dto->role = 'guest';
    assertSame(
        ['The role must be one of: user, admin.'],
        vMessages(vErrors($dto), 'role')
    );

    $dto->role = 'admin';
    assertSame([], vErrors($dto));
});

test('非 Required 规则在 null 时全部跳过', function () {
    assertSame([], vErrors(new FormatDto()));
});

/* ------------------------------------------------------------------ *
 * 嵌套与批量
 * ------------------------------------------------------------------ */

test('Valid：嵌套 DTO 错误带父字段前缀', function () {
    $dto = new ParentDto();
    $dto->child = new ChildDto();

    $errors = vErrors($dto);

    assertArrayHasKey('child.city', $errors);
    assertSame(['The city field is required.'], $errors['child.city']);
});

test('Valid：嵌套值为 null 时跳过', function () {
    assertSame([], vErrors(new ParentDto()));
});

test('Each + rules：元素错误带下标', function () {
    $dto = new TagsDto();
    $dto->tags = ['ok', ''];

    $errors = vErrors($dto);

    assertArrayHasKey('tags[1]', $errors);
    assertArrayNotHasKey('tags[0]', $errors);
});

test('Each + rules：空数组不报错', function () {
    assertSame([], vErrors(new TagsDto()));
});

test('Each + dto：逐元素构造 DTO 并递归校验', function () {
    $dto = new ItemsDto();
    $dto->items = [['code' => 'a'], []];

    $errors = vErrors($dto);

    assertArrayHasKey('items[1].code', $errors);
    assertArrayNotHasKey('items[0].code', $errors);
});

test('Each + dto：元素不是对象时报类型错误', function () {
    $dto = new ItemsDto();
    $dto->items = ['not-an-object'];

    $errors = vErrors($dto);

    assertArrayHasKey('items[0]', $errors);
    assertSame('The items[0] must be an object.', $errors['items[0]'][0]);
});

test('Each + dto：元素类型按属性声明做转换', function () {
    $dto = new ItemsDto();
    $dto->items = [['code' => 123]];

    // code 声明为 ?string，123 会被转成 '123'，非空即通过
    assertSame([], vErrors($dto));
});

/* ------------------------------------------------------------------ *
 * 分组
 * ------------------------------------------------------------------ */

test('分组：groups 为空表示对所有分组生效', function () {
    $dto = new GroupDto();

    assertSame(['allGroups'], array_keys(vErrors($dto, 'default')));
    assertContains('allGroups', array_keys(vErrors($dto, 'create')));
    assertContains('allGroups', array_keys(vErrors($dto, 'update')));
});

test('分组：只校验声明了该分组的规则', function () {
    $dto = new GroupDto();

    $keys = array_keys(vErrors($dto, 'create'));
    sort($keys);
    assertSame(['allGroups', 'onlyCreate', 'twoGroups'], $keys);

    $keys = array_keys(vErrors($dto, 'update'));
    sort($keys);
    assertSame(['allGroups', 'twoGroups'], $keys, 'update 分组不含 onlyCreate');
});

test('分组：规则在未声明的分组下完全不生效', function () {
    $dto = new GroupDto();
    $dto->updateOnly = 'a';

    // updateOnly 只属于 update 分组，default 下哪怕值非法也不报错
    assertArrayNotHasKey('updateOnly', vErrors($dto, 'default'));
    assertArrayNotHasKey('updateOnly', vErrors($dto, 'create'));
    assertArrayHasKey('updateOnly', vErrors($dto, 'update'));
});

/* ------------------------------------------------------------------ *
 * 自定义消息与错误结构
 * ------------------------------------------------------------------ */

test('自定义消息覆盖默认文案', function () {
    $dto = new CustomMsgDto();
    $dto->code = 'abc';

    assertSame(
        ['长度需为 5-8，当前 3，字段 code'],
        vMessages(vErrors($dto), 'code')
    );
});

test('自定义消息支持 {name} 占位符', function () {
    $dto = new GroupDto();
    $dto->updateOnly = 'a';

    assertSame(['updateOnly 至少 2 位'], vMessages(vErrors($dto, 'update'), 'updateOnly'));
});

test('同字段多条规则错误全部累积', function () {
    $dto = new MultiDto();
    $dto->x = '';

    $messages = vMessages(vErrors($dto), 'x');

    assertCount(2, $messages);
    assertSame('The x field is required.', $messages[0]);
    assertSame('x 太短了', $messages[1]);
});

test('ValidationException 携带 errors 且消息固定', function () {
    $e = assertThrows(
        ValidationException::class,
        fn() => (new Validator())->validate(new RequiredDto())
    );

    assertSame('Validation failed', $e->getMessage());
    assertArrayHasKey('name', $e->errors);
    assertSame(400, $e->getStatus(), '校验失败按 400 返回');
});

test('校验器实例复用不累积上一次的错误', function () {
    $validator = new Validator();

    assertThrows(
        ValidationException::class,
        fn() => $validator->validate(new RequiredDto())
    );

    // 第二次换成合法数据，不该带着上一次的错误
    $ok = new RequiredDto();
    $ok->name = 'tom';
    $validator->validate($ok);

    assertTrue(true, '第二次校验未抛异常');
});

test('path 参数作为错误键前缀', function () {
    $validator = new Validator();
    $dto = new RequiredDto();

    $e = assertThrows(
        ValidationException::class,
        fn() => $validator->validate($dto, 'default', 'list.0')
    );

    assertArrayHasKey('list.0.name', $e->errors);
});
