<?php

namespace phpet\console;

/**
 * 终端输出。只管拼字符串和上色，不关心谁来接。
 *
 * 颜色默认按「是不是 TTY」判断，另外支持 `--no-color` 与 NO_COLOR 环境变量，
 * 两者任一成立就退回纯文本——CI 日志里不会出现一堆转义序列。
 */
class Output
{
    private const COLORS = [
        'green'  => '32',
        'red'    => '31',
        'yellow' => '33',
        'cyan'   => '36',
        'gray'   => '90',
        'bold'   => '1',
    ];

    private bool $colored;

    public function __construct(bool $colored = true)
    {
        $this->colored = $colored;
    }

    public static function auto(): self
    {
        $isTty = \function_exists('stream_isatty') && @stream_isatty(\STDOUT);

        return new self($isTty && getenv('NO_COLOR') === false);
    }

    public function isColored(): bool
    {
        return $this->colored;
    }

    public function setColored(bool $colored): void
    {
        $this->colored = $colored;
    }

    public function write(string $text): void
    {
        echo $text;
    }

    public function writeln(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    public function newline(): void
    {
        echo PHP_EOL;
    }

    public function success(string $text): void
    {
        $this->writeln($this->paint('✔ ', 'green') . $text);
    }

    public function error(string $text): void
    {
        $this->writeln($this->paint('✖ ', 'red') . $text);
    }

    public function warn(string $text): void
    {
        $this->writeln($this->paint('! ', 'yellow') . $text);
    }

    public function info(string $text): void
    {
        $this->writeln($this->paint('› ', 'cyan') . $text);
    }

    public function dim(string $text): void
    {
        $this->writeln($this->paint($text, 'gray'));
    }

    public function title(string $text): void
    {
        $this->writeln($this->paint($text, 'bold'));
    }

    /**
     * 等宽对齐的表格。中文按显示宽度算，不会错位。
     *
     * @param string[]              $headers
     * @param array<int, string[]>  $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map([self::class, 'displayWidth'], $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, self::displayWidth((string)$cell));
            }
        }

        $render = function (array $cells) use ($widths): string {
            $parts = [];
            foreach (array_values($cells) as $i => $cell) {
                $pad = ($widths[$i] ?? 0) - self::displayWidth((string)$cell);
                $parts[] = (string)$cell . str_repeat(' ', max(0, $pad));
            }

            return rtrim(implode('  ', $parts));
        };

        $this->writeln($this->paint($render($headers), 'bold'));

        foreach ($rows as $row) {
            $this->writeln($render($row));
        }
    }

    private function paint(string $text, string $color): string
    {
        if (!$this->colored || !isset(self::COLORS[$color])) {
            return $text;
        }

        return "\033[" . self::COLORS[$color] . 'm' . $text . "\033[0m";
    }

    private static function displayWidth(string $text): int
    {
        return mb_strwidth($text, 'UTF-8');
    }
}
