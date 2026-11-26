# phpet

用 PHP 8 属性（Attribute）声明路由、取参与校验的极简 MVC 框架。零第三方运行时依赖。

**环境要求**：PHP >= 8.1（在 8.5.3 上验证）、ext-mbstring、ext-json、ext-filter

> 8.1 是因为 `#[Each(rules: [new Required()])]` 这种写法用到了「`new` 初始化器」，该语法自 8.1 起才允许。

---

## 特性

- **注解驱动路由** —— `#[Controller]` + `#[Route]`，支持路径参数与正则约束
- **路由缓存** —— 路由表落盘，源文件变动自动失效，启动不再反射全部控制器
- **自动参数注入** —— 路径参数、查询串、请求体、Header、Cookie、DTO 按类型自动装配
- **声明式校验** —— 10 条校验注解，支持分组、嵌套 DTO、数组逐项、自定义消息与占位符
- **DI 容器** —— 反射自动装配、单例缓存、循环依赖检测
- **中间件** —— `before` 正序 / `after` 逆序，支持短路与异常透传
- **内容协商** —— 同一套异常处理，浏览器拿 HTML 错误页，API 拿 JSON
- **分级日志** —— debug / info / warning / error，落盘在 web 根之外
- **即开即用** —— 装配收敛到 `Application` 一个类，入口 20 行；`composer create-project` 或 clone 下来直接能跑
- **自带测试套件** —— 零依赖 runner，201 个单元用例 + 20 个真实 HTTP 端到端用例

---

## 快速开始

零第三方**运行时**依赖，`vendor/` 只是自动加载产物。没装 composer 也能跑。

### 起一个新项目

```bash
composer create-project phpet/phpet my-app
cd my-app
php -S 127.0.0.1:8000 -t public public/index.php
```

还没发布到 Packagist 时，直接从 VCS 仓库拉：

```bash
composer create-project \
  --repository='{"type":"vcs","url":"git@github.com:you/phpet.git"}' \
  --stability=dev phpet/phpet my-app
```

### 在这个仓库里直接跑

```bash
git clone <repo> phpet && cd phpet
php -S 127.0.0.1:8000 -t public public/index.php
```

`bootstrap/autoload.php` 会优先用 `vendor/autoload.php`，没有就退回内置的 PSR-4 加载器，
所以 clone 下来不必先 `composer install`。想用 composer 的优化加载器时再执行：

```bash
composer dump-autoload -o
```

打开 http://127.0.0.1:8000/api/users?gender=male 就能看到第一个 JSON 响应。

调试模式（错误页展示源码与堆栈）：

```bash
APP_DEBUG=1 php -S 127.0.0.1:8000 -t public public/index.php
```

### 目录结构

```
.
├── app/                      # 业务代码
│   ├── controller/           # 控制器，启动时递归扫描
│   ├── dto/                  # 数据传输对象，需继承 BaseDto
│   └── middleware/           # 中间件
├── bootstrap/
│   └── autoload.php          # 自动加载入口（composer 优先，无则可退回内置 PSR-4）
├── public/
│   └── index.php             # 唯一入口，20 行
├── src/                      # 框架代码（phpet\ 命名空间）
│   ├── attribute/            # 注解定义
│   │   ├── request/          # 取参注解
│   │   └── validate/         # 校验注解
│   └── core/                 # Application、容器、路由、请求响应、校验、日志
├── runtime/
│   ├── cache/                # 路由缓存（自动生成，可随时删除）
│   └── logs/                 # 日志（web 根之外，已 gitignore）
├── tests/                    # 零依赖测试套件
│   ├── run.php               # 入口：php tests/run.php
│   ├── e2e.php               # 端到端：php tests/e2e.php
│   ├── cases/                # 用例，按被测单元分文件
│   └── fixtures/             # 测试替身
└── vendor/                   # composer 产物（可选）
```

`public/index.php` 的全部内容（略去 `use` 与注释）：

```php
require dirname(__DIR__) . '/bootstrap/autoload.php';

(new Application([
    'basePath'          => dirname(__DIR__),
    'middlewares'       => ['auth' => AuthMiddleware::class, 'log' => LogMiddleware::class],
    'globalMiddlewares' => ['cors'],
]))->run();
```

