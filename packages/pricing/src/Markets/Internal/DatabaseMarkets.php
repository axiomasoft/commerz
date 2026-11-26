<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Common\Internal\Windows;
use Commerz\Pricing\Markets\Contracts\Markets;
use Commerz\Pricing\Markets\Data\ArchiveMarket;
use Commerz\Pricing\Markets\Data\CreateMarket;
use Commerz\Pricing\Markets\Data\ListMarkets;
use Commerz\Pricing\Markets\Data\MarketId;
use Commerz\Pricing\Markets\Data\MarketView;
use Commerz\Pricing\Markets\Data\RestoreMarket;
use Commerz\Pricing\Markets\Data\SetMarketCountries;
use Commerz\Pricing\Markets\Data\UpdateMarket;
use Commerz\Pricing\Markets\Enums\MarketStatus;
use Commerz\Pricing\Markets\Events\MarketChanged;
use Commerz\Pricing\Markets\Exceptions\MarketArchived;
use Commerz\Pricing\Markets\Exceptions\MarketCountryTaken;
use Commerz\Pricing\Markets\Exceptions\MarketNotFound;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\PriceLists\Enums\PriceListStatus;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;
use Override;
use Packman\Attributes\Internal;

/**
 * Persistence of `Market` aggregates. Country membership changes serialize on a channel-scoped advisory lock
 * (`commerz.market_countries:{table}:{channel}`) before modifying `market_countries`.
 */
