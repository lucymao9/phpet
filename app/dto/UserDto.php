<?php

namespace app\dto;

use phpet\attribute\validate\Length;
use phpet\attribute\validate\Range;
use phpet\attribute\validate\Required;

class UserDto
{
    #[Required(groups:['create'])]
    #[Length(min: 3, max: 20, groups:['create','update'])]
    public ?string $username = null;

    #[Required(groups:['create'])]
    #[Range(min: 1, max: 120, groups:['create','update'])]
    public ?int $age = null;
}