`Application` 负责读配置、建容器、注册中间件、扫控制器、查路由、发响应，`run()` 只是「`Request::fromGlobals()` → `handle()` → `send()`」。

需要嵌入到已有项目、或在测试里直接拿响应时，用 `handle()` 而不是 `run()`：

```php
$app = new Application(['basePath' => '/srv/app']);
$response = $app->handle(Request::fromGlobals());   // 不发响应头，返回 Response
```

PSR-4 映射：`phpet\` → `src/`，`app\` → `app/`。

---

## 路由

### 声明

```php
namespace app\controller;

use phpet\attribute\Controller;
use phpet\attribute\Route;
use phpet\core\Response;

#[Controller('/api')]
class UserController
{
    #[Route('/users', methods: ['GET'])]
    public function index()
    {
        return Response::json(['ok' => true]);
    }
}
```

最终路径 = `Controller.prefix` + `Route.path`，两端会被归一化：折叠重复斜杠、去掉尾斜杠、保证单前导斜杠。`#[Controller]` 不写前缀时直接使用 `Route.path`。

`Route::$methods` 默认 `['GET']`。一个方法需要响应多个动词时写成数组 `methods: ['GET', 'POST']`——`#[Route]` 不可重复，一个方法上写两个会触发 PHP 的 attribute 重复错误。

> 只有带 `#[Controller]` 的类会被注册；扫描只发生在 `public/index.php` 中指定的那个目录（默认 `app/controller`），子目录可用，命名空间按 PSR-4 自动推导。

### 路径参数

```php
#[Route('/user/{id}', methods: ['GET'])]
public function show(int $id)
{
    return Response::json(['id' => $id]);
}
```

- `{id}` 默认匹配 `[^/]+`
- `{id:\d+}` 可加正则约束，表达式中不要包含 `/`
- 参数值按方法签名的标量类型自动转换（`int` / `float` / `bool` / `string`）

### HTTP 语义

| 场景 | 行为 |
| --- | --- |
| 路径匹配、方法不匹配 | `405`，响应带 `Allow` 头（自动补上 `OPTIONS`） |
| `HEAD` 请求 | 复用 `GET` 的处理器，响应体在发送阶段丢弃 |
| `OPTIONS` 请求 | 每个路径自动注册占位路由，默认返回 `204` |
| 完全未匹配 | `404` |

路由查找先命中静态路径快表，未命中再按注册顺序做正则匹配。路径归一化对 `/api///users` 与 `/api/users/` 一视同仁。

控制器实例是**按需创建**的：只有命中的那个方法才会从容器取控制器实例，其余控制器不会被构造。

---

## 路由缓存

把扫描结果落盘，避免每次请求都反射全部控制器。

**失效判定**：对 `app/controller` 下所有 PHP 文件取「路径 + mtime + size」做哈希存入缓存，每次启动重算一次比对。新增、删除、修改控制器都会让缓存自动失效并重建——**不需要手动清理**。

**存放位置**：`runtime/cache/routes.php`，内容是一段 `return [...];` 的 PHP 源码，可被 opcache 缓存，且位于 web 根之外。

**debug 模式**：`APP_DEBUG=1` 时完全跳过缓存（既不读也不写），改完控制器刷新即生效，不用等签名判定的时间粒度。

**强制重建**：删掉缓存文件即可。

```bash
rm -f runtime/cache/routes.php
```

**缓存内容**形如（实际是 `var_export` 输出，此处略去了 `0 =>` 之类的下标以方便阅读）：

```php
<?php
return array(
  'version'   => 2,
  'signature' => array('files' => 2, 'hash' => '77d0c03e...'),
  'routes'    => array(
    array(
      'path'        => '/api/users',
      'methods'     => array('GET'),
      'controller'  => 'app\\controller\\UserController',
      'action'      => 'index',
      'middlewares' => array('auth', 'log'),
    ),
    // ...
  ),
);
```

要点：

