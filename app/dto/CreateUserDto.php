<?php

namespace app\dto;

use phpet\attribute\validate\Valid;
use phpet\core\BaseDto;

class CreateUserDto extends BaseDto
{
    #[Valid]
    public ?UserDto $user = null;

    #[Valid]
    public ?AddressDto $address = null;

}