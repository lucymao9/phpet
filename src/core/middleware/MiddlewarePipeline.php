<?php

namespace phpet\core\middleware;

use phpet\core\Request;
use phpet\core\Response;

/**
 * 中间件管道：按顺序执行 before()，逆序执行 after()。
 *
 * before() 返回 Response 视为短路，跳过剩余中间件与核心逻辑。
 * 无论核心逻辑成功或抛异常，已执行 before() 的中间件都会收到 after()。
 */
class MiddlewarePipeline
{
    public function __construct(
        private MiddlewareRegistry $registry,
        private Request            $request,
    ) {
    }

    /**
     * @param string[] $names 中间件名称，按注册顺序执行
     * @param callable $core  核心逻辑，需返回 Response 或可转成 Response 的值
     */
    public function handle(array $names, callable $core): Response
    {
        /** @var MiddlewareInterface[] $instances 只包含 before() 已执行过的中间件 */
        $instances = [];
        $result = null;
        $exception = null;

        try {
            foreach ($names as $name) {
                $middleware = $this->registry->resolve($name);
                $instances[] = $middleware;

                $shortCircuit = $middleware->before($this->request);
                if ($shortCircuit instanceof Response) {
                    $result = $shortCircuit;
                    break;
                }
            }

            if ($result === null) {
                $result = $this->toResponse($core());
            }
        } catch (\Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            foreach (array_reverse($instances) as $middleware) {
                $after = $middleware->after($result, $exception);
                // 约定返回 null 表示中间件不改写响应
                if ($after !== null) {
                    $result = $after;
                }
            }
        }

        return $result;
    }

    private function toResponse($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return Response::json((array)$result);
        }

        if ($result === null) {
            return Response::text('');
        }

        return Response::text((string)$result);
    }
}
