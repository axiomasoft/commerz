<?php

declare(strict_types=1);

return [
    /* Default validity window (in days) for staged B2B quotes when not explicitly provided. */
    'default_quote_validity_days' => 30,

    /* Maximum hierarchy depth for nested BusinessUnits (0 = root company). */
    'max_hierarchy_depth' => 10,

    /* Whether the requester of an ApprovalFlow may approve their own request when holding an approver/admin role. */
    'allow_self_approval' => false,

    /* Prefix prepended to generated RFQ numbers. */
    'quote_request_prefix' => 'RFQ',
];
