<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Schema\TableNames;
use Commerz\Shipping\Common\Internal\Clock;
use Commerz\Shipping\Common\Internal\Rows;
use Commerz\Shipping\Configuration\ShippingOptions;
use Commerz\Shipping\Methods\Data\MethodId;
use Commerz\Shipping\Methods\Data\MethodView;
use Commerz\Shipping\Methods\Data\RateView;
use Commerz\Shipping\Methods\Data\ZoneRate;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;
use Commerz\Shipping\Rates\Internal\RateCodec;
use Commerz\Shipping\Rates\Internal\StoredRate;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/** Reads and writes the channel and tariff rows of methods and turns method rows into views. */
#[Internal]
final readonly class MethodRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private ShippingOptions $shipping,
        private RateCodec $codec,
    ) {}

    /**
     * @param  list<object>  $rows
     * @return list<MethodView> in the order of the rows
     */
    public function views(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
        $channels = $this->channelsOf($ids);
        $rates = $this->ratesOf($ids);

        return array_map(static function (object $row) use ($channels, $rates): MethodView {
            $id = Rows::string($row, 'id');

            return new MethodView(
                new MethodId($id),
                Rows::string($row, 'code'),
                new LocalizedText(Rows::strings($row, 'name')),
                Rows::string($row, 'carrier'),
                MethodStatus::from(Rows::string($row, 'status')),
                Rows::int($row, 'position'),
                $channels[$id] ?? [],
                $rates[$id] ?? [],
                Rows::date($row, 'updated_at'),
            );
        }, $rows);
    }

    /**
     * @param  list<ChannelId>  $channels
     * @return int how many were new
     */
    public function insertChannels(string $method, array $channels): int
    {
        if ($channels === []) {
            return 0;
        }

        $now = Clock::now();

        return $this->db()->table($this->tables->table('shipping_method_channels'))->insertOrIgnore(array_map(
            static fn (ChannelId $channel): array => [
                'shipping_method_id' => $method,
                'channel_id' => $channel->value,
                'created_at' => $now,
            ],
            $channels,
        ));
    }

    /**
     * @param  list<ZoneRate>  $rates
     * @param  list<StoredRate>  $stored  the encoded rule of each rate, in the same order
     */
    public function insertRates(string $method, array $rates, array $stored): void
    {
        if ($rates === []) {
            return;
        }

        $now = Clock::now();
        $rows = [];

        foreach ($rates as $index => $rate) {
            $rows[] = [
                'id' => Uuid7::generate(),
                'shipping_method_id' => $method,
                'shipping_zone_id' => $rate->zone->value,
                'currency' => $rate->currency->code,
                'rate_key' => $stored[$index]->key,
                'rate_version' => $stored[$index]->version,
                // An empty map must stay a JSON object (`{}`, not `[]`) for the column's object check.
                'params' => $stored[$index]->params->toArray() === [] ? '{}' : json_encode($stored[$index]->params->toArray(), JSON_THROW_ON_ERROR),
                'free_above_minor' => $rate->freeAbove?->amountMinor,
                'tax_included' => $rate->taxIncluded ?? $this->shipping->taxIncluded,
                'eta_days' => $rate->etaDays,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->db()->table($this->tables->table('shipping_rates'))->insert($rows);
    }

    /**
     * Whether the method already has exactly these tariffs.
     *
     * @param  list<ZoneRate>  $rates
     * @param  list<StoredRate>  $stored
     */
    public function sameRates(string $method, array $rates, array $stored): bool
    {
        $current = $this->db()->table($this->tables->table('shipping_rates'))
            ->where('shipping_method_id', $method)
            ->get()
            ->all();

        if (count($current) !== count($rates)) {
            return false;
        }

        $byPair = [];

        foreach ($current as $row) {
            $byPair[Rows::string($row, 'shipping_zone_id').'|'.Rows::string($row, 'currency')] = $row;
        }

        foreach ($rates as $index => $rate) {
            $row = $byPair[$rate->zone->value.'|'.$rate->currency->code] ?? null;

            if ($row === null
                || Rows::string($row, 'rate_key') !== $stored[$index]->key
                || Rows::int($row, 'rate_version') !== $stored[$index]->version
                || Rows::nullableInt($row, 'free_above_minor') !== $rate->freeAbove?->amountMinor
                || Rows::bool($row, 'tax_included') !== ($rate->taxIncluded ?? $this->shipping->taxIncluded)
                || Rows::nullableInt($row, 'eta_days') !== $rate->etaDays
                || self::canonical($row) !== $stored[$index]->params->toArray()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $methods
     * @return array<string, list<ChannelId>> by method id, by channel id
     */
    private function channelsOf(array $methods): array
    {
        $rows = $this->db()->table($this->tables->table('shipping_method_channels'))
            ->whereIn('shipping_method_id', $methods)
            ->orderBy('channel_id')
            ->get(['shipping_method_id', 'channel_id'])
            ->all();

        $byMethod = [];

        foreach ($rows as $row) {
            $byMethod[Rows::string($row, 'shipping_method_id')][] = new ChannelId(Rows::string($row, 'channel_id'));
        }

        return $byMethod;
    }

    /**
     * @param  list<string>  $methods
     * @return array<string, list<RateView>> by method id, by zone code and currency
     */
    private function ratesOf(array $methods): array
    {
        $rows = $this->db()->table($this->tables->table('shipping_rates').' as r')
            ->join($this->tables->table('shipping_zones').' as z', 'z.id', '=', 'r.shipping_zone_id')
            ->whereIn('r.shipping_method_id', $methods)
            ->orderBy('z.code')
            ->orderBy('r.currency')
            ->select('r.*', 'z.code as zone_code')
            ->get()
            ->all();

        $byMethod = [];

        foreach ($rows as $row) {
            $currency = Currency::of(Rows::string($row, 'currency'));
            $stored = self::storedParams($row);
            $loaded = $this->codec->decodeRow(Rows::string($row, 'rate_key'), Rows::int($row, 'rate_version'), $stored, new RateScope($currency));
            $freeAbove = Rows::nullableInt($row, 'free_above_minor');

            $byMethod[Rows::string($row, 'shipping_method_id')][] = new RateView(
                new ZoneId(Rows::string($row, 'shipping_zone_id')),
                Rows::string($row, 'zone_code'),
                $currency,
                new RateSpec(Rows::string($row, 'rate_key'), $loaded->params?->toArray() ?? self::canonicalOrRaw($stored)),
                $freeAbove === null ? null : Money::ofMinor($freeAbove, $currency),
                Rows::bool($row, 'tax_included'),
                Rows::nullableInt($row, 'eta_days'),
                $loaded->reason,
            );
        }

        return $byMethod;
    }

    /** @return array<array-key, mixed> */
    private static function storedParams(object $row): array
    {
        return Rows::json($row, 'params');
    }

    /**
     * The stored parameters in canonical form, or null when they are not.
     *
     * @return array<string, mixed>|null
     */
    private static function canonical(object $row): ?array
    {
        try {
            return RateParams::fromStored(self::storedParams($row))->toArray();
        } catch (InvalidRateConfig) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function canonicalOrRaw(array $stored): array
    {
        try {
            return RateParams::fromStored($stored)->toArray();
        } catch (InvalidRateConfig) {
            /** @var array<string, mixed> $stored */
            return $stored;
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
