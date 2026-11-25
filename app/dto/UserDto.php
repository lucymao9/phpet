<?php

namespace app\dto;

use phpet\attribute\validate\Date;
use phpet\attribute\validate\Email;
use phpet\attribute\validate\In;
use phpet\attribute\validate\Length;
use phpet\attribute\validate\Range;
use phpet\attribute\validate\Regex;
use phpet\attribute\validate\Required;

class UserDto
{
    #[Required(groups: ['create'], message: '用户名不能为空')]
    #[Length(min: 3, max: 20, groups: ['create', 'update'], message: '用户名长度需为 {min}-{max} 个字符，当前 {length} 个')]
    public ?string $username = null;

    #[Required(groups: ['create'])]
    #[Range(min: 1, max: 120, groups: ['create', 'update'])]
    public ?int $age = null;

    // 以下均为可选字段，用于演示新增的校验规则
    #[Email]
    public ?string $email = null;

    #[In(values: ['user', 'admin'])]
    public ?string $role = null;

    #[Date(format: 'Y-m-d')]
    public ?string $birthday = null;

    #[Regex(pattern: '/^1\d{10}$/', message: '手机号格式不正确')]
    public ?string $phone = null;
}
