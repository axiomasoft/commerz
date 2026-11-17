<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Events;

use Commerz\Catalog\Variants\Data\VariantView;
use Packman\Attributes\Api;

/** Dispatched after the creating transaction (`create` or `generate`, one per variant) commits. */
#[Api(since: '0.1')]
final readonly class VariantCreated
{
    public function __construct(public VariantView $variant) {}
}
