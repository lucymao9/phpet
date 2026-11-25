<?php

namespace phpet\core;

use DateTime;
use phpet\attribute\validate\Date;
use phpet\attribute\validate\Each;
use phpet\attribute\validate\Email;
use phpet\attribute\validate\In;
use phpet\attribute\validate\Integer;
use phpet\attribute\validate\Length;
use phpet\attribute\validate\Range;
use phpet\attribute\validate\Regex;
use phpet\attribute\validate\Required;
use phpet\attribute\validate\Valid;
use phpet\core\Exception\ValidationException;
use ReflectionClass;
use ReflectionNamedType;

class Validator
{
    /** @var array<string, string[]> */
    private array $errors = [];

    /**
     * 校验 DTO，失败抛 ValidationException。
     */
    public function validate(
        object $dto,
        string $group = 'default',
        string $path = '',
    ): void
    {
        // 每次校验都从干净状态开始，避免复用实例时错误累积
        $this->errors = [];
        $this->doValidate($dto, $group, $path);

        if ($this->errors) {
            throw new ValidationException($this->errors);
        }
    }

    private function doValidate(
        object $dto,
        string $group,
        string $path = '',
    ): void
    {
        $ref = new ReflectionClass($dto);

        foreach ($ref->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $name = $prop->getName();
            $fullPath = $path ? "{$path}.{$name}" : $name;

            $value = $prop->isInitialized($dto)
                ? $prop->getValue($dto)
                : null;

            foreach ($prop->getAttributes() as $attr) {
                $rule = $attr->newInstance();

                // 分组过滤：groups 为空表示对所有分组生效
                if (!$this->matchesGroup($rule, $group)) {
                    continue;
                }

                $this->applyRule($rule, $value, $fullPath, $name, $group);
            }
        }
    }

