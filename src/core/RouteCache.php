<?php

namespace phpet\core;

use phpet\cache\CacheInterface;
use phpet\core\Log\Logger;
use Throwable;

/**
 * 路由表缓存：把扫描出的路由定义存进缓存，避免每次请求都反射全部控制器。
 *
 * 存储完全交给 CacheInterface，本类只负责回答「这份数据还算不算新鲜」：
 * 源文件签名 + 结构版本。换驱动（文件 → Redis）不需要动这里，
 * 也不需要动 Application——这正是把它收敛到 CacheInterface 的意义。
 *
 * 值的形状是 ['version' => int, 'signature' => array, 'routes' => array]，
 * 和缓存键无关，所以键可以是一个稳定的常量。
 */
class RouteCache
{
    /**
     * 缓存结构版本。路由定义的字段发生变化时自增，让旧缓存自动失效。
     *
     * 版本号放在**值**里而不是键里：升级后新值会直接覆写旧值，
     * 不会留下一批永远读不到、也没人负责清理的孤儿键。
     */
    private const VERSION = 3;

    /**
     * 默认键名。不含 PSR-16 保留字符，换任何驱动都能用。
     */
    public const DEFAULT_KEY = 'route.table';

    private string $key;

    public function __construct(
        private CacheInterface $cache,
        string $key = self::DEFAULT_KEY,
    ) {
        $this->key = $key;
    }

    public function key(): string
    {
        return $this->key;
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
     * 缓存是否可用：命中、版本匹配、签名一致、且确实有路由。
     */
    public function isFresh(array $signature): bool
    {
        // 目录空/不存在时绝不使用缓存，交给扫描阶段抛错
        if (($signature['files'] ?? 0) === 0) {
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
     * 写入缓存，不带 TTL——失效靠签名判定，不靠时间。
     */
    public function save(array $signature, array $routes): bool
    {
        $ok = $this->cache->set($this->key, [
            'version'   => self::VERSION,
            'signature' => $signature,
            'routes'    => $routes,
        ]);

        if (!$ok) {
            // 缓存只是优化，写不进去不影响请求，记一笔就够了
            Logger::warning('Route cache write failed', ['key' => $this->key]);
        }

        return $ok;
    }

    public function clear(): bool
    {
        return $this->cache->delete($this->key);
    }

    /**
     * @return array<string, mixed>|null 未命中或结构不对时返回 null
     */
    private function read(): ?array
    {
        try {
            $data = $this->cache->get($this->key);
        } catch (Throwable $e) {
            // 驱动出问题（文件被改坏、Redis 连不上）不应该拖垮请求，当作失效即可
            Logger::warning('Route cache unreadable, will rebuild', [
                'key'   => $this->key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return is_array($data) ? $data : null;
    }
}
