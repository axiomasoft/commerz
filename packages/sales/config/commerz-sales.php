<?php

declare(strict_types=1);

return [
    'carts' => [
        // 1..10000: lines in one cart.
        'max_lines' => 250,
        // 1..1000000: quantity of one line.
        'max_quantity' => 100000,
    ],
    'checkout' => [
        // Lines that require shipping need a selected delivery method; false for a CRM without shipping.
        'require_delivery' => true,
        // Key of the `order_number_generator` contribution.
        'number_generator' => 'sequence',
    ],
    'orders' => [
        // null or 1..525600: minutes to pay an order placed from a `cart`; drafts have no deadline.
        'payment_due_minutes' => 4320,
        // 1..1000: orders expired in one pass.
        'expire_batch' => 100,
    ],
    'payments' => [
        // 10..3600: lease of a network operation; keep it above the HTTP timeout of the gateway client.
        'lease_seconds' => 120,
    ],
];