- 缓存只存**路由定义**，不存闭包；命中缓存时只对实际调用的那个方法做一次反射
- 缓存文件被写坏（语法错误、结构不对）会被当作失效并自动重建，不会让请求 500
- 写入走「临时文件 + rename」，并发下不会读到半截内容
- 结构版本号变化（框架升级）会自动作废旧缓存
- `runtime/` 必须对 web 用户只读，否则缓存文件可被写入即等于任意代码执行——这是所有文件缓存的通例

**实测**（200 个控制器 / 1216 条路由）：

| 启动方式 | 耗时 |
| --- | --- |
| 反射扫描 | 9.99 ms |
| 签名计算 + 读缓存注册 | 3.33 ms |

约 3 倍提升，节省约 6.7 ms/请求。规模小时绝对值不大，但缓存路径还额外避免了启动阶段加载全部控制器类——控制器的构造逻辑（连数据库、读配置）不会为无关请求执行。

---

## 参数注入

控制器方法的参数按以下顺序解析，命中即停：

1. **路径参数** —— 参数名与 `{name}` 一致
2. **请求对象** —— 类型声明为 `Request`
3. **取参注解** —— `FromQuery` / `FromBody` / `FromHeader` / `FromCookie` / `FromEach`
4. **DTO 注入** —— 类型是 `BaseDto` 子类
5. **默认值** —— 参数有默认值
6. 以上都不满足 → 抛 `RuntimeException`

### 取参注解

| 注解 | 取值来源 | 示例 |
| --- | --- | --- |
| `#[FromQuery]` | `$_GET` | `#[FromQuery] string $q` / `#[FromQuery('keyword')] string $q` |
| `#[FromBody]` | 解析后的请求体（JSON 或表单） | `#[FromBody] string $title` |
| `#[FromHeader]` | 请求头，键名大小写不敏感 | `#[FromHeader('X-Token')] string $token` |
| `#[FromCookie]` | `$_COOKIE` | `#[FromCookie('sid')] ?string $sid` |
| `#[FromEach]` | 请求体中的数组，逐项转 DTO | `#[FromEach(CreateUserDto::class)] array $list` |

上面几个注解都接受可选的 `name:` 参数改写取值的键名，省略时用参数名。

`FromEach` 的取值键默认是参数名——参数叫 `$list` 就读请求体的 `list` 字段；用 `name:` 可以指定成别的键。

> 声明了取参注解但取不到值时，**不会**回落到其它来源（比如 `FromBody` 取不到就去找 `FromQuery`），而是直接走默认值分支。

```php
#[Route('/users', methods: ['GET'])]
public function index(
    Request $request,
    #[FromQuery] string $gender = '',
    #[FromHeader('Host')] string $host = '',
) {
    return Response::json([
        'gender' => $gender,
        'host'   => $host,
        'query'  => $request->getQuery(),
    ]);
}
```

---

## DTO 与校验

DTO 继承 `phpet\core\BaseDto`，属性用类型声明 + 校验注解：

```php
namespace app\dto;

use phpet\attribute\validate\Length;
use phpet\attribute\validate\Required;
use phpet\core\BaseDto;

class UserDto extends BaseDto
{
    #[Required(groups: ['create'], message: '用户名不能为空')]
    #[Length(min: 3, max: 20, groups: ['create', 'update'], message: '用户名长度需为 {min}-{max} 个字符，当前 {length} 个')]
    public ?string $username = null;

    #[Required(groups: ['create'])]
    #[Range(min: 1, max: 120, groups: ['create', 'update'])]
    public ?int $age = null;
}
```

当方法参数类型是 `BaseDto` 子类时，框架会按属性名从请求体取值（取不到再查查询串），做类型转换后按 `#[ValidateGroup]` 指定的分组校验。

类型转换失败时**保留属性默认值**，交给校验规则报错，不会抛 `TypeError`。

```php
#[Route('/user', methods: ['POST'])]
public function add(#[ValidateGroup('create')] UserDto $dto)
{
    return Response::json(['dto' => $dto]);
}
```

### 校验规则

