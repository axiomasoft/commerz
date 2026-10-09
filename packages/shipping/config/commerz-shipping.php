<?php

declare(strict_types=1);

return [
    /* Whether a rate's price includes tax when `ZoneRate::$taxIncluded` is left null. */
    'tax_included' => false,

    /* The most members (countries, regions, postal prefixes) one zone command may carry: 1 to 100000. */
    'max_zone_members' => 2000,

    'booking' => [
        /* Seconds a fresh `pending` booking counts as in progress: 10 to 3600; must exceed the carrier's request timeout. */
        'lease_seconds' => 120,
    ],
];
