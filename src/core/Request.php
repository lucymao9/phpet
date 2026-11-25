<?php

namespace phpet\core;

use phpet\core\Exception\BadRequestException;

class Request
{
    public function __construct(
        private array $query,
        private array $body,
        private array $server,
        private array $headers,
        private array $files = []
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

        // CONTENT_TYPE / CONTENT_LENGTH 不带 HTTP_ 前缀，需要单独兜底，
        // 否则 FromHeader('Content-Type') 永远取不到值
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
            if (isset($_SERVER[$key]) && !isset($headers[strtolower(str_replace('_', '-', $key))])) {
                $headers[strtolower(str_replace('_', '-', $key))] = $_SERVER[$key];
            }
        }

        $rawBody = file_get_contents('php://input');

        if (str_contains($headers['content-type'] ?? '', 'json')) {
            if (trim((string)$rawBody) === '') {
                $body = [];
            } else {
                $body = json_decode((string)$rawBody, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new BadRequestException('Malformed JSON body: ' . json_last_error_msg());
                }
                if (!is_array($body)) {
                    throw new BadRequestException('JSON body must be an object or array');
                }
            }
        } else {
            $body = $_POST;
        }

        return new self($_GET, $body, $_SERVER, $headers, $_FILES);
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
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = (string)($this->server['REQUEST_URI'] ?? '/');

        // 不用 parse_url：`//api/x` 会被当成 host=api、path=/x
        $path = explode('?', $uri, 2)[0];
        $path = explode('#', $path, 2)[0];

        return $path === '' ? '/' : $path;
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * 获取完整请求体（已解析的 JSON 或表单数据）
     */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * 获取全部请求头，键名统一小写
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type', ''), 'json');
    }

    public function accept(): string
    {
        return (string)$this->header('accept', '');
    }

    /**
     * 客户端是否接受指定类型，如 accepts('application/json')。
     */
    public function accepts(string $type): bool
    {
        $accept = strtolower($this->accept());

        return $accept !== ''
            && (str_contains($accept, strtolower($type)) || str_contains($accept, '*/*'));
    }

    /**
     * 是否希望拿到 JSON。
     *
     * 显式声明 text/html 且把 JSON 排在后面的（浏览器导航请求）返回 false；
     * curl 这类只发通配符的按 JSON 处理。
     */
    public function wantsJson(): bool
    {
        $accept = strtolower($this->accept());

        if ($accept === '' || $accept === '*/*') {
            return true;
        }

        $html = strpos($accept, 'text/html');
        if ($html === false) {
            return true;
        }

        $json = strpos($accept, 'application/json');

        return $json !== false && $json < $html;
    }

    /**
     * 统一取值：先查请求体，再查 query。
     */
    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /**
     * 单个上传文件的原始信息（$_FILES 结构），没有则返回 null。
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * @return array<string, array>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key])
            && ($this->files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * 客户端 IP。默认只信任 REMOTE_ADDR；
     * 确实部署在反向代理后面时才传 $trustProxy = true 读取 X-Forwarded-For。
     */
    public function clientIp(bool $trustProxy = false): string
    {
        if ($trustProxy) {
            $forwarded = $this->header('x-forwarded-for');
            if (is_string($forwarded) && $forwarded !== '') {
                return trim(explode(',', $forwarded)[0]);
            }
        }

        return (string)($this->server['REMOTE_ADDR'] ?? '');
    }
}
