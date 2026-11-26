<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Measures\LocalizedText;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Shipping\Common\Data\PostalCode;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Shipping\Zones\Enums\ZoneStatus;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Packman\Attributes\Internal;

/**
 * Loads, in one query (one consistent snapshot), every method that is not archived with its assignment to the
 * context's channel, its tariffs and the members of each tariff's active zone that match the address. A member
 * matches by rank (knowledge/shipping-design#zones): its country 0, its region 50, a postal prefix of the country
 * that starts the address's postal code 100 + the prefix length. The postal code goes through
 * `PostalCode::normalize` and into the query only as a parameter; a prefix holds only `[A-Z0-9]`, so `like` sees
 * no wildcard but the appended `%`.
 */
#[Internal]
final readonly class CandidateRates
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /** @return list<CandidateRate> one per method and tariff (one with no tariff for a method without any) */
    public function load(CommerceContext $context, Address $address, ?string $methodCode = null): array
    {
        $postal = PostalCode::normalize($address->postalCode);

        $query = $this->connections->connection($this->config->connection)
            ->table($this->tables->table('shipping_methods').' as m')
            ->leftJoin($this->tables->table('shipping_method_channels').' as mc', static function (JoinClause $join) use ($context): void {
                $join->on('mc.shipping_method_id', '=', 'm.id')->where('mc.channel_id', '=', $context->channel->value);
            })
            ->leftJoin($this->tables->table('shipping_rates').' as r', 'r.shipping_method_id', '=', 'm.id')
            ->leftJoin($this->tables->table('shipping_zones').' as z', static function (JoinClause $join): void {
                $join->on('z.id', '=', 'r.shipping_zone_id')->where('z.status', '=', ZoneStatus::Active->value);
            })
            ->leftJoin($this->tables->table('shipping_zone_members').' as zm', static function (JoinClause $join) use ($address, $postal): void {
                $join->on('zm.shipping_zone_id', '=', 'z.id')
                    ->where('zm.country', '=', $address->country)
                    ->where(static function (Builder $match) use ($address, $postal): void {
                        $match->where(static fn (Builder $country): Builder => $country->whereNull('zm.region')->whereNull('zm.postal_prefix'));

                        if ($address->region !== null) {
                            $match->orWhere('zm.region', '=', $address->region);
                        }

                        if ($postal !== null) {
                            $match->orWhereRaw("? like zm.postal_prefix || '%'", [$postal]);
                        }
                    });
            })
            ->where('m.status', '<>', MethodStatus::Archived->value)
            ->orderBy('m.code')
            ->orderBy('r.id')
            ->orderBy('zm.id')
            ->select([
                'm.code as method_code', 'm.name', 'm.carrier', 'm.delivery_mode', 'm.status', 'm.position', 'mc.channel_id',
                'r.id as rate_id', 'r.currency', 'r.rate_key', 'r.rate_version', 'r.params', 'r.free_above_minor',
                'r.tax_included', 'r.eta_days', 'z.code as zone_code', 'zm.id as member_id', 'zm.region as member_region',
                'zm.postal_prefix as member_prefix',
            ]);

        if ($methodCode !== null) {
            $query->where('m.code', '=', $methodCode);
        }

        /** @var array<string, array{row: object, rank: int|null}> $candidates */
        $candidates = [];

        foreach ($query->get()->all() as $row) {
            $key = Rows::string($row, 'method_code').'|'.(Rows::nullableString($row, 'rate_id') ?? '');
            $rank = self::rank($row);
            $best = $candidates[$key]['rank'] ?? null;
            $candidates[$key] = ['row' => $row, 'rank' => $best === null || ($rank !== null && $rank > $best) ? $rank : $best];
        }

        return array_values(array_map(static function (array $candidate): CandidateRate {
            $row = $candidate['row'];
            $rateId = Rows::nullableString($row, 'rate_id');

            return new CandidateRate(
                Rows::string($row, 'method_code'),
                new LocalizedText(Rows::strings($row, 'name')),
                Rows::string($row, 'carrier'),
                MethodStatus::from(Rows::string($row, 'status')),
                Rows::int($row, 'position'),
                Rows::nullableString($row, 'channel_id') !== null,
                $rateId,
                Rows::nullableString($row, 'currency'),
                Rows::nullableString($row, 'rate_key'),
                Rows::nullableInt($row, 'rate_version'),
                $rateId === null ? [] : Rows::json($row, 'params'),
                Rows::nullableInt($row, 'free_above_minor'),
                Rows::nullableBool($row, 'tax_included') ?? false,
                Rows::nullableInt($row, 'eta_days'),
                Rows::nullableString($row, 'zone_code'),
                $candidate['rank'],
                DeliveryMode::from(Rows::nullableString($row, 'delivery_mode') ?? DeliveryMode::Shipping->value),
            );
        }, $candidates));
    }

    /** The rank of the row's matching member, or null when no member matched. */
    private static function rank(object $row): ?int
    {
        if (Rows::nullableInt($row, 'member_id') === null) {
            return null;
        }

        $prefix = Rows::nullableString($row, 'member_prefix');

        return match (true) {
            $prefix !== null => 100 + strlen($prefix),
            Rows::nullableString($row, 'member_region') !== null => 50,
            default => 0,
        };
    }
}
