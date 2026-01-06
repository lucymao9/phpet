<?php

namespace app\dto;

use phpet\attribute\validate\Range;
use phpet\attribute\validate\Required;

class IdsDto
{
    #[Each(
        rules: [
            new Required(),
            new Range(min: 1)
        ]
    )]
    public ?array $ids = null;
}