<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\EntityId;

#[Api(since: '0.1')]
final readonly class CategoryId extends EntityId {}
