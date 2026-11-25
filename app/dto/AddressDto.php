<?php

namespace app\dto;

use phpet\attribute\validate\Length;
use phpet\attribute\validate\Required;

class AddressDto
{
    #[Required]
    public ?string $city = null;

    #[Length(min: 5, max: 6)]
    public ?string $zip = null;
}