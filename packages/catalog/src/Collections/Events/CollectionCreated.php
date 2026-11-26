<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Events;

use Commerz\Catalog\Collections\Data\CollectionView;
use Packman\Attributes\Api;

/** Dispatched after the creating transaction commits. */
#[Api(since: '0.1')]
final readonly class CollectionCreated
{
    public function __construct(public CollectionView $collection) {}
}
