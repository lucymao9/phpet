<?php

namespace phpet\attribute\validate;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Each
{
    public function __construct(
        public ?string $dto = null,
        public array $rules = []
    ) {}
}