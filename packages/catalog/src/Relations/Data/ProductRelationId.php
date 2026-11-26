<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ProductRelationId extends EntityId {}
