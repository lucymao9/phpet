<?php
namespace phpet\attribute;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Route
{
    public function __Construct(
        public string $path,
        public array $methods = ['GET']
    ){}

}