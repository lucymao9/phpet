<?php

namespace phpet\console;

use phpet\core\Application;

/**
 * 命令基类。
 *
 * 子类只需要声明名字、描述和 run()；退出码遵循惯例：
 * 0 成功，1 失败，2 用法错误。
 */
abstract class Command
{
    private ?Application $app = null;

    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * 用法示例，出现在 `help <命令>` 里。
     */
    public function usage(): string
    {
        return '';
    }

    abstract public function run(Input $input, Output $output): int;

    public function setApplication(Application $app): void
    {
        $this->app = $app;
    }

    protected function app(): Application
    {
        if ($this->app === null) {
            throw new \LogicException('Command ' . $this->name() . ' is not bound to an Application');
        }

        return $this->app;
    }
}
