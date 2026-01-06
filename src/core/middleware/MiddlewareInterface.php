<?php

namespace phpet\core\middleware;

use phpet\core\Request;
use phpet\core\Response;

interface MiddlewareInterface
{
    public function before(?Request $request);
    public function after(?Response $response, ?\Throwable $exception=null);
}