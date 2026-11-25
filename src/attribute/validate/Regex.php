<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Regex
{
    public function __construct(
        public string $pattern,
        public array $groups = [],
        public ?string $message = null,
    ) {}
}
