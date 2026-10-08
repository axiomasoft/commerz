<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\EntityId;

/** Id of a store: the legal and commercial umbrella of one or more channels. */
#[Api(since: '0.1')]
final readonly class StoreId extends EntityId {}
