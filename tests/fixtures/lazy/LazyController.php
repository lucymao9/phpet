<?php
declare(strict_types=1);

namespace fixtures\lazy;

use phpet\attribute\Controller;
use phpet\attribute\Route;
use phpet\core\Response;

/**
 * 只在「从缓存定义注册」的测试里出现，永远不该被真正加载。
 *
 * 单独放一个目录，是为了让扫描测试不会顺手把它 class_exists 掉，
 * 否则「控制器按需实例化」这条断言就没法验证了。
 */
#[Controller('/lazy')]
class LazyController
{
    #[Route('/never', methods: ['GET'])]
    public function never(): Response
    {
        return Response::json(['should' => 'not be loaded']);
    }
}
