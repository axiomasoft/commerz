<?php

declare(strict_types=1);

use Commerz\Configuration\StockMode;

return [
    /*
     | Database connection for all Commerz tables. null uses the application default.
     */
    'connection' => null,

    /*
     | Prefix of every Commerz table: 2..7 lowercase letters or digits, then "_".
     | Choose it before the first migration; changing it later is a boot error.
     */
    'table_prefix' => 'cmz_',

    /*
     | 'inventory' checks sales against the inventory package; 'unlimited' skips stock tracking.
     */
    'stock' => StockMode::Inventory->value,

    /*
     | Class implementing the CustomerDirectory port, or null when the host has no customer groups.
     */
    'customers' => null,

    /*
     | Plugin classes implementing CommerzPlugin, registered in order.
     */
    'plugins' => [],

    'operations' => [
        /*
         | Days an idempotency receipt is kept; retries of the same key within it replay the result.
         */
        'receipt_ttl_days' => 30,
    ],
];
