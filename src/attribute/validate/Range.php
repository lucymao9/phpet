<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Range
{
    public function __construct(
        public int $min,
        public int $max,
        public array $groups = ['default'],
    ) {}
}