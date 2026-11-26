<?php
declare(strict_types=1);

namespace fixtures\controllers;

/**
 * 有类无 #[Controller] 注解：扫描时应当被跳过。
 */
class PlainClass
{
    public function index(): string
    {
        return 'plain';
    }
}
