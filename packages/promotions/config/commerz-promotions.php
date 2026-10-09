<?php

declare(strict_types=1);

return [
    /* How a percentage effect rounds to the minor unit: half_up, half_even, half_down, up, down, ceiling or floor. */
    'rounding' => 'half_up',

    /* Codes read from one quote; the codes past the limit are rejected as `too_many_codes`. */
    'max_codes' => 5,

    /* Reasons for which `PromotionUsage::restore` gives the usage back: cancelled, expired, refunded. */
    'restore_on' => ['cancelled', 'expired'],

    'coupons' => [
        /* Characters a `#` in a generation pattern takes: 2 to 64 distinct characters of A-Z and 0-9. */
        'alphabet' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',

        /* The most coupons one `GenerateCoupons` command may create. */
        'max_generate' => 50000,
    ],
];