| 注解 | 参数 | 语义 |
| --- | --- | --- |
| `#[Required]` | `groups`, `message` | 空值判定为 `null`、`''`、`[]`；`0` 与 `false` 视为有效值 |
| `#[Length]` | `min=0`, `max=PHP_INT_MAX` | 字符串按 `mb_strlen` 计，数组按元素个数计 |
| `#[Range]` | `min=0`, `max=PHP_INT_MAX` | 数值区间；非数值单独报「必须是数字」 |
| `#[Email]` | `groups`, `message` | `FILTER_VALIDATE_EMAIL` |
| `#[Integer]` | `groups`, `message` | `FILTER_VALIDATE_INT` |
| `#[Date]` | `format='Y-m-d'` | 按格式严格解析并回写比对，能挡住 `2026-02-31` 这类 |
| `#[Regex]` | `pattern` | 必须带分隔符，如 `/^1\d{10}$/` |
| `#[In]` | `values=[]` | 严格比较（`in_array` 第三参为 `true`） |
| `#[Valid]` | — | 嵌套 DTO 递归校验 |
| `#[Each]` | `dto` 或 `rules`, `message` | 数组逐项校验 |

除 `Required` 外，其余规则在值为 `null` 时直接跳过——**是否必填只由 `Required` 决定**，不要指望 `Length` 拦住 null。

### 分组

`groups` 默认是空数组，表示**对所有分组生效**：

```php
#[Required(groups: ['create'])]                      // 只在 create 分组校验
#[Length(min: 3, max: 20, groups: ['create', 'update'])]  // 两组都校验
#[Email]                                             // groups: [] → 任意分组都校验
```

方法参数上用 `#[ValidateGroup('create')]` 选分组；不写则分组名为 `default`。

### 嵌套与数组

```php
class CreateUserDto extends BaseDto
{
    #[Valid]
    public ?UserDto $user = null;

    #[Valid]
    public ?AddressDto $address = null;
}
```

`#[Valid]` 只在值确实是对象时递归；值为 null 时跳过（要必填就再加 `#[Required]`）。

数组两种写法：

```php
// 1) 元素是 DTO
#[Each(dto: UserDto::class)]
public ?array $users = null;

// 2) 元素是标量，套一组规则
#[Each(rules: [new Required(), new Range(min: 1)])]
public ?array $ids = null;
```

### 校验失败的响应

抛 `ValidationException`（HTTP **400**），错误键是字段路径，同一个字段可以累积多条消息：

```json
{
  "message": "Validation failed",
  "errors": {
    "user.username": [
      "用户名不能为空",
      "用户名长度需为 3-20 个字符，当前 0 个"
    ],
    "user.age": ["The age field is required."],
    "address.city": ["The city field is required."]
  }
}
```

上面这个响应来自 `POST /api/user`，载荷 `{"user":{"username":""},"address":{}}`——`username` 同时触发了 `Required` 与 `Length`，因此累积了两条消息；各条规则独立执行，失败就各自追加。

错误键的命名规则：

| 场景 | 键的形式 |
| --- | --- |
| 普通字段 | `username` |
| 嵌套 DTO | `address.city` |
| `#[Each]` 数组 | `ids[0]` |
| `#[FromEach]` 批量 | `list.0.user.username` |

`#[FromEach]` 会收集**所有条目**的错误后一次性抛出，不会因为第一条出错就中断——批量导入时能一次看全。

### 自定义消息与占位符

每条规则都支持 `message:`，占位符在输出前替换：

| 占位符 | 替换为 |
| --- | --- |
| `{name}` | 属性名 |
| `{path}` | 完整字段路径 |
| `{min}` / `{max}` | 规则参数 |
| `{length}` | 实际长度 |
| `{format}` | `Date` 的格式串 |
| `{values}` | `In` 的候选值，逗号分隔 |
| `{index}` | `Each` 的元素下标 |

---

## 响应

`phpet\core\Response` 提供静态构造器，控制器直接 `return`：

| 方法 | 状态码 | 说明 |
| --- | --- | --- |
| `Response::json($data, $status = 200)` | 200 | 按 `JSON_UNESCAPED_UNICODE` + `JSON_UNESCAPED_SLASHES` 编码；编码失败记日志并退化为 500 |
| `Response::text($content, $status = 200)` | 200 | `text/plain` |
| `Response::html($content, $status = 200)` | 200 | `text/html` |
| `Response::noContent()` | 204 | 无响应体 |
| `Response::redirect($url, $status = 302)` | 302 | 带 `Location` |
| `Response::file($path, $contentType = null)` | 200 | 浏览器内联展示，如 PDF、图片 |
| `Response::download($path, $filename = null)` | 200 | `Content-Disposition: attachment` |

