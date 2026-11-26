<?php

namespace phpet\console;

/**
 * 命令行输入的解析结果。
 *
 * 约定（刻意保持简单，避免「选项能不能吃下一个 token」这种歧义）：
 *   - 第一个非选项 token 是命令名
 *   - `--name=value` 是带值选项；`--name` 是布尔开关
 *   - 以 `--` 开头的 token 之后全部按位置参数处理
 *   - 其余 token 按出现顺序成为位置参数
 */
class Input
{
    /**
     * @param string[]             $arguments 位置参数
     * @param array<string, mixed> $options   选项，键不含前导斜杠
     */
    private function __construct(
        private string $command,
        private array $arguments,
        private array $options
    ) {
    }

    /**
     * @param string[] $argv 完整的 argv（含脚本名）
     */
    public static function fromArgv(array $argv): self
    {
        $command = '';
        $arguments = [];
        $options = [];
        $literal = false;

        foreach (array_slice($argv, 1) as $token) {
            $token = (string)$token;

            if ($literal) {
                $arguments[] = $token;
                continue;
            }

            if ($token === '--') {
                $literal = true;
                continue;
            }

            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                $pos = strpos($body, '=');

                if ($pos === false) {
                    $options[$body] = true;
                } else {
                    $options[substr($body, 0, $pos)] = substr($body, $pos + 1);
                }

                continue;
            }

            // 单个短横线开头的选项一律按布尔开关处理，不做合并解析
            if (str_starts_with($token, '-') && $token !== '-') {
                $options[substr($token, 1)] = true;
                continue;
            }

            if ($command === '') {
                $command = $token;
                continue;
            }

            $arguments[] = $token;
        }

        return new self($command, $arguments, $options);
    }

    public function command(): string
    {
        return $this->command;
    }

    /**
     * @return string[]
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function argument(int $index, mixed $default = null): mixed
    {
        return $this->arguments[$index] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /**
     * 取整数选项：`--steps=3`。非法值直接报错，不静默退回默认值。
     */
    public function intOption(string $name, ?int $default = null): ?int
    {
        $value = $this->options[$name] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_bool($value) || !preg_match('/^-?\d+$/', (string)$value)) {
            throw new \InvalidArgumentException(
                "Option --{$name} expects an integer, got '" . (is_bool($value) ? '1' : $value) . "'"
            );
        }

        return (int)$value;
    }
}
