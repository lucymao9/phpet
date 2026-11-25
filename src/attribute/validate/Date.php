<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Date
{
    public function __construct(
        public string $format = 'Y-m-d',
        public array $groups = [],
        public ?string $message = null,
    ) {}
}
