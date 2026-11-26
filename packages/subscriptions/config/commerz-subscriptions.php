<?php

declare(strict_types=1);

return [
    /* Maximum due billing cycles processed in a single batch run when no explicit limit is given. */
    'batch_size' => 100,

    'retry_policy' => [
        /* Total attempts per billing cycle (1 initial attempt + retries). */
        'max_attempts' => 4,

        /* Delay in seconds before each subsequent retry attempt (1 day, 3 days, 7 days). */
        'backoff_delays_seconds' => [86400, 259200, 604800],

        /* Contract transition when all retry attempts are exhausted: 'cancel', 'pause', or 'keep_past_due'. */
        'exhausted_action' => 'cancel',
    ],
];