链式修改：

```php
return Response::json($data)
    ->status(201)
    ->header('X-Trace-Id', $id);
```

要点：

- 文件类响应走 `readfile()` **流式输出**，大文件不会整个读进内存
- `download()` 同时输出 `filename` 与 `filename*`，中文文件名不会乱码
- `HEAD` 请求与 `204`/`304` 状态不发送响应体
- 控制器里意外的 `echo` / `var_dump` 会被输出缓冲拦截并丢弃，同时记一条 WARNING；响应头不会因此提前发出

控制器返回非 `Response` 时按类型兜底：数组 → JSON，标量 → text，`null` → 空 text。

---

## 错误处理与内容协商

### 异常层次

```
Throwable
└── HttpException(status)
    ├── BadRequestException          400
    │   └── ValidationException      400  （带 errors 数组）
    ├── NotFoundException            404
    └── MethodNotAllowedException    405  （带 Allow 方法列表）
```

不属于 `HttpException` 的异常一律按 `500` 处理。业务代码里 `throw new NotFoundException('资源不存在')` 即可。

### 内容协商

`ExceptionHandler` 根据 `Accept` 头决定渲染形式：

| `Accept` | 响应 |
| --- | --- |
| 空 或 `*/*` | JSON —— curl、SDK、移动端的默认行为 |
| 含 `text/html`，且在 `application/json` **之前** | HTML 错误页 |
| 其它 | JSON |

### 错误页

HTML 错误页包含：状态码、原因短语、异常消息、字段校验清单、请求编号。样式内联、无外部资源，跟随系统暗色模式。

`APP_DEBUG=1` 时额外展示：

- 异常类名
- 出错文件与行号
- 出错位置的源码片段（±6 行，命中行高亮）
- 完整调用栈（折叠在 `<details>` 里）

生产模式下 5xx 只返回错误标识与 `requestId`，不泄露路径与堆栈：

```json
{"error":"Internal Server Error","requestId":"64b98451fcd8bc27"}
```

`requestId` 优先取请求头 `X-Request-Id`，没有则随机生成 16 位十六进制。排查线上问题时报这个编号即可在日志里定位。

---

## 中间件

### 接口

```php
interface MiddlewareInterface
{
    public function before(?Request $request): ?Response;
    public function after(?Response $response, ?\Throwable $exception = null): ?Response;
}
```

- `before()` 返回 `Response` → **短路**，跳过剩余中间件与控制器
- `before()` 返回 `null` → 继续
- `after()` 返回 `null` → 不改写响应；返回 `Response` 则替换
- 执行顺序：`before` 按注册顺序，`after` **逆序**
- 控制器抛异常时，已执行过 `before` 的中间件仍会收到 `after()`

### 注册

```php
$registry = new MiddlewareRegistry();
$registry->register('cors', CorsMiddleware::class);                     // 无参构造
$registry->register('cors', CorsMiddleware::class, [['https://a.com'], true]); // 传构造参数
$registry->registerInstance('auth', new AuthMiddleware());              // 直接给实例
```

`register()` 会当场校验三件事：类存在、实现了 `MiddlewareInterface`、并且可实例化（抽象类会被拒），任一不满足立刻抛 `RuntimeException`。类名拼错或漏写接口在启动时就暴露，不会拖到第一次请求。

### 绑定

```php
#[Middleware('auth')]
#[Middleware('log')]
#[Controller('/api')]
class UserController
{
    #[Middleware('log')]
    #[Route('/users', methods: ['GET'])]
    public function index() { /* ... */ }
}
```

该注解可重复，类上的在前、方法上的在后。

### 全局中间件

在 `public/index.php` 里用管道包住 `dispatch()`：

```php
$pipeline = new MiddlewarePipeline($registry, $request);
$response = $pipeline->handle(
    ['cors'],
    fn() => $router->dispatch($request->method(), $request->path())
);
```

