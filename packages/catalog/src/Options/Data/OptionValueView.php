<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Data;

use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class OptionValueView
{
    public function __construct(
        public OptionValueId $id,
        public string $code,
        public LocalizedText $label,
        public int $position,
    ) {}
}
