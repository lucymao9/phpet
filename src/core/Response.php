<?php

namespace phpet\core;

use phpet\core\Exception\NotFoundException;
use phpet\core\Log\Logger;

class Response
{
    protected int $status = 200;
    protected array $headers = [];
    protected string $content = '';

    /** 需要流式输出的文件路径（大文件不读进内存） */
    protected ?string $filePath = null;

    public static function text(string $content, int $status = 200): self
    {
        $res = new self();
        $res->content = $content;
        $res->headers['Content-Type'] = 'text/plain; charset=utf-8';
        return $res->status($status);
    }

    public static function json($data, int $status = 200): self
    {
        $res = new self();
        $res->headers['Content-Type'] = 'application/json; charset=utf-8';

        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            Logger::error('Response json encode failed: ' . json_last_error_msg());
            $res->content = '{"error":"Internal Server Error"}';
            return $res->status(500);
        }

        $res->content = $encoded;
        return $res->status($status);
    }

    public static function html(string $content, int $status = 200): self
    {
        $res = new self();
        $res->content = $content;
        $res->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $res->status($status);
    }

    public static function noContent(): self
    {
        return (new self())->status(204);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return (new self())
            ->header('Location', $url)
            ->status($status);
    }

    /**
     * 输出文件内容（浏览器内联展示，如图片、PDF）。
     */
    public static function file(string $path, ?string $contentType = null): self
    {
        return self::fileResponse($path, $contentType, null);
    }

    /**
     * 触发下载。
     */
    public static function download(string $path, ?string $filename = null): self
    {
        return self::fileResponse($path, null, $filename ?? basename($path));
    }

    private static function fileResponse(
        string  $path,
        ?string $contentType,
        ?string $filename
    ): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new NotFoundException('File not found');
        }

        $res = new self();
        $res->filePath = $path;
        $detected = function_exists('mime_content_type') ? mime_content_type($path) : null;
        $res->headers['Content-Type'] = $contentType
            ?? ($detected ?: 'application/octet-stream');
        $res->headers['Content-Length'] = (string)filesize($path);

        if ($filename !== null) {
            $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename);
            $res->headers['Content-Disposition'] = sprintf(
                "attachment; filename=\"%s\"; filename*=UTF-8''%s",
                $fallback,
                rawurlencode($filename)
            );
        }

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

        if ($this->filePath !== null) {
            readfile($this->filePath);
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
