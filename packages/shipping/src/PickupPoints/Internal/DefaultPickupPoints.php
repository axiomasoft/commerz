<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Contracts\PickupPointProvider;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Shipping\Methods\Exceptions\MethodArchived;
use Commerz\Shipping\PickupPoints\Contracts\PickupPoints;
use Commerz\Shipping\PickupPoints\Data\PickupPointView;
use Commerz\Shipping\PickupPoints\Data\UpsertPickupPoint;
use Commerz\Shipping\PickupPoints\Enums\PickupPointStatus;
use Commerz\Shipping\PickupPoints\Exceptions\PickupPointNotFound;
use Commerz\Shipping\PickupPoints\Exceptions\PickupPointOversized;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

#[Internal]
final readonly class DefaultPickupPoints implements PickupPoints
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Contributions $contributions,
        private Clock $clock,
    ) {}

    public function upsert(UpsertPickupPoint $command): PickupPointView
    {
        return $this->transactions->run(function () use ($command): PickupPointView {
            $table = $this->tables->table('shipping_pickup_points');
            $db = $this->db();
            $now = PgTimestamp::now($this->clock);
            $city = trim((string) $command->address->city);

            $existing = $db->table($table)
                ->where('provider', $command->provider)
                ->where('code', $command->code)
                ->lockForUpdate()
                ->first();

            $payload = [
                'name' => json_encode($command->name->texts, JSON_THROW_ON_ERROR),
                'mode' => $command->mode->value,
                'country' => $command->address->country,
                'region' => $command->address->region,
                'city' => $city,
                'city_normalized' => mb_strtolower($city, 'UTF-8'),
                'postal_code' => $command->address->postalCode,
                'address_lines' => json_encode($command->address->lines, JSON_THROW_ON_ERROR),
                'phone' => $command->address->phone,
                'latitude_microdeg' => $command->coordinates?->latitudeMicrodeg,
                'longitude_microdeg' => $command->coordinates?->longitudeMicrodeg,
                'is_locker' => $command->isLocker,
                'max_weight_grams' => $command->limits?->maxWeightGrams,
                'max_length_mm' => $command->limits?->maxLengthMm,
                'max_width_mm' => $command->limits?->maxWidthMm,
                'max_height_mm' => $command->limits?->maxHeightMm,
                'opening_hours' => $command->openingHours,
                'status' => $command->status->value,
                'meta' => $command->meta === [] ? '{}' : json_encode($command->meta, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ];

            if ($existing !== null) {
                $db->table($table)
                    ->where('id', Rows::string($existing, 'id'))
                    ->update($payload);
            } else {
                $db->table($table)->insert([
                    'id' => Uuid7::generate(),
                    'provider' => $command->provider,
                    'code' => $command->code,
                    'created_at' => $now,
                ] + $payload);
            }

            return $this->findView($command->provider, $command->code);
        });
    }

    public function enable(string $provider, string $code): PickupPointView
    {
        return $this->transition($provider, $code, PickupPointStatus::Active);
    }

    public function disable(string $provider, string $code): PickupPointView
    {
        return $this->transition($provider, $code, PickupPointStatus::Disabled);
    }

    public function archive(string $provider, string $code): PickupPointView
    {
        return $this->transition($provider, $code, PickupPointStatus::Archived);
    }

    public function findView(string $provider, string $code): PickupPointView
    {
        $row = $this->db()->table($this->tables->table('shipping_pickup_points'))
            ->where('provider', $provider)
            ->where('code', $code)
            ->first();

        return $row === null
            ? throw new PickupPointNotFound("{$provider}:{$code}")
            : DatabasePickupPointProvider::hydrateView($row);
    }

    public function find(string $pointId, CommerceContext $context, ?string $provider = null): PickupPoint
    {
        if ($provider !== null && $this->contributions->has(PickupPointProvider::KIND, $provider)) {
            $found = $this->contributions->get(PickupPointProvider::KIND, $provider, PickupPointProvider::class)->find($pointId, $context);
            if ($found instanceof PickupPoint) {
                return $found;
            }
        }

        if ($provider !== null && $this->contributions->has(PickupPointProvider::KIND, DatabasePickupPointProvider::KEY)) {
            $row = $this->db()->table($this->tables->table('shipping_pickup_points'))
                ->where('status', PickupPointStatus::Active->value)
                ->where('provider', $provider)
                ->where('code', $pointId)
                ->first();

            if ($row !== null) {
                return DatabasePickupPointProvider::hydrateView($row)->toPickupPoint($context->locale);
            }
        }

        foreach ($this->contributions->keys(PickupPointProvider::KIND) as $key) {
            $candidate = $this->contributions->get(PickupPointProvider::KIND, $key, PickupPointProvider::class)->find($pointId, $context);

            if ($candidate instanceof PickupPoint && ($provider === null || $candidate->provider === $provider)) {
                return $candidate;
            }
        }

        throw new PickupPointNotFound($provider === null ? $pointId : "{$provider}:{$pointId}");
    }

    public function search(PickupPointQuery $query): array
    {
        $collected = [];
        $seen = [];

        foreach ($this->contributions->keys(PickupPointProvider::KIND) as $key) {
            if ($query->provider !== null && $key !== DatabasePickupPointProvider::KEY && $key !== $query->provider) {
                continue;
            }

            $provider = $this->contributions->get(PickupPointProvider::KIND, $key, PickupPointProvider::class);

            foreach ($provider->search($query) as $point) {
                $dedupeKey = $point->provider.'|'.$point->id;

                if (! isset($seen[$dedupeKey])) {
                    $seen[$dedupeKey] = true;
                    $collected[] = $point;
                }
            }
        }

        return PickupPointFilter::apply($collected, $query);
    }

    public function validateSelection(
        PickupPointDestination $destination,
        Parcel $parcel,
        CommerceContext $context,
        array $items = [],
    ): PickupPoint {
        $point = $this->find($destination->pointId, $context, $destination->provider);

        if ($point->limits === null) {
            return $point;
        }

        if ($point->limits->maxWeightGrams !== null && $parcel->weightGrams > $point->limits->maxWeightGrams) {
            throw new PickupPointOversized(
                $point->id,
                $point->provider,
                "parcel weight {$parcel->weightGrams} g exceeds locker limit {$point->limits->maxWeightGrams} g",
            );
        }

        if (! $point->accepts($parcel, $items)) {
            throw new PickupPointOversized(
                $point->id,
                $point->provider,
                'parcel or item dimensions exceed locker compartment capacity',
            );
        }

        return $point;
    }

    private function transition(string $provider, string $code, PickupPointStatus $target): PickupPointView
    {
        return $this->transactions->run(function () use ($provider, $code, $target): PickupPointView {
            $table = $this->tables->table('shipping_pickup_points');
            $row = $this->db()->table($table)
                ->where('provider', $provider)
                ->where('code', $code)
                ->lockForUpdate()
                ->first() ?? throw new PickupPointNotFound("{$provider}:{$code}");

            $current = PickupPointStatus::from(Rows::string($row, 'status'));

            if ($current === $target) {
                return DatabasePickupPointProvider::hydrateView($row);
            }

            if ($current === PickupPointStatus::Archived) {
                throw new MethodArchived("{$provider}:{$code}");
            }

            $this->db()->table($table)
                ->where('id', Rows::string($row, 'id'))
                ->update([
                    'status' => $target->value,
                    'updated_at' => PgTimestamp::now($this->clock),
                ]);

            return $this->findView($provider, $code);
        });
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
