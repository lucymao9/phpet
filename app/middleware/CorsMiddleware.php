<?php

namespace app\middleware;

use phpet\core\middleware\MiddlewareInterface;
use phpet\core\Request;
use phpet\core\Response;

/**
 * 跨域中间件。
 *
 * 注意：Access-Control-Allow-Origin 与 Access-Control-Allow-Credentials 不能
 * 同时用通配符。需要携带 Cookie 时，把 $origins 配成具体域名并开启 credentials，
 * 中间件会自动回显请求的 Origin。
 */
class CorsMiddleware implements MiddlewareInterface
{
    private ?string $origin = null;

    public function __construct(
        private array $origins = ['*'],
        private bool  $credentials = false,
        private array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $headers = ['Content-Type', 'Authorization', 'X-Request-Id'],
        private int   $maxAge = 86400,
    ) {
    }

    public function before(?Request $request): ?Response
    {
        $this->origin = $request?->header('origin');

        // 预检请求直接短路返回 204，不再进入控制器
        $method = $request?->method() ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (strtoupper($method) === 'OPTIONS') {
            $preflight = (new Response())->status(204);
            foreach ($this->buildHeaders() as $k => $v) {
                $preflight->header($k, $v);
            }
            return $preflight;
        }

        return null;
    }

    public function after(?Response $response, ?\Throwable $exception = null): ?Response
    {
        $headers = $this->buildHeaders();

        if ($response instanceof Response) {
            foreach ($headers as $k => $v) {
                $response->header($k, $v);
            }
            return $response;
        }

        foreach ($headers as $k => $v) {
            header("{$k}: {$v}");
        }
        return $response;
    }

    private function buildHeaders(): array
    {
        $headers = [];
        $allowOrigin = $this->resolveAllowOrigin();

        if ($allowOrigin !== null) {
            $headers['Access-Control-Allow-Origin'] = $allowOrigin;
            if ($allowOrigin !== '*') {
                $headers['Vary'] = 'Origin';
            }
            if ($this->credentials) {
                $headers['Access-Control-Allow-Credentials'] = 'true';
            }
        }

        $headers['Access-Control-Allow-Methods'] = implode(', ', $this->methods);
        $headers['Access-Control-Allow-Headers'] = implode(', ', $this->headers);
        $headers['Access-Control-Max-Age'] = (string)$this->maxAge;

        return $headers;
    }

    /**
     * 返回 null 表示不输出 CORS 来源头。
     * credentials 为 true 时绝不使用通配符，改为回显具体 Origin。
     */
    private function resolveAllowOrigin(): ?string
    {
        $wildcard = in_array('*', $this->origins, true);

        if ($wildcard && !$this->credentials) {
            return '*';
        }

        $origin = $this->origin;
        if ($origin === null) {
            return null;
        }

        if ($wildcard || in_array($origin, $this->origins, true)) {
            return $origin;
        }

        return null;
    }
}