    private function applyRule(
        object $rule,
        $value,
        string $path,
        string $name,
        string $group
    ): void
    {
        if ($rule instanceof Required) {
            if ($this->isEmpty($value)) {
                $this->addError($path, $this->msg($rule, 'The {name} field is required.', [], $path, $name));
            }
            return;
        }

        // 其余规则在值为 null 时跳过，是否必填交给 Required 决定
        if ($value === null) {
            return;
        }

        if ($rule instanceof Length) {
            if (is_array($value)) {
                $len = count($value);
            } elseif (is_scalar($value)) {
                $len = mb_strlen((string)$value);
            } else {
                $len = null;
            }

            if ($len === null) {
                $this->addError($path, $this->msg($rule, 'The {name} must be a string or array.', [], $path, $name));
                return;
            }

            if ($len < $rule->min || $len > $rule->max) {
                $this->addError($path, $this->msg(
                    $rule,
                    'The {name} length must be {min}-{max}.',
                    ['min' => $rule->min, 'max' => $rule->max, 'length' => $len],
                    $path,
                    $name
                ));
            }
            return;
        }

        if ($rule instanceof Range) {
            if (!is_numeric($value)) {
                $this->addError($path, $this->msg(
                    $rule,
                    'The {name} must be a number.',
                    [],
                    $path,
                    $name
                ));
                return;
            }
            if ($value < $rule->min || $value > $rule->max) {
                $this->addError($path, $this->msg(
                    $rule,
                    $this->rangeDefault($rule->min, $rule->max),
                    ['min' => $rule->min, 'max' => $rule->max],
                    $path,
                    $name
                ));
            }
            return;
        }

        if ($rule instanceof Email) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $this->addError($path, $this->msg($rule, 'The {name} must be a valid email address.', [], $path, $name));
            }
            return;
        }

        if ($rule instanceof Integer) {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                $this->addError($path, $this->msg($rule, 'The {name} must be an integer.', [], $path, $name));
            }
            return;
        }

        if ($rule instanceof Date) {
            if (!is_scalar($value)) {
                $this->addError($path, $this->msg($rule, 'The {name} must be a valid date.', [], $path, $name));
                return;
            }
            $raw = (string)$value;
            $parsed = DateTime::createFromFormat($rule->format, $raw);
            if ($parsed === false || $parsed->format($rule->format) !== $raw) {
                $this->addError($path, $this->msg(
                    $rule,
                    'The {name} must match the date format {format}.',
                    ['format' => $rule->format],
                    $path,
                    $name
                ));
            }
            return;
        }

        if ($rule instanceof Regex) {
            if (!is_scalar($value) || @preg_match($rule->pattern, (string)$value) !== 1) {
                $this->addError($path, $this->msg($rule, 'The {name} format is invalid.', [], $path, $name));
            }
            return;
        }

        if ($rule instanceof In) {
            if (!in_array($value, $rule->values, true)) {
                $this->addError($path, $this->msg(
                    $rule,
                    'The {name} must be one of: {values}.',
                    ['values' => implode(', ', array_map('strval', $rule->values))],
                    $path,
                    $name
                ));
            }
            return;
        }

        if ($rule instanceof Valid) {
            if (is_object($value)) {
                $this->doValidate($value, $group, $path);
            }
            return;
        }

        if ($rule instanceof Each) {
            if (is_array($value)) {
                $this->validateEach($rule, $value, $path, $name, $group);
            }
        }
    }

    /**
     * 数组元素校验：
     * - 声明了 dto 时按 DTO 递归校验每个元素
     * - 否则对每个元素依次应用 rules 中的标量规则
     */
    private function validateEach(
        Each   $rule,
        array  $items,
        string $path,
        string $name,
        string $group
    ): void
    {
        foreach ($items as $index => $item) {
            $itemPath = "{$path}[{$index}]";

            if ($rule->dto !== null) {
                $child = $this->asObject($rule->dto, $item);
                if ($child === null) {
                    $this->addError($itemPath, $this->msg(
                        $rule,
                        'The {name}[{index}] must be an object.',
                        ['index' => $index],
                        $itemPath,
                        $name
                    ));
                    continue;
                }
                $this->doValidate($child, $group, $itemPath);
                continue;
            }

            foreach ($rule->rules as $subRule) {
                $this->applyRule($subRule, $item, $itemPath, $name, $group);
            }
        }
    }

    /**
     * 把数组元素转成 DTO 实例（已是对象则直接返回）。
     */
    private function asObject(string $dtoClass, $item): ?object
    {
        if (is_object($item)) {
            return $item;
        }
        if (!is_array($item) || !class_exists($dtoClass)) {
            return null;
        }

        $ref = new ReflectionClass($dtoClass);
        $child = $ref->newInstance();
        foreach ($item as $key => $v) {
            if (!$ref->hasProperty($key)) {
                continue;
            }

            $prop = $ref->getProperty($key);
            $type = $prop->getType();
            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                $v = match ($type->getName()) {
                    'int' => is_numeric($v) ? (int)$v : $v,
                    'float' => is_numeric($v) ? (float)$v : $v,
                    'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                    'string' => is_scalar($v) ? (string)$v : $v,
                    default => $v,
                };
            }
            $prop->setValue($child, $v);
        }

        return $child;
    }

    /**
     * Required 语义下的“空”：null、空字符串、空数组。
     * 0 与 false 视为有效值。
     */
    private function isEmpty($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * groups 为空：对所有分组生效；否则只匹配指定分组。
     */
    private function matchesGroup(object $rule, string $group): bool
    {
        if (!property_exists($rule, 'groups')) {
            return true;
        }

        return $rule->groups === [] || in_array($group, $rule->groups, true);
    }

    private function rangeDefault(int|float $min, int|float $max): string
    {
        if ($max >= PHP_INT_MAX) {
            return 'The {name} must be at least {min}.';
        }
        if ($min <= 0) {
            return 'The {name} must be at most {max}.';
        }

        return 'The {name} must be between {min} and {max}.';
    }

    /**
     * 取规则自定义消息并替换占位符：{name} {path} {min} {max} ...
     */
    private function msg(
        object $rule,
        string $default,
        array  $vars,
        string $path,
        string $name
    ): string
    {
        $template = property_exists($rule, 'message') && $rule->message !== null
            ? $rule->message
            : $default;

        $replace = [
            '{name}' => $name,
            '{path}' => $path,
        ];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string)$value;
        }

        return str_replace(array_keys($replace), array_values($replace), $template);
    }

    private function addError(string $itemPath, $message): void
    {
        $this->errors[$itemPath][] = $message;
    }
}
