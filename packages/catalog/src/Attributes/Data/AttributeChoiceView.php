<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Data;

use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class AttributeChoiceView
{
    public function __construct(
        public AttributeChoiceId $id,
        public string $code,
        public LocalizedText $label,
        public int $position,
    ) {}
}
