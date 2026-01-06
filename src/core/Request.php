<?php

namespace phpet\core;

class Request
{
    public function __construct(
        private array $query,
        private array $body,
        private array $server,
        private array $headers
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[
                str_replace('_', '-', strtolower(substr($key, 5)))
                ] = $value;
            }
        }

        $rawBody = file_get_contents('php://input');
        $body = json_decode($rawBody, true) ?? $_POST;

        return new self($_GET, $body, $_SERVER, $headers);
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function body(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    public function header(string $key, $default = null)
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function method(): string
    {
        return $this->server['REQUEST_METHOD'];
    }

    public function path(): string
    {
        return parse_url($this->server['REQUEST_URI'], PHP_URL_PATH);
    }

    public function getQuery(): array
    {
        return $this->query;
    }
}