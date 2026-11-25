<?php

namespace app\controller;

use app\dto\CreateUserDto;
use phpet\attribute\Controller;
use phpet\attribute\Middleware;
use phpet\attribute\request\FromEach;
use phpet\attribute\request\FromHeader;
use phpet\attribute\request\FromQuery;
use phpet\attribute\Route;
use phpet\attribute\validate\ValidateGroup;
use phpet\core\Request;
use phpet\core\Response;

#[Middleware('auth')]
#[Middleware('log')]
#[Controller('/api')]
class UserController
{
    #[Route('/users', methods: ['GET'])]
    public function index(
        Request $request,
        #[FromQuery] string $gender = '',
        #[FromHeader('Host')] string $host = '',
    ) {
        return Response::json([
            'gender' => $gender,
            'host' => $host,
            'request' => $request->getQuery(),
        ])->status(200);
    }

    #[Route('/user/{id}', methods: ['GET'])]
    public function show(int $id)
    {
        return Response::json(['id' => $id])->status(200);
    }

    #[Route('/user', methods: ['POST'])]
    public function add(
        #[ValidateGroup('create')] CreateUserDto $dto
    ) {
        return Response::json(['dto' => $dto])->status(200);
    }

    #[Route('/user/batch', methods: ['POST'])]
    public function batchAdd(
        #[ValidateGroup('create')] #[FromEach(CreateUserDto::class)] array $list
    ) {
        return Response::json(['list' => $list])->status(200);
    }
}
