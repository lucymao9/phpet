<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Integer
{
    public function __construct(
        public array $groups = [],
        public ?string $message = null,
    ) {}
}
