<?php

namespace phpet\core;

use ReflectionClass;

class DtoResolver
{
    public function __construct(
        private Request $request
    ) {}

    public function resolve(string $class,  $item = null): object
    {
        $ref = new ReflectionClass($class);
        $dto = $ref->newInstance();

        foreach ($ref->getProperties() as $prop) {
            $name = $prop->getName();
            
            $value = $item[$name] ?? ($this->request->body($name)
                ?? $this->request->query($name));

            if ($value !== null) {
                $type = $prop->getType()?->getName();

                if ($type && class_exists($type)) {
                    // 👇 嵌套 DTO
                    $child = new $type();

                    foreach ((array)$value as $k => $v) {
                        if (property_exists($child, $k)) {
                            $child->$k = $v;
                        }
                    }

                    $prop->setValue($dto, $child);
                    continue;
                }
                settype($value, $type);
                $prop->setValue($dto, $value);
            }
        }

        return $dto;
    }

}