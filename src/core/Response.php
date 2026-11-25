<?php

namespace phpet\core;

use phpet\core\Log\Logger;

class Response
{
    protected int $status = 200;
    protected array $headers = [];
    protected string $content = '';

    public static function text(string $content): self
    {
        $res = new self();
        $res->content = $content;
        $res->headers['Content-Type'] = 'text/plain; charset=utf-8';
        return $res;
    }

    public static function json($data): self
    {
        $res = new self();

        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            Logger::error('Response json encode failed: ' . json_last_error_msg());
            $res->content = '{"error":"Internal Server Error"}';
            $res->headers['Content-Type'] = 'application/json';
            return $res->status(500);
        }

        $res->content = $encoded;
        $res->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $res;
    }

    public function status(int $code): self
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function send(): void
    {
        // 先清掉控制器里意外的直接输出（echo/var_dump），
        // 否则会提前发送响应头，导致状态码和 Content-Type 都改不动
        $this->discardStrayOutput();

        // 状态码与响应头必须在任何输出之前设置
        http_response_code($this->status);

        foreach ($this->headers as $key => $value) {
            header("{$key}: {$value}");
        }

        // HEAD 与 204/304 不返回响应体
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (strtoupper($method) === 'HEAD' || in_array($this->status, [204, 304], true)) {
            return;
        }

        echo $this->content;
    }

    private function discardStrayOutput(): void
    {
        $stray = '';
        while (ob_get_level() > 0) {
            $chunk = ob_get_clean();
            if ($chunk === false) {
                break;
            }
            $stray .= $chunk;
        }

        if ($stray !== '') {
            Logger::warning('Discarded stray output before response', [
                'length' => strlen($stray),
                'preview' => mb_substr($stray, 0, 200),
            ]);
        }
    }
}
