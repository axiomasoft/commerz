<?php

declare(strict_types=1);

namespace Commerz\Inventory\Exceptions;

use Commerz\Attributes\Api;
use RuntimeException;

#[Api(since: '1.0')]
final class InsufficientStock extends RuntimeException
{
    public function __construct(public readonly string $stockLevelId, public readonly string $lineRef, public readonly int $requested, public readonly int $available)
    {
        parent::__construct("Insufficient stock on level [{$stockLevelId}] for [{$lineRef}]: requested {$requested}, available {$available}.");
    }
}

/** Тот же естественный ключ брони, но другое количество: это не повтор, а ошибка вызывающего. */
#[Api(since: '1.0')]
final class ReservationConflict extends RuntimeException
{
    public static function quantityMismatch(string $lineRef, int $existing, int $requested): self
    {
        return new self("Reservation for [{$lineRef}] already exists with quantity {$existing}, requested {$requested}.");
    }
}

#[Api(since: '1.0')]
final class UnknownStockLevel extends RuntimeException
{
    public static function id(string $id): self
    {
        return new self("Stock level [{$id}] does not exist.");
    }
}
