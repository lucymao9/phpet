<?php

namespace app\middleware;

use phpet\core\Request;
use phpet\core\Response;

class CorsMiddleware implements \phpet\core\middleware\MiddlewareInterface
{
    private array $headers = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Max-Age' => '86400',
    ];

    public function before(?Request $request)
    {
        // 预检请求直接返回
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            foreach ($this->headers as $k => $v) {
                header("$k: $v");
            }

            http_response_code(204);
            exit;
        }
    }

    public function after(?Response $response, ?\Throwable $exception=null)
    {
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        return $response;
    }
}