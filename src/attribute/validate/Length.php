<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Length
{
    public function __construct(
        public int $min = 0,
        public int $max = PHP_INT_MAX,
        public array $groups = ['default'],
    ) {}
}