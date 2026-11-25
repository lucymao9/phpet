<?php

namespace app\controller;

use phpet\attribute\Controller;
use phpet\attribute\request\FromQuery;
use phpet\attribute\Route;
use phpet\core\Response;

/**
 * 响应助手示例：页面、重定向、204、文件下载。
 */
#[Controller('/demo')]
class DemoController
{
    #[Route('/page', methods: ['GET'])]
    public function page(#[FromQuery] string $name = 'world')
    {
        // 简单转义，避免把用户输入直接拼进 HTML
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        return Response::html(
            '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<title>Demo</title></head><body style="font:16px system-ui;padding:40px">'
            . '<h1>Hello, ' . $name . '</h1>'
            . '<p>这是由 Response::html() 渲染的页面。</p>'
            . '</body></html>'
        );
    }

    #[Route('/go', methods: ['GET'])]
    public function go()
    {
        return Response::redirect('/demo/page?name=redirected');
    }

    #[Route('/ping', methods: ['GET'])]
    public function ping()
    {
        return Response::noContent();
    }

    #[Route('/download', methods: ['GET'])]
    public function download()
    {
        return Response::download(
            dirname(__DIR__, 2) . '/README.md',
            'phpet-README.md'
        );
    }
}
