<?php

namespace phpet\core;

use phpet\attribute\validate\Each;
use phpet\attribute\validate\Length;
use phpet\attribute\validate\Range;
use phpet\attribute\validate\Required;
use phpet\attribute\validate\Valid;
use phpet\core\Exception\ValidationException;
use ReflectionClass;
use ReflectionProperty;

class Validator
{
    private array $errors = [];
    public function validate(
        object $dto,
        string $group = 'default',
        string $path = '',
    ): void
    {
        $errors = $this->doValidate($dto, $group, $path);
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    private function doValidate(
        object $dto,
        string $group,
        string $path = '',
    ): array
    {
        $ref = new ReflectionClass($dto);

        foreach ($ref->getProperties() as $prop) {
            $name = $prop->getName();
            $fullPath = $path ? "{$path}.{$name}" : $name;

            $value = $prop->isInitialized($dto)
                ? $prop->getValue($dto)
                : null;

            foreach ($prop->getAttributes() as $attr) {
                $rule = $attr->newInstance();

                // group 过滤
                if (property_exists($rule, 'groups')
                    && !in_array($group, $rule->groups, true)) {
                    continue;
                }

                if ($rule instanceof Required && $value === null) {
                    $this->addError($fullPath,"{$name} is required");
                }

                if ($rule instanceof Length && $value !== null) {
                    $len = mb_strlen((string)$value);
                    if ($len < $rule->min || $len > $rule->max) {
                        $this->addError($fullPath,"{$name} length must be {$rule->min}-{$rule->max}");
                    }
                }

                if ($rule instanceof Range && $value !== null) {
                    if ($value < $rule->min || $value > $rule->max) {
                        $this->addError($fullPath,"{$name} must be {$rule->min}-{$rule->max}");
                    }
                }

                if ($rule instanceof Valid && is_object($value)) {
                    $this->doValidate(
                        $value,
                        $group,
                        $fullPath
                    );
                }

            }
        }
        return $this->errors;
    }

    private function addError(string $itemPath, $message)
    {
        $this->errors[$itemPath][] = $message;
    }
}