放在 `dispatch` **外层**而不是控制器外层，是为了让 404 / 405 这类未命中路由的响应也能带上 CORS 头。

### 内置中间件

| 中间件 | 说明 |
| --- | --- |
| `CorsMiddleware` | 可配 `origins` / `credentials` / `methods` / `headers` / `maxAge`。预检请求直接返回 204 短路。`credentials` 为 true 时绝不使用通配符，改为回显具体 `Origin` 并附加 `Vary: Origin` |
| `AuthMiddleware` | 骨架实现，示例代码已注释，按业务启用 |
| `LogMiddleware` | 记录 method / path / status / 耗时 / 异常 |

`CorsMiddleware` 的经典坑已经处理：`Access-Control-Allow-Origin: *` 与 `Allow-Credentials: true` 不能共存，需要带 Cookie 时把 `origins` 配成具体域名并开启 `credentials`。

---

## 容器

反射自动装配，构造器参数按类型递归解析：

| 方法 | 说明 |
| --- | --- |
| `get($abstract)` | 解析并缓存 —— 同一请求内多次 `get` 得到同一对象 |
| `make($abstract)` | 每次新建，不写缓存 |
| `bind($abstract, $concrete)` | 抽象 → 实现，并清掉旧实例 |
| `singleton($abstract, $instance)` | 直接绑定实例 |
| `forget($abstract)` | 清除缓存 |
| `has($abstract)` | 是否可解析 |

```php
$container->bind(UserRepositoryInterface::class, DbUserRepository::class);
$repo = $container->get(UserRepositoryInterface::class);
```

有循环依赖检测，命中时抛 `RuntimeException` 而不是无限递归。`Request` 在入口处用 `singleton()` 绑定为当前请求实例。

---

## 请求对象

| 方法 | 说明 |
| --- | --- |
| `method()` / `path()` | 方法名转大写；`path()` 只取路径部分 |
| `query($k, $d)` / `body($k, $d)` / `header($k, $d)` | 单值取值，`$d` 为默认值 |
| `input($k, $d)` | 先查请求体，再查查询串 |
| `has($k)` | 请求体或查询串中是否存在 |
| `all()` / `getQuery()` / `headers()` | 全量数组 |
| `isJson()` | `Content-Type` 是否含 `json` |
| `accept()` / `accepts($type)` / `wantsJson()` | 内容协商 |
| `file($k)` / `files()` / `hasFile($k)` | 上传文件 |
| `clientIp($trustProxy = false)` | 客户端 IP；`trustProxy` 为 true 时读 `X-Forwarded-For` |

两个细节：

- `path()` 手写截断 `?` 与 `#`，**不用 `parse_url()`** —— 后者会把 `//api/users` 解析成 `host=api`，丢掉路径前缀
- JSON 请求体解析失败抛 `BadRequestException`（400），不是 500

---

## 配置

### Application 配置项

`public/index.php` 里所有装配都收敛到 `Application`，可传的选项如下（未知键会直接抛 `InvalidArgumentException`，避免「配置没生效也不报错」）：

| 选项 | 默认 | 说明 |
| --- | --- | --- |
| `basePath` | `getcwd()` | 项目根。其余路径都从它推导 |
| `debug` | `APP_DEBUG` 环境变量 | 影响错误页细节、日志级别、是否跳过路由缓存 |
| `controllerDir` | `{basePath}/app/controller` | 控制器扫描目录 |
| `controllerNamespace` | `app\controller` | 与上面目录对应的命名空间 |
| `cachePath` | `{basePath}/runtime/cache/routes.php` | 路由缓存文件 |
| `cache` | `true` | 关掉后每次请求都重新扫描 |
| `logDir` | `{basePath}/runtime/logs` | 日志目录 |
| `logLevel` | debug ? `debug` : `info` | 日志最小级别 |
| `timezone` | `APP_TIMEZONE`，否则 `Asia/Shanghai` | 传给 `date_default_timezone_set()` |
| `middlewares` | `[]` | `名称 => 类名` 或 `名称 => [类名, [构造参数]]` |
| `globalMiddlewares` | `[]` | 包在路由外层的中间件名，404 / 405 也会经过 |
| `exceptionHandler` | 按 `debug` 自动创建 | 可换成自定义实现 |
| `container` | 新建 | 传入已装配的容器 |

