<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Currency;
use Commerz\Stores\Enums\StoreStatus;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class StoreView
{
    public function __construct(
        public StoreId $id,
        public string $code,
        public string $name,
        public Currency $defaultCurrency,
        public string $orderNumberPrefix,
        public StoreStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
