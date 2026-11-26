<?php
declare(strict_types=1);

/**
 * 测试替身集合。
 *
 * 集中放在一个文件里，bootstrap 一次性 require：
 * 这些类不是被测对象，只是数据形状，没必要一个类一个文件。
 * （真正需要被扫描器按路径推导类名的控制器在 fixtures/controllers/ 下）
 */

namespace fixtures;

use phpet\attribute\validate\Date;
use phpet\attribute\validate\Each;
use phpet\attribute\validate\Email;
use phpet\attribute\validate\In;
use phpet\attribute\validate\Integer;
use phpet\attribute\validate\Length;
use phpet\attribute\validate\Range;
use phpet\attribute\validate\Regex;
use phpet\attribute\validate\Required;
use phpet\attribute\validate\Valid;
use phpet\core\BaseDto;
use phpet\core\middleware\MiddlewareInterface;
use phpet\core\Request;
use phpet\core\Response;

/* ------------------------------------------------------------------ *
 * 校验：基础规则
 * ------------------------------------------------------------------ */

class RequiredDto
{
    #[Required]
    public ?string $name = null;
}

/** 无类型属性，便于塞入 0 / false / 数组等边界值 */
class LooseRequiredDto
{
    #[Required]
    public $value;
}

class LengthDto
{
    #[Length(min: 3, max: 5)]
    public ?string $nick = null;

    #[Length(min: 1, max: 2)]
    public ?array $tags = null;

    #[Length(min: 1)]
    public $anything = null;
}

class RangeDto
{
    #[Range(min: 1, max: 120)]
    public $age = null;

    #[Range(min: 18)]
    public $atLeast = null;

    #[Range(min: 0, max: 10)]
    public $atMost = null;
}

class FormatDto
{
    #[Email]
    public $email = null;

    #[Integer]
    public $qty = null;

    #[Date(format: 'Y-m-d')]
    public $day = null;

    #[Regex('/^1\d{10}$/')]
    public $phone = null;

    #[In(values: ['user', 'admin'])]
    public $role = null;
}

/* ------------------------------------------------------------------ *
 * 校验：嵌套、批量、分组、自定义消息
 * ------------------------------------------------------------------ */

class ChildDto
{
    #[Required]
    public ?string $city = null;
}

class ParentDto
{
    #[Valid]
    public ?ChildDto $child = null;
}

class TagsDto
{
    #[Each(rules: [new Required()])]
    public array $tags = [];
}

class ItemDto extends BaseDto
{
    #[Required]
    public ?string $code = null;
}

class ItemsDto
{
    #[Each(dto: ItemDto::class)]
    public array $items = [];
}

class GroupDto
{
    #[Required(groups: ['create'])]
    public ?string $onlyCreate = null;

    #[Required]
    public ?string $allGroups = null;

    #[Required(groups: ['create', 'update'])]
    public ?string $twoGroups = null;

    #[Length(min: 2, groups: ['update'], message: '{name} 至少 {min} 位')]
    public ?string $updateOnly = null;
}

class MultiDto
{
    #[Required]
    #[Length(min: 3, message: '{name} 太短了')]
    public ?string $x = null;
}

class CustomMsgDto
{
    #[Length(min: 5, max: 8, message: '长度需为 {min}-{max}，当前 {length}，字段 {path}')]
    public ?string $code = null;
}

/* ------------------------------------------------------------------ *
 * DTO 解析：类型转换与嵌套
 * ------------------------------------------------------------------ */

class TypedDto
{
    public ?int $count = 7;
    public ?float $ratio = 1.5;
    public ?bool $enabled = false;
    public ?string $label = 'default';
    public ?array $list = ['default'];
}

class ScalarDto
{
    public ?string $name = null;
    public ?int $age = null;
    public ?bool $active = null;
    public ?array $tags = [];
}

/** 顶层也有 city，用来验证嵌套 DTO 不会串用上层数据 */
class NestedRootDto
{
    #[Valid]
    public ?ChildDto $child = null;

    public ?string $city = null;
}

/* ------------------------------------------------------------------ *
 * 容器
 * ------------------------------------------------------------------ */

interface Contract
{
    public function name(): string;
}

class Impl implements Contract
{
    public function name(): string
    {
        return 'impl';
    }
}

class Impl2 implements Contract
{
    public function name(): string
    {
        return 'impl2';
    }
}

class NoDeps
{
    public string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(4));
    }
}

class DepA
{
}

class DepB
{
    public function __construct(public DepA $a)
    {
    }
}

class CircularA
{
    public function __construct(public CircularB $b)
    {
    }
}

class CircularB
{
    public function __construct(public CircularA $a)
    {
    }
}

abstract class AbstractThing
{
}

class NeedsScalar
{
    public function __construct(public string $token)
    {
    }
}

class OptionalScalar
{
    public function __construct(public string $token = 'fallback')
    {
    }
}

/* ------------------------------------------------------------------ *
 * 中间件
 * ------------------------------------------------------------------ */

/** 记录中间件执行顺序，静态属性便于断言 */
class Recorder
{
    /** @var string[] */
    public static array $log = [];

    public static function reset(): void
    {
        self::$log = [];
    }

    public static function add(string $entry): void
    {
        self::$log[] = $entry;
    }
}

class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(private string $label = 'mw')
    {
    }

    public function before(?Request $request): ?Response
    {
        Recorder::add('before:' . $this->label);

        return null;
    }

    public function after(?Response $response, ?\Throwable $exception = null): ?Response
    {
        Recorder::add('after:' . $this->label . ($exception !== null ? ':ex' : ''));

        return null;
    }
}

class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $label = 'sc',
        private int    $status = 403
    ) {
    }

    public function before(?Request $request): ?Response
    {
        Recorder::add('before:' . $this->label);

        return Response::json(['blocked' => true])->status($this->status);
    }

    public function after(?Response $response, ?\Throwable $exception = null): ?Response
    {
        Recorder::add('after:' . $this->label);

        return null;
    }
}

/** after 里改写响应 */
class RetagMiddleware implements MiddlewareInterface
{
    public function before(?Request $request): ?Response
    {
        return null;
    }

    public function after(?Response $response, ?\Throwable $exception = null): ?Response
    {
        return ($response ?? Response::text(''))->header('X-Retag', '1');
    }
}

class ThrowingMiddleware implements MiddlewareInterface
{
    public function before(?Request $request): ?Response
    {
        Recorder::add('before:throw');
        throw new \RuntimeException('boom in before');
    }

    public function after(?Response $response, ?\Throwable $exception = null): ?Response
    {
        Recorder::add('after:throw' . ($exception !== null ? ':ex' : ''));

        return null;
    }
}

/** 结构上像中间件，但没有实现接口 */
class NotAMiddleware
{
    public function before(?Request $request): ?Response
    {
        return null;
    }

    public function after(?Response $response, ?\Throwable $exception = null): ?Response
    {
        return null;
    }
}

/** 能通过 is_subclass_of，但 new 不出来 */
abstract class AbstractMiddleware implements MiddlewareInterface
{
}

/* ------------------------------------------------------------------ *
 * 控制器用 DTO
 * ------------------------------------------------------------------ */

class ApiDto extends BaseDto
{
    #[Required(groups: ['create'])]
    public ?string $title = null;
}
