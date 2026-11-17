<?php

declare(strict_types=1);

// Shared fixtures of the zone tests.

use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Data\CreateZone;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Shipping\Zones\Data\ZoneView;
use Packman\Schema\Data\Compiler\TableNames;

/** @param  list<ZoneMember>  $members */
function znCreate(string $code = 'ru', ?array $members = null, ?string $name = null): ZoneView
{
    return app(Zones::class)->create(new CreateZone($code, $name ?? strtoupper($code), $members ?? [ZoneMember::country('RU')]));
}

function znTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}
