<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Range
{
    public function __construct(
        public int|float $min = 0,
        public int|float $max = PHP_INT_MAX,
        public array $groups = ['default'],
    ) {}
}