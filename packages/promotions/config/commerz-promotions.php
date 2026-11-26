<?php

declare(strict_types=1);

return [
    /* How a percentage effect rounds to the minor unit: half_up, half_even, half_down, up, down, ceiling or floor. */
    'rounding' => 'half_up',

    /* Codes read from one quote; the codes past the limit are rejected as `too_many_codes`. */
    'max_codes' => 5,

    /* Reasons for which `PromotionUsage::restore` gives the usage back: cancelled, expired, refunded. */
    'restore_on' => ['cancelled', 'expired'],

    /* Optional 2-phase coupon hold mode during checkout (held -> redeemed / released) in addition to D13. */
    'hold_on_checkout' => false,

    /* Default TTL in seconds for a checkout coupon hold when `hold_on_checkout` is enabled. */
    'hold_ttl_seconds' => 900,

    /* Near-miss hint evaluation policy (non-blocking stage with FailurePolicy::IgnoreOnFailure). */
    'near_miss' => [
        /* Minimum progress toward a threshold in basis points (0..9999) before emitting a hint; 0 emits for any positive cart. */
        'min_progress_bp' => 0,

        /* Timeout budget in milliseconds for the near-miss hint stage. */
        'timeout_ms' => 200,
    ],

    'coupons' => [
        /* Characters a `#` in a generation pattern takes: 2 to 64 distinct characters of A-Z and 0-9. */
        'alphabet' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',

        /* The most coupons one `GenerateCoupons` command may create. */
        'max_generate' => 50000,
    ],
];
