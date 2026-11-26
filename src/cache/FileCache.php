<?php

namespace phpet\cache;

/**
 * 文件缓存。零依赖，适合单机与中小流量。
 *
 * 存法：一个键一个文件，内容是 serialize() 的数组（键名、过期时间、值）。
 * 不用 var_export 是因为它不支持对象；不用 json 是因为它会把 int/float、
 * 关联数组和对象压平，取回来类型就不对了。
 *
 * 写入走「临时文件 + rename」，rename 在同一文件系统上是原子的，
 * 所以并发下不会读到写了一半的文件。
 *
 * 过期文件靠读时惰性删除；长期没人读的键会滞留，用 prune() 定期清理。
 */
class FileCache implements CacheInterface
{
    private const EXT = '.cache';

    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $record = $this->read($key);

        return $record === null ? $default : $record['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        return $this->write($key, $value, self::expiryOf($ttl));
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        $file = $this->path($key);

        if (!is_file($file)) {
            // PSR-16：删除不存在的键也算成功
            return true;
        }

        return @unlink($file);
    }

    public function clear(): bool
    {
        foreach ($this->files() as $file) {
            if (!@unlink($file)) {
                return false;
            }
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $key = (string)$key;
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $expiresAt = self::expiryOf($ttl);
        $ok = true;

        foreach ($values as $key => $value) {
            $key = (string)$key;

            if (!$this->write($key, $value, $expiresAt)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ($keys as $key) {
            if (!$this->delete((string)$key)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return $this->read($key) !== null;
    }

    /**
     * 删除已经过期的文件，返回删除个数。
     *
     * 不传 $now 便于测试；生产上配合定时任务跑即可，不跑也只是占点磁盘。
     */
    public function prune(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;

        foreach ($this->files() as $file) {
            $record = $this->readFile($file);

            if ($record === null || $record['expires'] === null) {
                continue;
            }

            if ($record['expires'] <= $now && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /* ------------------------------------------------------------------ */

    private function path(string $key): string
    {
        // 键可能含任意字符，用哈希做文件名，原键存在文件内容里以检测碰撞
        return $this->dir . '/' . hash('sha256', $key) . self::EXT;
    }

    /**
     * @return array{key: string, expires: int|null, value: mixed}|null
     */
    private function read(string $key): ?array
    {
        $file = $this->path($key);

        if (!is_file($file)) {
            return null;
        }

        $record = $this->readFile($file);

        if ($record === null || $record['key'] !== $key) {
            return null;
        }

        if ($record['expires'] !== null && $record['expires'] <= time()) {
            @unlink($file);

            return null;
        }

        return $record;
    }

    /**
     * @return array{key: string, expires: int|null, value: mixed}|null
     */
    private function readFile(string $file): ?array
    {
        $raw = @file_get_contents($file);

        if ($raw === false || $raw === '') {
            return null;
        }

        $data = @unserialize($raw);

        if (!is_array($data) || !array_key_exists('key', $data) || !array_key_exists('expires', $data)) {
            // 文件被截断或版本不兼容，当作未命中；下次写入会覆盖
            return null;
        }

        return ['key' => (string)$data['key'], 'expires' => $data['expires'], 'value' => $data['value'] ?? null];
    }

    private function write(string $key, mixed $value, ?int $expiresAt): bool
    {
        $this->validateKey($key);

        if (!$this->ensureDir()) {
            return false;
        }

        $file = $this->path($key);
        $payload = serialize(['key' => $key, 'expires' => $expiresAt, 'value' => $value]);
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $payload) === false) {
            return false;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        @chmod($file, 0664);

        return true;
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }

        return @mkdir($this->dir, 0775, true) || is_dir($this->dir);
    }

    /**
     * @return string[]
     */
    private function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $files = [];

        foreach ((array)scandir($this->dir) as $entry) {
            $entry = (string)$entry;

            if (str_ends_with($entry, self::EXT)) {
                $files[] = $this->dir . '/' . $entry;
            }
        }

        return $files;
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new CacheException('Cache key must not be empty');
        }

        // PSR-16 明确保留这几个字符，换驱动时才不会出现「文件驱动能跑、Redis 报错」
        if (preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw new CacheException("Cache key contains reserved characters: {$key}");
        }
    }

    private static function expiryOf(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp();
        }

        // 非正数表示「立刻过期」，按 PSR-16 应当删除而不是永久保存
        return $ttl > 0 ? time() + $ttl : time() - 1;
    }
}
