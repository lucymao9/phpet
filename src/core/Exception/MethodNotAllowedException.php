<?php

namespace phpet\core\Exception;

class MethodNotAllowedException extends HttpException
{
    /**
     * @param string[] $allowed 该路径允许的 HTTP 方法
     */
    public function __construct(
        private array $allowed = [],
        string $message = 'Method Not Allowed'
    ) {
        parent::__construct($message, 405);
    }

    /**
     * @return string[]
     */
    public function getAllowed(): array
    {
        return $this->allowed;
    }
}