路径全部从 `basePath` 推导、不依赖框架自身安装位置，所以整套代码放进 `vendor/` 也能正常工作。

### 环境变量

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `APP_DEBUG` | 关闭 | `1` / `true` / `on` 视为开启。影响错误页细节与日志最小级别 |
| `APP_TIMEZONE` | `Asia/Shanghai` | 传给 `date_default_timezone_set()` |

---

## 日志

```php
use phpet\core\Log\Logger;

Logger::debug('cache miss', ['key' => $key]);
Logger::info('order paid', ['id' => 123]);
Logger::error('db down', ['trace' => $e->getTraceAsString()]);
```

- **落盘位置**：`runtime/logs/app-YYYY-MM-DD.log`（在 web 根之外，不能被直接下载）
- **格式**：`[2026-11-25 15:40:19] ERROR: 消息 {"id":123}`
- **级别**：`debug < info < warning < error`，低于最小级别的直接丢弃
- **最小级别**：debug 模式为 `DEBUG`，否则 `INFO`
- **兜底**：日志目录不可写时退回 `error_log()`，绝不因为写日志失败影响主流程

异常处理的日志分级：

| 状态码 | 级别 | 是否带堆栈 |
| --- | --- | --- |
| 4xx | `WARNING` | 否（属于正常业务流） |
| 5xx | `ERROR` | 是 |

自定义落盘目录：`Logger::init('/var/log/phpet', Logger::INFO)`。

---

## 测试

不依赖 PHPUnit，零第三方依赖，clone 下来直接能跑：

```bash
php tests/run.php                # 单元 + 集成：201 个用例
php tests/run.php router         # 只跑名称或文件名含 "router" 的用例
php tests/run.php --no-color     # 关掉颜色
php tests/e2e.php                # 端到端：起 php -S 打真实 HTTP
```

`composer test` / `composer test:e2e` 是同样两条命令的别名。

### 目录结构

| 路径 | 作用 |
| --- | --- |
| `tests/run.php` | 运行器：遍历 `cases/`、统计、失败清单、退出码 |
| `tests/lib.php` | 断言库与 `Harness`（打印、计时、断言计数） |
| `tests/bootstrap.php` | 载入类、注册 fixture 自动加载、把日志重定向到临时目录 |
| `tests/cases/*.php` | 用例，按被测单元分文件 |
| `tests/fixtures/` | 测试替身：DTO、中间件、容器依赖、控制器 |
| `tests/e2e.php` | 端到端，独立进程，不影响单元测试的速度 |

### 覆盖范围

| 文件 | 覆盖内容 |
| --- | --- |
| `01_router.php` | 路径归一、`{id:\d+}` 约束、静态优先、405 + `Allow`、HEAD、`preg_quote` |
| `02_validator.php` | 全部内置规则、`Required` 的空值语义、分组、嵌套、`Each` 下标、占位符 |
| `03_dto_resolver.php` | 标量转换、嵌套不串用上层数据、类型不符时保留默认值 |
| `04_container.php` | 自动装配、单例、`bind` 失效、循环依赖、不可实例化 |
| `05_request.php` | Header 大小写、`path()` 的双斜杠回归、`wantsJson()` 矩阵 |
| `06_response.php` | 各响应助手的类型与状态、JSON 编码失败降级、下载头 |
| `07_route_cache.php` | 签名失效（mtime / size / 增删文件）、版本号、损坏自愈、写不进去 |
| `08_middleware.php` | before 正序 / after 逆序、短路、异常仍进 after、四种注册拒绝路径 |
| `09_controller_scanner.php` | 前缀拼接、OPTIONS 占位、DTO 注入、缓存路径与扫描路径等价、按需实例化 |
| `10_error_handler.php` | JSON / HTML 分支、debug 才透出细节、HTML 转义、`wantsHtml()` 矩阵 |
| `11_logger.php` | 落盘格式、级别过滤、目录不可写时退回 `error_log()` |
| `12_application.php` | 路径从 `basePath` 推导、未知配置项报错、`handle()` 不经 SAPI、中间件装配顺序、缓存命中 |
| `e2e.php` | 真实请求下的 `$_SERVER` / `php://input` 解析、响应头下发、HEAD 空体、内容协商 |

