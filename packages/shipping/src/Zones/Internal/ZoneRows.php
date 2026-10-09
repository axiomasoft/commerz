<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Internal;

use Commerz\Schema\TableNames;
use Commerz\Shipping\Common\Internal\Rows;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Shipping\Zones\Data\ZoneView;
use Commerz\Shipping\Zones\Enums\ZoneStatus;
use Illuminate\Database\ConnectionInterface;

/** Reads and writes the member rows of zones and turns zone rows into views. */
final readonly class ZoneRows
{
    public function __construct(private TableNames $tables) {}

    /**
     * @param  list<string>  $zoneIds
     * @return array<string, list<ZoneMember>> members by zone id, country ASC, region and prefix NULLS FIRST
     */
    public function membersOf(ConnectionInterface $db, array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        $rows = $db->table($this->tables->table('shipping_zone_members'))
            ->whereIn('shipping_zone_id', $zoneIds)
            ->orderByRaw('country asc, region asc nulls first, postal_prefix asc nulls first')
            ->get(['shipping_zone_id', 'country', 'region', 'postal_prefix'])
            ->all();

        $byZone = [];

        foreach ($rows as $row) {
            $region = Rows::nullableString($row, 'region');
            $prefix = Rows::nullableString($row, 'postal_prefix');

            $byZone[Rows::string($row, 'shipping_zone_id')][] = match (true) {
                $region !== null => ZoneMember::region($region),
                $prefix !== null => ZoneMember::postalPrefix(Rows::string($row, 'country'), $prefix),
                default => ZoneMember::country(Rows::string($row, 'country')),
            };
        }

        return $byZone;
    }

    /** @param  list<ZoneMember>  $members */
    public function insertMembers(ConnectionInterface $db, string $zoneId, array $members): void
    {
        if ($members === []) {
            return;
        }

        $db->table($this->tables->table('shipping_zone_members'))->insert(array_map(
            static fn (ZoneMember $member): array => [
                'shipping_zone_id' => $zoneId,
                'country' => $member->country,
                'region' => $member->region,
                'postal_prefix' => $member->postalPrefix,
            ],
            $members,
        ));
    }

    public function deleteMembers(ConnectionInterface $db, string $zoneId): void
    {
        $db->table($this->tables->table('shipping_zone_members'))->where('shipping_zone_id', $zoneId)->delete();
    }

    /** @param  array<string, list<ZoneMember>>  $members by zone id */
    public function view(object $row, array $members): ZoneView
    {
        $id = Rows::string($row, 'id');

        return new ZoneView(
            new ZoneId($id),
            Rows::string($row, 'code'),
            Rows::string($row, 'name'),
            ZoneStatus::from(Rows::string($row, 'status')),
            $members[$id] ?? [],
            Rows::date($row, 'updated_at'),
        );
    }
}
