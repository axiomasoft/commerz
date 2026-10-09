<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Data;

use Commerz\Attributes\Api;
use Commerz\Measures\LocalizedText;

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
