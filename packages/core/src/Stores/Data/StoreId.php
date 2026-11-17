<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

/** Id of a store: the legal and commercial umbrella of one or more channels. */
#[Api(since: '0.1')]
final readonly class StoreId extends EntityId {}
