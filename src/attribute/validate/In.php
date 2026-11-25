<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class In
{
    public function __construct(
        public array $values,
        public array $groups = [],
        public ?string $message = null,
    ) {}
}
