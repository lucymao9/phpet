<?php

namespace phpet\core;

use phpet\attribute\validate\Each;
use phpet\attribute\validate\Length;
use phpet\attribute\validate\Range;
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

                // 分组过滤：规则未声明 groups 时对所有分组生效
                if (property_exists($rule, 'groups')
                    && !in_array($group, $rule->groups, true)) {
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
                $this->addError($path, "{$name} is required");
            }
            return;
        }

        if ($rule instanceof Length) {
            if ($value === null) {
                return;
            }
            $len = is_array($value) ? count($value) : mb_strlen((string)$value);
            if ($len < $rule->min || $len > $rule->max) {
                $this->addError($path, $this->rangeMessage("{$name} length", $rule->min, $rule->max));
            }
            return;
        }

        if ($rule instanceof Range) {
            if ($value === null) {
                return;
            }
            if (!is_numeric($value)) {
                $this->addError($path, "{$name} must be numeric");
                return;
            }
            if ($value < $rule->min || $value > $rule->max) {
                $this->addError($path, $this->rangeMessage($name, $rule->min, $rule->max));
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
            if (!is_array($value)) {
                return;
            }
            $this->validateEach($rule, $value, $path, $name, $group);
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
                    $this->addError($itemPath, "{$name} must be an object");
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
            if ($ref->hasProperty($key)) {
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

    private function rangeMessage(string $label, $min, $max): string
    {
        if ($max >= PHP_INT_MAX) {
            return "{$label} must be at least {$min}";
        }
        if ($min <= 0 && $max < PHP_INT_MAX) {
            return "{$label} must be at most {$max}";
        }
        return "{$label} must be {$min}-{$max}";
    }

    private function addError(string $itemPath, $message): void
    {
        $this->errors[$itemPath][] = $message;
    }
}
