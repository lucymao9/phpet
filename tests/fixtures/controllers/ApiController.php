<?php
declare(strict_types=1);

namespace fixtures\controllers;

use fixtures\ApiDto;
use phpet\attribute\Controller;
use phpet\attribute\Middleware;
use phpet\attribute\request\FromQuery;
use phpet\attribute\Route;
use phpet\attribute\validate\ValidateGroup;
use phpet\core\Response;

/**
 * 供控制器扫描测试使用。
 *
 * 命名空间与目录必须符合 PSR-4：
 * scanDirectory() 是按文件路径推导类名再 class_exists() 的。
 */
#[Controller('/api')]
#[Middleware('rec')]
class ApiController
{
    #[Route('/items/{id}', methods: ['GET'])]
    public function show(int $id): Response
    {
        return Response::json(['id' => $id]);
    }

    #[Route('/items', methods: ['POST'])]
    public function create(#[ValidateGroup('create')] ApiDto $dto): Response
    {
        return Response::json(['title' => $dto->title]);
    }

    #[Route('/echo', methods: ['GET'])]
    public function echoQuery(#[FromQuery] string $q = ''): Response
    {
        return Response::json(['q' => $q]);
    }

    #[Route('/guarded', methods: ['GET'])]
    #[Middleware('block')]
    public function guarded(): Response
    {
        return Response::json(['ok' => true]);
    }
}
