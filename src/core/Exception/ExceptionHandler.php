<?php

namespace phpet\core\Exception;

use phpet\core\Log\Logger;
use phpet\core\Response;
use Throwable;

class ExceptionHandler
{
    public function __construct(
        private bool $debug = false,
    ) {
    }

    public function handle(Throwable $e): Response
    {
        $requestId = $this->requestId();
        $status = $e instanceof HttpException ? $e->getStatus() : 500;

        // 4xx 属于正常业务流，按 warning 记录且不带堆栈；5xx 才算 error
        $context = [
            'requestId' => $requestId,
            'exception' => get_class($e),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ];
        if ($status >= 500) {
            $context['trace'] = $e->getTraceAsString();
        }

        Logger::log($status >= 500 ? Logger::ERROR : Logger::WARNING, $e->getMessage(), $context);

        if ($e instanceof ValidationException) {
            return Response::json([
                'message' => $e->getMessage(),
                'errors' => $e->errors
            ])->status(400);
        }

        if ($e instanceof HttpException) {
            $response = Response::json([
                'message' => $e->getMessage()
            ])->status($e->getStatus());

            // 405 需要告诉客户端该路径支持哪些方法
            if ($e instanceof MethodNotAllowedException && $e->getAllowed() !== []) {
                $response->header('Allow', implode(', ', $e->getAllowed()));
            }

            return $response;
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

        return Response::json($payload)->status(500);
    }

    private function requestId(): string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
    }
}
