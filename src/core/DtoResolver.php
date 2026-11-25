<?php

namespace phpet\core;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;

class DtoResolver
{
    public function __construct(
        private Request $request
    ) {}

    /**
     * @param string     $class 目标 DTO 类
     * @param array|null $data  数据源；传 null 时从当前 Request 的 body/query 读取。
     *                          显式传数组时（嵌套 DTO、批量项）只从该数组取值，
     *                          避免嵌套字段意外串用顶层请求数据。
     */
    public function resolve(string $class, ?array $data = null): object
    {
        $ref = new ReflectionClass($class);
        $dto = $ref->newInstance();

        foreach ($ref->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $name = $prop->getName();
            $value = $data !== null
                ? ($data[$name] ?? null)
                : ($this->request->body($name) ?? $this->request->query($name));

            if ($value === null) {
                continue;
            }

            $type = $prop->getType();
            $typeName = $this->classTypeName($type);

            // 嵌套 DTO：递归解析（子级只从自己的数组取数）
            if ($typeName !== null && class_exists($typeName)) {
                if (is_object($value)) {
                    $child = $value;
                } elseif (is_array($value)) {
                    $child = $this->resolve($typeName, $value);
                } else {
                    continue;
                }

                $prop->setValue($dto, $child);
                continue;
            }

            $casted = $this->cast($value, $type);
            // null 表示类型不匹配、无法安全赋值，保留属性默认值交给校验处理
            if ($casted !== null) {
                $prop->setValue($dto, $casted);
            }
        }

        return $dto;
    }

    /**
     * 取非内置类型的类名；无类型声明或内置类型返回 null。
     */
    private function classTypeName(?ReflectionType $type): ?string
    {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    /**
     * 按声明类型转换标量；无类型声明时原样返回，
     * 不再用 settype($value, null) 触发 ValueError。
     */
    private function cast($value, ?ReflectionType $type)
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int)$value : null,
            'float' => is_numeric($value) ? (float)$value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => is_scalar($value) ? (string)$value : null,
            'array' => is_array($value) ? $value : null,
            default => $value,
        };
    }
}
