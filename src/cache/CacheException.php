<?php

namespace phpet\cache;

/**
 * 缓存键或缓存目录不合法时抛出。
 *
 * 继承 InvalidArgumentException，与 PSR-16 的约定一致。
 */
class CacheException extends \InvalidArgumentException
{
}
