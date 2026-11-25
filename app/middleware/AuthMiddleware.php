<?php

namespace app\middleware;

use phpet\core\middleware\MiddlewareInterface;
use phpet\core\Request;
use phpet\core\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function before(?Request $request): ?Response
    {
        // 示例：校验通过返回 null 继续；需要拦截时返回 Response 短路
        // $token = $request?->header('authorization');
        // if ($token === null) {
        //     return Response::json(['message' => 'Unauthorized'])->status(401);
        // }
        return null;
    }

    public function after(?Response $response, ?\Throwable $exception = null): ?Response
    {
        return $response;
    }
}
