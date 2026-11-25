<?php

namespace phpet\core\Exception;

use Throwable;

/**
 * 错误页渲染：根据 Accept 头在 JSON 与 HTML 之间选择。
 *
 * 浏览器导航请求（Accept 里 text/html 优先）拿到排版后的错误页；
 * curl / 前端 fetch 等（通配符或 application/json）继续拿 JSON。
 */
class ErrorRenderer
{
    private const REASONS = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        413 => 'Payload Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public function __construct(
        private bool $debug = false,
    ) {
    }

    public function wantsHtml(): bool
    {
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');

        if ($accept === '' || $accept === '*/*') {
            return false;
        }

        $html = strpos($accept, 'text/html');
        if ($html === false) {
            return false;
        }

        $json = strpos($accept, 'application/json');

        return $json === false || $html < $json;
    }

    /**
     * @param array<string, mixed> $payload JSON 分支会返回的同一份数据，HTML 里直接复用
     */
    public function html(Throwable $e, int $status, string $requestId, array $payload): string
    {
        $reason = self::REASONS[$status] ?? 'Error';
        $isServerError = $status >= 500;

        // 5xx 在生产环境不暴露具体异常信息
        $message = ($isServerError && !$this->debug)
            ? '服务器内部错误，请稍后重试。'
            : $e->getMessage();

        $details = '';

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $items = '';
            foreach ($errors as $field => $messages) {
                foreach ((array)$messages as $msg) {
                    $items .= '<li><code>' . $this->e($field) . '</code> '
                        . $this->e((string)$msg) . '</li>';
                }
            }
            $details .= '<div class="errors"><div class="label">字段校验失败</div><ul>'
                . $items . '</ul></div>';
        }

        $debugBlock = '';
        if ($this->debug) {
            $debugBlock = '<section class="debug">'
                . '<div class="label">调试信息</div>'
                . '<dl>'
                . '<dt>异常</dt><dd>' . $this->e(get_class($e)) . '</dd>'
                . '<dt>位置</dt><dd>' . $this->e($e->getFile()) . ':' . $e->getLine() . '</dd>'
                . '</dl>'
                . $this->source($e->getFile(), $e->getLine())
                . '<details><summary>调用栈</summary><pre>'
                . $this->e($e->getTraceAsString())
                . '</pre></details>'
                . '</section>';
        }

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $status . ' ' . $this->e($reason) . '</title>'
            . '<style>' . $this->css() . '</style></head><body>'
            . '<main>'
            . '<header><span class="status">' . $status . '</span>'
            . '<h1>' . $this->e($reason) . '</h1></header>'
            . '<p class="message">' . $this->e($message) . '</p>'
            . $details
            . $debugBlock
            . '<footer>请求编号 <code>' . $this->e($requestId) . '</code></footer>'
            . '</main></body></html>';
    }

    private function source(string $file, int $line, int $pad = 6): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

        $lines = @file($file);
        if ($lines === false) {
            return '';
        }

        $total = count($lines);
        $start = max(0, $line - $pad - 1);
        $end = min($total - 1, $line + $pad - 1);

        $rows = '';
        for ($i = $start; $i <= $end; $i++) {
            $no = $i + 1;
            $rows .= '<li' . ($no === $line ? ' class="hit"' : '') . '>'
                . '<span class="no">' . $no . '</span>'
                . '<span class="code">' . $this->e(rtrim($lines[$i])) . '</span>'
                . '</li>';
        }

        return '<div class="label">出错位置</div><ol class="source">' . $rows . '</ol>';
    }

    private function css(): string
    {
        return <<<'CSS'
:root{--bg:#f6f7f9;--card:#fff;--fg:#1f2328;--muted:#6b7280;--line:#e5e7eb;--accent:#b42318;--code:#f3f4f6}
@media (prefers-color-scheme:dark){:root{--bg:#16181d;--card:#1e2027;--fg:#e6e8eb;--muted:#9aa2ae;--line:#2c3038;--accent:#f97066;--code:#262a32}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px 16px;
background:var(--bg);color:var(--fg);font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif}
main{width:100%;max-width:860px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:32px 34px}
header{display:flex;align-items:baseline;gap:14px;margin-bottom:6px}
.status{font-size:15px;font-weight:700;color:var(--accent);letter-spacing:.04em}
h1{font-size:22px;margin:0;font-weight:650}
.message{margin:4px 0 20px;color:var(--muted);word-break:break-word}
.label{margin:22px 0 8px;font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.errors ul{margin:0;padding-left:18px}
.errors li{margin:4px 0}
code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:var(--code);padding:1px 5px;border-radius:4px;font-size:13px}
dl{display:grid;grid-template-columns:64px 1fr;gap:6px 12px;margin:0}
dt{color:var(--muted);font-size:13px}
dd{margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;word-break:break-all}
ol.source{list-style:none;margin:0;padding:0;border:1px solid var(--line);border-radius:8px;overflow:auto;background:var(--code)}
ol.source li{display:flex;gap:12px;padding:1px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;white-space:pre}
ol.source li.hit{background:rgba(180,35,24,.12)}
ol.source .no{color:var(--muted);min-width:34px;text-align:right;user-select:none}
details{margin-top:14px}
summary{cursor:pointer;color:var(--muted);font-size:13px}
pre{margin:10px 0 0;padding:12px 14px;background:var(--code);border-radius:8px;overflow:auto;font-size:12.5px;line-height:1.5}
footer{margin-top:26px;padding-top:16px;border-top:1px solid var(--line);color:var(--muted);font-size:13px}
CSS;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
