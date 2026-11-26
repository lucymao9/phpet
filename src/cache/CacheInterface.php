<?php

namespace phpet\cache;

/**
 * 缓存契约。
 *
 * 方法签名与 PSR-16（Psr\SimpleCache\CacheInterface）完全一致，只是不引入
 * 那个包——框架承诺零第三方运行时依赖。需要和其他库互操作时，写一个
 * 十行的适配器实现 Psr 接口再转调本接口即可，不需要改框架。
 *
 * PSR-16 用 InvalidArgumentException 表示 key 非法，这里用 CacheException，
 * 它是 InvalidArgumentException 的子类，catch 父类照样能抓到。
 */
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool;

    public function has(string $key): bool;
}
