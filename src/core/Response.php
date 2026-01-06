<?php

namespace phpet\core;

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

    public static function json(array $data): self
    {
        $res = new self();

        $res->content = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $res->headers['Content-Type'] = 'application/json';
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

    public function send(): void
    {
        foreach ($this->headers as $key => $value) {
            header("{$key}: {$value}");
        }
//        file_put_contents('debug.log', $this->content.PHP_EOL, FILE_APPEND);
        echo $this->content;
        http_response_code($this->status);
    }
}