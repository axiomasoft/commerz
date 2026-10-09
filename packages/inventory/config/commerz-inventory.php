<?php

declare(strict_types=1);

return [
    /*
    | Key of the contributed `allocation_strategy` that decides which location holds a reservation:
    | `priority` (default; fills channel sources in priority order, may split a line across locations),
    | `single_location` (one location for the whole reservation) or a plugin key such as `acme.nearest`.
    */
    'allocation_strategy' => 'priority',

    /*
    | What a sellable without any stock item link is: `unavailable` (nothing to sell, reserving fails)
    | or `unlimited` (always available, nothing is held).
    */
    'unlinked_sellables' => 'unavailable',

    /* Reservations the expiry sweeper releases per pass (1..10000). */
    'expiry_batch' => 500,
];
