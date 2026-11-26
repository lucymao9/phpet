<?php

namespace phpet\console\command;

use phpet\console\Command;
use phpet\console\Console;
use phpet\console\Input;
use phpet\console\Output;

/**
 * 列出全部命令。
 *
 * 命令表由 Console 通过闭包喂进来，避免这里反向依赖 Console 实例。
 */
final class ListCommand extends Command
{
    /** @var \Closure(): array<string, Command> */
    private \Closure $provider;

    /**
     * @param \Closure(): array<string, Command> $provider
     */
    public function __construct(\Closure $provider)
    {
        $this->provider = $provider;
    }

    public function name(): string
    {
        return 'list';
    }

    public function description(): string
    {
        return 'List all available commands';
    }

    public function usage(): string
    {
        return 'console list';
    }

    public function run(Input $input, Output $output): int
    {
        $commands = ($this->provider)();
        ksort($commands);

        $rows = [];
        foreach ($commands as $name => $command) {
            $rows[] = [$name, $command->description()];
        }

        $output->title('phpet console ' . Console::VERSION);
        $output->newline();
        $output->table(['Command', 'Description'], $rows);
        $output->newline();
        $output->dim("Run 'console help <command>' for usage.");

        return 0;
    }
}
