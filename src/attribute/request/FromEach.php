<?php

namespace phpet\attribute\request;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class FromEach
{
    public function __construct(
        public ?string $dto = null,
        public ?string $name = null,
    ) {}
}