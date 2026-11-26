<?php
declare(strict_types=1);

namespace fixtures;

use phpet\cache\CacheInterface;

/**
 * 内存缓存：测试替身，用来证明上层只依赖 CacheInterface。
 *
 * 故意不做键校验——那是各驱动自己的责任，RouteCache 之类不该替驱动操心，
 * 也不该因为换了驱动就换一套行为。
 */
final class MemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public int $reads = 0;
    public int $writes = 0;

    /** 置 true 后 get() 抛异常，用来验证上层不会因为驱动故障而崩 */
    public bool $failOnGet = false;

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->failOnGet) {
            throw new \RuntimeException('cache driver unavailable');
        }

        $this->reads++;

        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->writes++;
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

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
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string)$key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
