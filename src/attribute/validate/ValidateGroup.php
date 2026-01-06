<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ValidateGroup
{
    public function __construct(
        public string $group,
    ) {}
}