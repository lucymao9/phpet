<?php

namespace app\middleware;

use phpet\core\middleware\MiddlewareInterface;
use phpet\core\Request;
use phpet\core\Response;

class LogMiddleware implements MiddlewareInterface
{
    public function before(?Request $request)
    {
    }

    public function after(?Response $response, ?\Exception $exception=null)
    {
        return $response;
    }
}