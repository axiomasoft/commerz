<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Events;

use Commerz\Catalog\Variants\Data\VariantView;
use Packman\Attributes\Api;

/** Dispatched after a variant changed (fields, attributes, archival or restore) and the change committed; the view is the state after it. */
#[Api(since: '0.1')]
final readonly class VariantUpdated
{
    public function __construct(public VariantView $variant) {}
}
