<?php

declare(strict_types=1);

namespace Commerz\Inventory\Data;

use Commerz\Attributes\Api;
use Commerz\Inventory\Enums\DemandType;
use InvalidArgumentException;

/** Одна строка плана: сколько базовых единиц с какого уровня (item × location). План строит AllocationStrategy. */
#[Api(since: '1.0')]
final readonly class ReservationLine
{
    public function __construct(public string $stockLevelId, public string $lineRef, public int $quantity)
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Reservation quantity must be positive, got {$quantity}.");
        }
    }
}

#[Api(since: '1.0')]
final readonly class ReserveStock
{
    /**
     * @param list<ReservationLine> $lines
     * @param ?int $ttlMinutes null — бессрочно (заказ), иначе бронь истекает (корзина, неоплаченный заказ)
     */
    public function __construct(
        public DemandType $demandType,
        public string $demandId,
        public array $lines,
        public ?int $ttlMinutes = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('ReserveStock requires at least one line.');
        }
    }
}

#[Api(since: '1.0')]
final readonly class ReservedLine
{
    public function __construct(
        public string $reservationId,
        public string $stockLevelId,
        public string $lineRef,
        public int $quantity,
        public bool $replayed,      // true — команда повторена, бронь уже была (идемпотентность)
    ) {}
}

#[Api(since: '1.0')]
final readonly class ReservationResult
{
    /** @param list<ReservedLine> $lines */
    public function __construct(public array $lines) {}
}
