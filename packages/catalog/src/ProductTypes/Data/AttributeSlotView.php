<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Data;

use Commerz\Catalog\Attributes\Data\AttributeId;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class AttributeSlotView
{
    public function __construct(
        public AttributeId $attribute,
        public string $code,
        public AttributeLevel $level,
        public bool $required,
        public int $position,
    ) {}
}
