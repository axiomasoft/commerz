<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Measures\LocalizedText;
use Commerz\Ports\Delivery\Contracts\PickupPointProvider;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Coordinates;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointLimits;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Shipping\PickupPoints\Data\PickupPointId;
use Commerz\Shipping\PickupPoints\Data\PickupPointView;
use Commerz\Shipping\PickupPoints\Enums\PickupPointStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/** Built-in `PickupPointProvider` backed by the `shipping_pickup_points` table for merchant BOPIS and synced lockers. */
#[Internal]
final readonly class DatabasePickupPointProvider implements PickupPointProvider
{
    public const string KEY = 'database';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function search(PickupPointQuery $query): array
    {
        $builder = $this->db()->table($this->tables->table('shipping_pickup_points'))
            ->where('status', PickupPointStatus::Active->value)
            ->where('country', $query->country)
            ->orderBy('provider')
            ->orderBy('code');

        if ($query->provider !== null) {
            $builder->where('provider', $query->provider);
        }

        if ($query->mode !== null) {
            $builder->where('mode', $query->mode->value);
        }

        if ($query->onlyLockers) {
            $builder->where('is_locker', true);
        }

        if ($query->city !== null && trim($query->city) !== '') {
            $builder->where('city_normalized', mb_strtolower(trim($query->city), 'UTF-8'));
        }

        if ($query->parcel !== null) {
            $weight = $query->parcel->weightGrams;
            $builder->where(static function (Builder $w) use ($weight): void {
                $w->whereNull('max_weight_grams')->orWhere('max_weight_grams', '>=', $weight);
            });
        }

        $points = array_values(array_map(
            fn (object $row): PickupPoint => self::hydrateView($row)->toPickupPoint($query->context->locale),
            $builder->get()->all(),
        ));

        return PickupPointFilter::apply($points, $query);
    }

    public function find(string $pointId, CommerceContext $context): ?PickupPoint
    {
        $row = $this->db()->table($this->tables->table('shipping_pickup_points'))
            ->where('status', PickupPointStatus::Active->value)
            ->where('code', $pointId)
            ->orderBy('provider')
            ->first();

        return $row === null ? null : self::hydrateView($row)->toPickupPoint($context->locale);
    }

    public static function hydrateView(object $row): PickupPointView
    {
        $lat = Rows::nullableInt($row, 'latitude_microdeg');
        $lon = Rows::nullableInt($row, 'longitude_microdeg');
        $maxWeight = Rows::nullableInt($row, 'max_weight_grams');
        $maxLength = Rows::nullableInt($row, 'max_length_mm');
        $maxWidth = Rows::nullableInt($row, 'max_width_mm');
        $maxHeight = Rows::nullableInt($row, 'max_height_mm');

        $lines = [];
        foreach (Rows::json($row, 'address_lines') as $line) {
            if (is_string($line)) {
                $lines[] = $line;
            }
        }

        $meta = [];
        foreach (Rows::json($row, 'meta') as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $meta[$k] = $v;
            }
        }

        return new PickupPointView(
            id: new PickupPointId(Rows::string($row, 'id')),
            provider: Rows::string($row, 'provider'),
            code: Rows::string($row, 'code'),
            name: new LocalizedText(Rows::strings($row, 'name')),
            mode: DeliveryMode::from(Rows::string($row, 'mode')),
            address: new Address(
                country: Rows::string($row, 'country'),
                region: Rows::nullableString($row, 'region'),
                city: Rows::string($row, 'city'),
                postalCode: Rows::nullableString($row, 'postal_code'),
                lines: $lines,
                phone: Rows::nullableString($row, 'phone'),
            ),
            coordinates: $lat !== null && $lon !== null ? new Coordinates($lat, $lon) : null,
            limits: ($maxWeight !== null || $maxLength !== null || $maxWidth !== null || $maxHeight !== null)
                ? new PickupPointLimits($maxWeight, $maxLength, $maxWidth, $maxHeight)
                : null,
            isLocker: self::boolColumn($row, 'is_locker'),
            openingHours: Rows::nullableString($row, 'opening_hours'),
            status: PickupPointStatus::from(Rows::string($row, 'status')),
            meta: $meta,
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private static function boolColumn(object $row, string $column): bool
    {
        $raw = get_object_vars($row)[$column] ?? null;

        return is_bool($raw) ? $raw : Rows::int($row, $column) !== 0;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