#[Internal]
final readonly class DatabaseMarkets implements Markets
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private Channels $channels,
    ) {}

    #[Override]
    public function create(CreateMarket $command): MarketView
    {
        return $this->transactions->run(function () use ($command): MarketView {
            $this->lockCountries($command->channel->value);

            foreach ($command->allowedCurrencies as $currency) {
                $this->channels->assertActive($command->channel, $currency);
            }

            if ($command->defaultPriceListId instanceof PriceListId) {
                $this->assertValidDefaultPriceList($command->defaultPriceListId, $command->allowedCurrencies);
            }

            $db = $this->db();
            $id = Uuid7::generate();
            $now = PgTimestamp::now();

            try {
                $db->transaction(fn () => $db->table($this->tables->table('markets'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'channel_id' => $command->channel->value,
                    'default_currency' => $command->defaultCurrency->code,
                    'allowed_currencies' => self::encodeCurrencies($command->allowedCurrencies),
                    'tax_included' => $command->taxIncluded,
                    'default_price_list_id' => $command->defaultPriceListId?->value,
                    'status' => MarketStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Market', $command->code);
            }

            $this->insertCountries($id, $command->channel->value, $command->countries);
            $marketId = new MarketId($id);
            $this->events->dispatch(new MarketChanged($marketId));

            return $this->find($marketId);
        });
    }

    #[Override]
    public function update(UpdateMarket $command): MarketView
    {
        return $this->transactions->run(function () use ($command): MarketView {
            $row = $this->lockActive($command->market, 'for no key update');
            $channel = new ChannelId(Rows::string($row, 'channel_id'));

            $currentDefault = Currency::of(Rows::string($row, 'default_currency'));
            $currentAllowed = self::decodeCurrencies(Rows::string($row, 'allowed_currencies'));

            $nextDefault = $command->defaultCurrency ?? $currentDefault;
            $nextAllowed = $command->allowedCurrencies ?? $currentAllowed;

            if (! array_any($nextAllowed, static fn (Currency $c): bool => $c->equals($nextDefault))) {
                throw new InvalidCommand(
                    'defaultCurrency',
                    "'{$nextDefault->code}' must be included in allowedCurrencies",
                    'pricing.invalid_currencies',
                );
            }

            foreach ($nextAllowed as $currency) {
                $this->channels->assertActive($channel, $currency);
            }

            $currentPriceList = Rows::nullableString($row, 'default_price_list_id');
            $nextPriceList = match (true) {
                $command->clearDefaultPriceList => null,
                $command->defaultPriceListId instanceof PriceListId => $command->defaultPriceListId,
                $currentPriceList !== null => new PriceListId($currentPriceList),
                default => null,
            };

            if ($nextPriceList instanceof PriceListId) {
                $this->assertValidDefaultPriceList($nextPriceList, $nextAllowed);
            }

            $changes = [];

            if ($command->name !== null && $command->name !== Rows::string($row, 'name')) {
                $changes['name'] = $command->name;
            }

            if ($nextDefault->code !== $currentDefault->code) {
                $changes['default_currency'] = $nextDefault->code;
            }

            $encodedAllowed = self::encodeCurrencies($nextAllowed);
            if ($encodedAllowed !== self::encodeCurrencies($currentAllowed)) {
                $changes['allowed_currencies'] = $encodedAllowed;
            }

            if ($command->taxIncluded !== null && $command->taxIncluded !== self::boolColumn($row, 'tax_included')) {
                $changes['tax_included'] = $command->taxIncluded;
            }

            if ($nextPriceList?->value !== $currentPriceList) {
                $changes['default_price_list_id'] = $nextPriceList?->value;
            }

            if ($changes !== []) {
                $this->touch($command->market, $changes);
                $this->events->dispatch(new MarketChanged($command->market));
            }

            return $this->find($command->market);
        });
    }

    #[Override]
    public function setCountries(SetMarketCountries $command): MarketView
    {
        return $this->transactions->run(function () use ($command): MarketView {
            $existing = $this->db()->table($this->tables->table('markets'))->where('id', $command->market->value)->first()
                ?? throw new MarketNotFound($command->market->value);
            $channelId = Rows::string($existing, 'channel_id');

            $this->lockCountries($channelId);
            $this->lockActive($command->market, 'for no key update');

            $this->db()->table($this->tables->table('market_countries'))
                ->where('market_id', $command->market->value)
                ->delete();

            $this->insertCountries($command->market->value, $channelId, $command->countries);
            $this->touch($command->market, []);
            $this->events->dispatch(new MarketChanged($command->market));

            return $this->find($command->market);
        });
    }

    #[Override]
    public function archive(ArchiveMarket $command): MarketView
    {
        return $this->transactions->run(function () use ($command): MarketView {
            $row = $this->lock($command->market, 'for no key update');

            if (Rows::string($row, 'status') === MarketStatus::Active->value) {
                $this->touch($command->market, ['status' => MarketStatus::Archived->value]);
                $this->events->dispatch(new MarketChanged($command->market));
            }

            return $this->find($command->market);
        });
    }

    #[Override]
    public function restore(RestoreMarket $command): MarketView
    {
        return $this->transactions->run(function () use ($command): MarketView {
            $row = $this->lock($command->market, 'for no key update');

            if (Rows::string($row, 'status') === MarketStatus::Archived->value) {
                $this->touch($command->market, ['status' => MarketStatus::Active->value]);
                $this->events->dispatch(new MarketChanged($command->market));
            }

            return $this->find($command->market);
        });
    }

    #[Override]
    public function find(MarketId $id): MarketView
    {
        $row = $this->db()->table($this->tables->table('markets'))->where('id', $id->value)->first()
            ?? throw new MarketNotFound($id->value);

        return $this->view($row, $this->countriesOf([$id->value]));
    }

    #[Override]
    public function findByCode(string $code): MarketView
    {
        $row = $this->db()->table($this->tables->table('markets'))->where('code', $code)->first()
            ?? throw new MarketNotFound($code);
        $id = Rows::string($row, 'id');

        return $this->view($row, $this->countriesOf([$id]));
    }

    #[Override]
    public function resolveForCountry(ChannelId $channel, string $country): ?MarketView
    {
        $normalized = strtoupper(trim($country));

        if (preg_match('/\A[A-Z]{2}\z/', $normalized) !== 1) {
            return null;
        }

        $row = $this->db()->table($this->tables->table('markets').' as m')
            ->join($this->tables->table('market_countries').' as mc', 'mc.market_id', '=', 'm.id')
            ->where('m.channel_id', $channel->value)
            ->where('m.status', MarketStatus::Active->value)
            ->where('mc.country', $normalized)
            ->select('m.*')
            ->first();

        if ($row === null) {
            return null;
        }

        $id = Rows::string($row, 'id');

        return $this->view($row, $this->countriesOf([$id]));
    }

    #[Override]
    public function list(ListMarkets $query): Page
    {
        $builder = $this->db()->table($this->tables->table('markets'))
            ->orderBy('code')
            ->limit($query->first + 1);

        if ($query->status instanceof MarketStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->channel instanceof ChannelId) {
            $builder->where('channel_id', $query->channel->value);
        }

        if ($query->country !== null) {
            $builder->whereIn('id', $this->db()->table($this->tables->table('market_countries'))
                ->select('market_id')
                ->where('country', $query->country));
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $rows = array_slice($rows, 0, $query->first);
        $countries = $this->countriesOf(array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows));
        $views = array_map(fn (object $row): MarketView => $this->view($row, $countries), $rows);

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    /**
     * @param  list<Currency>  $allowedCurrencies
     */
    private function assertValidDefaultPriceList(PriceListId $id, array $allowedCurrencies): void
    {
        $row = $this->db()->table($this->tables->table('price_lists'))
            ->where('id', $id->value)
            ->lock('for share')
            ->first() ?? throw new PriceListNotFound($id->value);

        if (Rows::string($row, 'status') === PriceListStatus::Archived->value) {
            throw new PriceListArchived(Rows::string($row, 'code'));
        }

        $listCurrency = Rows::string($row, 'currency');

        if (! array_any($allowedCurrencies, static fn (Currency $c): bool => $c->code === $listCurrency)) {
            throw new InvalidCommand(
                'defaultPriceListId',
                "price list '".Rows::string($row, 'code')."' is in {$listCurrency}, which is not in allowedCurrencies",
                'pricing.currency_mismatch',
            );
        }
    }

    /** @param  list<string>  $countries */
    private function insertCountries(string $marketId, string $channelId, array $countries): void
    {
        $db = $this->db();

        try {
            $db->transaction(fn () => $db->table($this->tables->table('market_countries'))->insert(array_map(
                static fn (string $country): array => [
                    'market_id' => $marketId,
                    'channel_id' => $channelId,
                    'country' => $country,
                ],
                $countries,
            )));
        } catch (UniqueConstraintViolationException $e) {
            foreach ($countries as $country) {
                $owner = $this->marketOf($channelId, $country);

                if ($owner !== null) {
                    throw new MarketCountryTaken($channelId, $country, $owner);
                }
            }

            throw $e;
        }
    }

    private function marketOf(string $channelId, string $country): ?string
    {
        $code = $this->db()->table($this->tables->table('market_countries').' as mc')
            ->join($this->tables->table('markets').' as m', 'm.id', '=', 'mc.market_id')
            ->where('mc.channel_id', $channelId)
            ->where('mc.country', $country)
            ->value('m.code');

        return is_string($code) ? $code : null;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private function countriesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db()->table($this->tables->table('market_countries'))
            ->whereIn('market_id', $ids)
            ->orderBy('country')
            ->get(['market_id', 'country'])
            ->all();

        $byMarket = [];

        foreach ($rows as $row) {
            $byMarket[Rows::string($row, 'market_id')][] = Rows::string($row, 'country');
        }

        return $byMarket;
    }

    /** @param  array<string, list<string>>  $countries */
    private function view(object $row, array $countries): MarketView
    {
        $id = Rows::string($row, 'id');
        $defaultPriceList = Rows::nullableString($row, 'default_price_list_id');

        return new MarketView(
            id: new MarketId($id),
            code: Rows::string($row, 'code'),
            name: Rows::string($row, 'name'),
            channel: new ChannelId(Rows::string($row, 'channel_id')),
            countries: $countries[$id] ?? [],
            defaultCurrency: Currency::of(Rows::string($row, 'default_currency')),
            allowedCurrencies: self::decodeCurrencies(Rows::string($row, 'allowed_currencies')),
            taxIncluded: self::boolColumn($row, 'tax_included'),
            defaultPriceListId: $defaultPriceList === null ? null : new PriceListId($defaultPriceList),
            status: MarketStatus::from(Rows::string($row, 'status')),
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    /** @param  list<Currency>  $currencies */
    private static function encodeCurrencies(array $currencies): string
    {
        $codes = array_map(static fn (Currency $c): string => $c->code, $currencies);

        return json_encode($codes, JSON_THROW_ON_ERROR);
    }

    /** @return list<Currency> */
    private static function decodeCurrencies(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $currencies = [];

        foreach ($decoded as $code) {
            if (is_string($code)) {
                $currencies[] = Currency::of($code);
            }
        }

        return $currencies;
    }

    /** @param  array<string, mixed>  $changes */
    private function touch(MarketId $id, array $changes): void
    {
        $this->db()->table($this->tables->table('markets'))
            ->where('id', $id->value)
            ->update($changes + ['updated_at' => PgTimestamp::now()]);
    }

    private function lock(MarketId $id, string $mode): object
    {
        return $this->db()->table($this->tables->table('markets'))
            ->where('id', $id->value)
            ->lock($mode)
            ->first() ?? throw new MarketNotFound($id->value);
    }

    private function lockActive(MarketId $id, string $mode): object
    {
        $row = $this->lock($id, $mode);

        if (Rows::string($row, 'status') === MarketStatus::Archived->value) {
            throw new MarketArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    private static function boolColumn(object $row, string $column): bool
    {
        $values = get_object_vars($row);
        $raw = $values[$column] ?? null;

        return is_bool($raw) ? $raw : (bool) Rows::int($row, $column);
    }

    private function lockCountries(string $channelId): void
    {
        Windows::lock($this->db(), 'commerz.market_countries:'.$this->tables->table('market_countries').':'.$channelId);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
