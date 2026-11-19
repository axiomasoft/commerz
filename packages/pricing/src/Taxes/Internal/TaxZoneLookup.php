<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Pricing\Common\Internal\Rows;
use Commerz\Pricing\Taxes\Data\TaxZoneId;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Pricing\Taxes\Data\TaxZoneView;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/** Finds the tax zone of a place. The one reader of zone membership: `zoneFor` and the `rates` calculator share it. */
#[Internal]
final readonly class TaxZoneLookup
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /** @return object|null the zone row: id, code, name */
    public function byCode(string $code, ?string $lock = null): ?object
    {
        $builder = $this->db()->table($this->tables->table('tax_zones'))->where('code', $code);

        if ($lock !== null) {
            $builder->lock($lock);
        }

        return $builder->first();
    }

    /**
     * The zone of the region when a member names it, else the zone of the country as a whole.
     *
     * @return object|null the zone row: id, code, name
     */
    public function forAddress(Address $address): ?object
    {
        return $this->db()->table($this->tables->table('tax_zone_members').' as m')
            ->join($this->tables->table('tax_zones').' as z', 'z.id', '=', 'm.tax_zone_id')
            ->where('m.country', $address->country)
            ->where(static fn (Builder $region): Builder => $address->region === null
                ? $region->whereNull('m.region')
                : $region->where('m.region', $address->region)->orWhereNull('m.region'))
            ->orderByRaw('m.region is null')
            ->select('z.id', 'z.code', 'z.name')
            ->first();
    }

    /**
     * @param  list<object>  $zones  zone rows
     * @return list<TaxZoneView> in the order of the rows
     */
    public function views(array $zones): array
    {
        if ($zones === []) {
            return [];
        }

        $members = [];

        $rows = $this->db()->table($this->tables->table('tax_zone_members'))
            ->whereIn('tax_zone_id', array_map(static fn (object $zone): string => Rows::string($zone, 'id'), $zones))
            ->orderBy('country')
            ->orderByRaw('region asc nulls first')
            ->get(['tax_zone_id', 'country', 'region']);

        foreach ($rows as $row) {
            $members[Rows::string($row, 'tax_zone_id')][] = new TaxZoneMember(Rows::string($row, 'country'), Rows::nullableString($row, 'region'));
        }

        return array_map(
            static fn (object $zone): TaxZoneView => new TaxZoneView(
                new TaxZoneId(Rows::string($zone, 'id')),
                Rows::string($zone, 'code'),
                Rows::string($zone, 'name'),
                $members[Rows::string($zone, 'id')] ?? [],
            ),
            $zones,
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
