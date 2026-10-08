<?php

declare(strict_types=1);

namespace Commerz\Inventory\Enums;

use Commerz\Attributes\Api;

#[Api(since: '1.0')]
enum ReservationState: string
{
    case Active = 'active';
    case Consumed = 'consumed';   // полностью списана отгрузкой
    case Released = 'released';   // отмена заказа/удаление строки
    case Expired = 'expired';     // TTL, освобождена лениво или sweeper-ом

    public function holdsStock(): bool
    {
        return $this === self::Active;
    }
}

#[Api(since: '1.0')]
enum DemandType: string
{
    case Order = 'order';
    case Cart = 'cart';       // только при ReservationTiming::OnCheckoutStart
    case Transfer = 'transfer';
    case Manual = 'manual';
}

/** Когда sales вызывает резерв. Конфиг commerz-inventory.reservation_timing. */
#[Api(since: '1.0')]
enum ReservationTiming: string
{
    case OnOrderPlaced = 'on_order_placed';     // по умолчанию
    case OnCheckoutStart = 'on_checkout_start'; // бронь корзины с TTL (лимитированные дропы)
    case Never = 'never';                       // продаём без брони, сверка при отгрузке
}

#[Api(since: '1.0')]
enum StockMovementKind: string
{
    case Receipt = 'receipt';
    case Shipment = 'shipment';
    case Adjustment = 'adjustment';
    case Count = 'count';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case ReturnRestock = 'return_restock';
    case Correction = 'correction';   // сторно ошибочного движения; журнал не редактируется
}
