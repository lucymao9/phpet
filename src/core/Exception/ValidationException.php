<?php

namespace phpet\core\Exception;

class ValidationException extends BadRequestException
{
    public function __construct(
        public array $errors
    ) {
        parent::__construct('Validation failed');
    }
}