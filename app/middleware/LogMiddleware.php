<?php

namespace app\middleware;

use phpet\core\Exception\HttpException;
use phpet\core\Log\Logger;
use phpet\core\middleware\MiddlewareInterface;
use phpet\core\Request;
use phpet\core\Response;

/**
 * 访问日志：记录方法、路径、状态码与耗时。
 */
class LogMiddleware implements MiddlewareInterface
{
    private float $start = 0.0;
    private string $method = '';
    private string $path = '';

    public function before(?Request $request): ?Response
    {
        $this->start = microtime(true);
        $this->method = $request?->method() ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = $request?->path() ?? ($_SERVER['REQUEST_URI'] ?? '/');

        return null;
    }

    public function after(?Response $response, ?\Throwable $exception = null): ?Response
    {
        // 异常在 Router 层才被转成 Response，这里从异常推断状态码
        $status = $response?->getStatus()
            ?? ($exception instanceof HttpException ? $exception->getStatus() : 500);

        Logger::info('request', [
            'method' => $this->method,
            'path' => $this->path,
            'status' => $status,
            'ms' => round((microtime(true) - $this->start) * 1000, 2),
            'error' => $exception?->getMessage(),
        ]);

        return $response;
    }
}
