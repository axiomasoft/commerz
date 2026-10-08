<?php

declare(strict_types=1);

namespace Commerz\Inventory\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Inventory\Data\ReservationResult;
use Commerz\Inventory\Data\ReserveStock;

/**
 * Порт, через который sales резервирует остатки. Реализация по умолчанию — commerz-inventory;
 * хост может забиндить адаптер внешнего склада (МойСклад, 1С) — это и есть «замена модуля», без подмены моделей.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface StockReservations
{
    /** Всё или ничего. Бросает InsufficientStock, ReservationConflict. Вызывается внутри транзакции заказа. */
    public function reserve(ReserveStock $command): ReservationResult;
}
