<?php

namespace phpet\core\middleware;

use phpet\core\Request;
use phpet\core\Response;

interface MiddlewareInterface
{
    /**
     * 请求进入控制器之前执行。
     * 返回 Response 表示短路：不再执行后续中间件与控制器，直接进入 after 链。
     */
    public function before(?Request $request): ?Response;

    /**
     * 响应返回客户端之前执行（逆序）。
     * 返回 null 表示不修改响应。
     */
    public function after(?Response $response, ?\Throwable $exception = null): ?Response;
}
