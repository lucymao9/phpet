<?php

namespace phpet\attribute\request;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class FromCookie
{
    public function __construct(
        public ?string $name = null,
    ) {}
}