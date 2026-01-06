<?php

namespace phpet\core\Exception;

use phpet\core\Response;
use Throwable;

class ExceptionHandler
{
    public static function handle(Throwable $e): Response
    {
        file_put_contents('runtime.log', '['.date('Y-m-d H:i:s').']'.$e->getMessage().' on line '.$e->getLine().' in '.$e->getFile().$e->getTraceAsString().PHP_EOL, FILE_APPEND);

        if ($e instanceof ValidationException) {
            return Response::json([
                'message' => $e->getMessage(),
                'errors' => $e->errors
            ])->status(400);
        }

        if ($e instanceof HttpException) {
            return Response::json([
                'message' => $e->getMessage()
            ])->status($e->getStatus());
        }
        // 未知异常
        return Response::json([
            'error' => 'Internal Server Error'
        ])->status(500);
    }
}