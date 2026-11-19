<?php

declare(strict_types=1);

use Commerz\Sales\Orders\Data\OrderEventView;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Schema\TableNames;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

function retOutbox(string $type): int
{
    return DB::table(app(TableNames::class)->table('outbox_messages'))->where('type', $type)->count();
}

/** @return list<string> */
function retHistory(OrderView $order): array
{
    return array_map(static fn (OrderEventView $event): string => $event->type, slsOrders()->history($order->id));
}
