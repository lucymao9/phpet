<?php

namespace phpet\core\Exception;

use phpet\core\Log\Logger;
use phpet\core\Response;
use Throwable;

class ExceptionHandler
{
    private ErrorRenderer $renderer;

    public function __construct(
        private bool $debug = false,
    ) {
        $this->renderer = new ErrorRenderer($debug);
    }

    public function handle(Throwable $e): Response
    {
        $requestId = $this->requestId();
        $status = $e instanceof HttpException ? $e->getStatus() : 500;

        $this->log($e, $status, $requestId);

        $payload = $this->payload($e, $status, $requestId);

        // 浏览器导航请求给排版后的错误页，接口调用继续返回 JSON
        $response = $this->renderer->wantsHtml()
            ? Response::html($this->renderer->html($e, $status, $requestId, $payload), $status)
            : Response::json($payload, $status);

        // 405 需要告诉客户端该路径支持哪些方法
        if ($e instanceof MethodNotAllowedException && $e->getAllowed() !== []) {
            $response->header('Allow', implode(', ', $e->getAllowed()));
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Throwable $e, int $status, string $requestId): array
    {
        if ($e instanceof ValidationException) {
            return [
                'message' => $e->getMessage(),
                'errors' => $e->errors,
            ];
        }

        if ($e instanceof HttpException) {
            return ['message' => $e->getMessage()];
        }

        $payload = [
            'error' => 'Internal Server Error',
            'requestId' => $requestId,
        ];

        // 仅在 debug 模式下透出异常细节，避免生产泄露路径与堆栈
        if ($this->debug) {
            $payload['exception'] = get_class($e);
            $payload['message'] = $e->getMessage();
            $payload['file'] = $e->getFile() . ':' . $e->getLine();
        }

        return $payload;
    }

    private function log(Throwable $e, int $status, string $requestId): void
    {
        // 4xx 属于正常业务流，按 warning 记录且不带堆栈；5xx 才算 error
        $context = [
            'requestId' => $requestId,
            'exception' => get_class($e),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ];
        if ($status >= 500) {
            $context['trace'] = $e->getTraceAsString();
        }

        Logger::log(
            $status >= 500 ? Logger::ERROR : Logger::WARNING,
            $e->getMessage(),
            $context
        );
    }

    private function requestId(): string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
    }
}
