<?php

namespace phpet\console;

use phpet\console\command\CacheClearCommand;
use phpet\console\command\ListCommand;
use phpet\console\command\MakeMigrationCommand;
use phpet\console\command\MigrateCommand;
use phpet\console\command\RollbackCommand;
use phpet\console\command\RouteListCommand;
use phpet\console\command\StatusCommand;
use phpet\core\Application;

/**
 * 控制台入口。
 *
 * 本身不认识任何具体命令：命令通过 add() 注册，`list` 与 `route:list` 等内置命令
 * 在构造时挂上。新增命令时只要在这里加一行 add()。
 */
class Console
{
    public const VERSION = '0.3.0';

    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(private Application $app)
    {
        $this->add(new ListCommand(fn(): array => $this->commands));
        $this->add(new RouteListCommand());
        $this->add(new MakeMigrationCommand());
        $this->add(new MigrateCommand());
        $this->add(new RollbackCommand());
        $this->add(new StatusCommand());
        $this->add(new CacheClearCommand());
    }

    public function add(Command $command): static
    {
        $name = $command->name();

        if (isset($this->commands[$name])) {
            throw new \InvalidArgumentException("Command '{$name}' is already registered");
        }

        $this->commands[$name] = $command;

        return $this;
    }

    public function application(): Application
    {
        return $this->app;
    }

    /**
     * @return array<string, Command>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * @param string[]|null $argv   完整的 argv；缺省取 $_SERVER['argv']
     * @param Output|null   $output 传进来可以接管输出（测试用）
     */
    public function run(?array $argv = null, ?Output $output = null): int
    {
        $argv ??= $_SERVER['argv'] ?? [];
        $input = Input::fromArgv($argv);

        $output ??= Output::auto();

        if ($input->hasOption('no-color')) {
            $output->setColored(false);
        }

        // 命令里也会写日期、写日志，时区与日志目录得和 web 一致
        $this->app->applyRuntimeDefaults();

        if ($input->hasOption('version')) {
            $output->writeln('phpet console ' . self::VERSION);

            return 0;
        }

        $name = $input->command();

        if ($name === 'help') {
            $target = (string)$input->argument(0, '');

            return $target === ''
                ? $this->runList($output)
                : $this->describe($output, $target);
        }

        if ($name === '') {
            return $this->runList($output);
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $output->error("Unknown command '{$name}'");

            $suggestion = $this->suggest($name);
            if ($suggestion !== null) {
                $output->dim("Did you mean '{$suggestion}'?");
            }

            $output->dim("Run 'console list' to see all commands.");

            return 2;
        }

        if ($input->hasOption('help')) {
            return $this->describe($output, $name);
        }

        $command->setApplication($this->app);

        try {
            return $command->run($input, $output);
        } catch (\Throwable $e) {
            $output->error($e->getMessage());

            // 默认只报一行；要看栈就把 APP_DEBUG 打开或加 --debug
            if ($this->app->isDebug() || $input->hasOption('debug')) {
                $output->dim($e->getFile() . ':' . $e->getLine());
                $output->dim($e->getTraceAsString());
            }

            return 1;
        }
    }

    private function runList(Output $output): int
    {
        /** @var ListCommand $list */
        $list = $this->commands['list'];

        return $list->run(Input::fromArgv(['console', 'list']), $output);
    }

    private function describe(Output $output, string $name): int
    {
        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $output->error("Unknown command '{$name}'");

            return 2;
        }

        $output->title($command->name());
        $output->writeln('  ' . $command->description());

        $usage = $command->usage();
        if ($usage !== '') {
            $output->newline();
            $output->writeln('  Usage: ' . $usage);
        }

        return 0;
    }

    private function suggest(string $name): ?string
    {
        $best = null;
        $score = PHP_INT_MAX;

        foreach (array_keys($this->commands) as $candidate) {
            $distance = levenshtein($name, $candidate);

            // 只建议足够接近的，否则「你是指 xxx 吗」反而是噪音
            if ($distance < $score && $distance <= 3) {
                $best = $candidate;
                $score = $distance;
            }
        }

        return $best;
    }
}