### 写一个用例

```php
test('路径归一：尾斜杠与重复斜杠', function () {
    $router = makeRouter();
    $router->add('GET', '/ping', fn() => Response::text('pong'));

    assertSame('pong', $router->dispatch('GET', '/ping/')->getContent());
    assertSame('pong', $router->dispatch('GET', '//ping')->getContent());
});
```

- 断言失败抛 `AssertionFailed`，**只影响当前用例**，后面的用例照跑，末尾统一列失败清单
- 失败信息自带调用位置（`[tests/cases/01_router.php:23]`）
- 可用断言：`assertSame` / `assertEquals` / `assertTrue` / `assertFalse` / `assertNull` / `assertCount` / `assertContains` / `assertArrayHasKey` / `assertStringContains` / `assertMatches` / `assertInstanceOf` / `assertGreaterThan` / `assertFileExists` / `assertThrows`（返回异常对象，可继续断言）
- 辅助：`mktmp()` / `rmtree()` / `withAccept()` / `withGlobals()`（临时改 `$_SERVER` / `$_GET` 后自动还原）
- 只想跳过时 `skip('原因')`

### 说明

- 单元测试与端到端测试都**不需要** `vendor/`：`bootstrap/autoload.php` 找不到 `vendor/autoload.php` 时会退回内置 PSR-4 加载器
- 测试期间日志写进系统临时目录，不会污染 `runtime/logs`
- 退出码：全部通过 `0`，有失败 `1`，可直接接 CI

---

## 内置示例接口

启动后可直接访问，用于验证框架各部分是否正常：

| 方法 | 路径 | 演示内容 |
| --- | --- | --- |
| GET | `/api/users` | 查询参数、Header 注入、Request 对象 |
| GET | `/api/user/{id}` | 路径参数与类型转换 |
| POST | `/api/user` | 单 DTO 校验（create 分组、嵌套、自定义消息） |
| POST | `/api/user/batch` | 批量 DTO 校验与 `list.0.*` 错误键 |
| GET | `/demo/page` | `Response::html()` |
| GET | `/demo/go` | `Response::redirect()` |
| GET | `/demo/ping` | `Response::noContent()` |
| GET | `/demo/download` | `Response::download()` |

```bash
# 校验通过
curl -s -X POST http://127.0.0.1:8000/api/user \
  -H 'Content-Type: application/json' \
  -d '{"user":{"username":"alice","age":30}}'

# 校验失败（400，看 errors 的键路径）
curl -s -X POST http://127.0.0.1:8000/api/user \
  -H 'Content-Type: application/json' \
  -d '{"user":{"username":"ab","age":200},"address":{}}'

# 浏览器视角：未匹配路径返回 HTML 错误页而不是 JSON
curl -s http://127.0.0.1:8000/nope -H 'Accept: text/html'
```

---

## 已知限制

- `ValidationException` 返回 **400** 而非 422。改成 422 更符合部分客户端的预期，但会影响既有调用方
- 错误页与异常消息的内建文案是英文（`Route not found`、`Validation failed`），字段级消息可用 `message:` 自定义为中文
- 路由缓存只缓存「定义」，正则编译仍在每次启动时进行；路由规模很大时可进一步把编译结果一并落盘
- `#[Route]` 不可重复，一个方法只能有一个路径；多动词请用 `methods: []`
- 内容协商按 `Accept` 里的**出现位置**判断，不解析 `q` 权重
- 无 PSR-7 / PSR-15 兼容层，中间件签名是本框架自有约定
- 未内置 ORM、模板引擎、i18n
- `composer.json` 同时把 `phpet\` 映射到 `src/`、`app\` 映射到 `app/`。作为**依赖包**安装时，`app\` 这条会与宿主项目自己的 `app\` 争抢；当前分发形态是骨架一体式（`composer create-project`），不存在该问题，将来拆成「框架包 + 骨架包」时需把 `app\` 映射移出框架包

