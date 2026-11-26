<?php

declare(strict_types=1);

return [
    /* Maximum stored-value tenders accepted on a single quote settlement. */
    'max_tenders_per_quote' => 5,

    /* Default TTL (in seconds) for checkout holds when the caller omits expiresAt. Null means no auto-expiry. */
    'default_hold_ttl_seconds' => 1800,

    'gift_cards' => [
        /* Length of generated random gift-card codes (excluding prefix and group separators). */
        'code_length' => 16,

        /* Characters used when generating gift-card codes: 16..64 distinct characters of A-Z and 0-9. */
        'code_alphabet' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',

        /* Optional uppercase prefix prepended to generated gift-card codes (e.g. 'GC'). */
        'code_prefix' => 'GC',

        /* Default validity period in days when issuing a gift card without explicit expiresAt; null = never expires. */
        'default_expiry_days' => null,
    ],
];
