<?php

namespace phpet\core;

use phpet\core\Log\Logger;
use Throwable;

/**
 * 路由缓存：把扫描出的路由定义落盘，避免每次请求都反射全部控制器。
 *
 * 缓存有效性由「源文件签名」判定：目录下所有 PHP 文件的路径 + mtime + size
 * 参与哈希，任一文件改动都会让缓存失效并自动重建。不需要手动清理。
 *
 * 缓存文件是 PHP 源码（`return [...]`），可被 opcache 缓存，
 * 且落在 runtime/ 下，位于 web 根之外。
 */
class RouteCache
{
    /**
     * 缓存结构版本。路由定义的字段发生变化时自增，让旧缓存自动失效。
     */
    private const VERSION = 2;

    public function __construct(
        private string $path,
    ) {
    }

    /**
     * 默认缓存路径。由项目根推导，与框架自身的安装位置无关
     * （装在 vendor/ 下时也同样正确）。
     */
    public static function defaultPath(string $basePath): string
    {
        return rtrim($basePath, '/\\') . '/runtime/cache/routes.php';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * 计算源目录签名。只做 stat，不加载任何类。
     *
     * @return array{files:int, hash:string}
     */
    public function signature(string $sourceDir): array
    {
        $dir = rtrim($sourceDir, '/\\');
        if (!is_dir($dir)) {
            return ['files' => 0, 'hash' => ''];
        }

        $entries = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $entries[$file->getPathname()] = $file->getMTime() . ':' . $file->getSize();
        }

        if ($entries === []) {
            return ['files' => 0, 'hash' => ''];
        }

        // 固定顺序，避免迭代顺序不同导致签名抖动
        ksort($entries);

        return [
            'files' => count($entries),
            'hash'  => md5(implode('|', $entries)),
        ];
    }

    /**
     * 缓存是否可用：文件存在、可读、版本匹配、签名一致、且确实有路由。
     */
    public function isFresh(array $signature): bool
    {
        // 目录空/不存在时绝不使用缓存，交给扫描阶段抛错
        if (($signature['files'] ?? 0) === 0) {
            return false;
        }

        if (!is_file($this->path) || !is_readable($this->path)) {
            return false;
        }

        $data = $this->read();

        if ($data === null) {
            return false;
        }

        return ($data['version'] ?? null) === self::VERSION
            && ($data['signature'] ?? null) === $signature
            && is_array($data['routes'] ?? null)
            && $data['routes'] !== [];
    }

    /**
     * 读取缓存中的路由定义；不可用时返回空数组。
     *
     * @return array<int, array{path:string, methods:string[], controller:string, action:string, middlewares:string[]}>
     */
    public function load(): array
    {
        $data = $this->read();

        return is_array($data['routes'] ?? null) ? $data['routes'] : [];
    }

    /**
     * 写入缓存。先写临时文件再 rename，避免并发下读到半截内容。
     */
    public function save(array $signature, array $routes): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            // 缓存只是优化，写不进去不影响请求，记一笔就够了
            Logger::warning('Route cache directory not writable', ['dir' => $dir]);
            return false;
        }

        $data = [
            'version'   => self::VERSION,
            'signature' => $signature,
            'routes'    => $routes,
        ];

        $code = "<?php\n"
            . "// 由 phpet 自动生成，请勿手动修改。删除本文件可强制重建。\n"
            . "return " . var_export($data, true) . ";\n";

        $tmp = $this->path . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
            Logger::warning('Route cache write failed', ['file' => $tmp]);
            return false;
        }

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            Logger::warning('Route cache rename failed', ['file' => $this->path]);
            return false;
        }

        return true;
    }

    public function clear(): bool
    {
        return !is_file($this->path) || @unlink($this->path);
    }

    /**
     * @return array<string, mixed>|null 结构损坏或语法错误时返回 null
     */
    private function read(): ?array
    {
        try {
            $data = include $this->path;
        } catch (Throwable $e) {
            // 缓存被改坏（语法错误等）不应该拖垮请求，直接当作失效
            Logger::warning('Route cache unreadable, will rebuild', [
                'file'  => $this->path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
