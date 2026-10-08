<?php

declare(strict_types=1);

namespace Commerz\Stores\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\ChannelSettings;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\ListChannels;
use Commerz\Stores\Data\UpdateChannel;
use Commerz\Stores\Enums\ChannelStatus;
use Commerz\Stores\Enums\StoreStatus;
use Commerz\Stores\Events\ChannelCreated;
use Commerz\Stores\Events\ChannelUpdated;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Commerz\Stores\Exceptions\DuplicateCode;
use Commerz\Stores\Exceptions\StoreNotFound;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class DatabaseChannels implements Channels
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
    ) {}

    public function create(CreateChannel $command): ChannelView
    {
        return $this->transactions->run(function () use ($command): ChannelView {
            $db = $this->db();

            // Shared lock: a concurrent archive of the store waits, so no active channel is born in an archived store.
            $store = $db->table($this->tables->table('stores'))->where('id', $command->store->value)->lock('for share')->first();

            if ($store === null || (get_object_vars($store)['status'] ?? null) !== StoreStatus::Active->value) {
                throw new StoreNotFound($command->store->value);
            }

            $id = Uuid7::generate();
            $now = self::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('channels'))->insert([
                    'id' => $id,
                    'store_id' => $command->store->value,
                    'code' => $command->code,
                    'name' => $command->name,
                    'currencies' => self::codes($command->currencies),
                    'default_currency' => $command->defaultCurrency->code,
                    'locales' => json_encode($command->locales, JSON_THROW_ON_ERROR),
                    'default_locale' => $command->defaultLocale,
                    'prices_include_tax' => $command->pricesIncludeTax,
                    'status' => ChannelStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Channel', $command->code);
            }

            $view = $this->find(new ChannelId($id));
            $this->events->dispatch(new ChannelCreated($view));

            return $view;
        });
    }

    public function update(UpdateChannel $command): ChannelView
    {
        return $this->transactions->run(function () use ($command): ChannelView {
            $current = $this->lock($command->channel);

            if ($current->status === ChannelStatus::Archived) {
                throw new ChannelArchived($current->code);
            }

            $currencies = $command->currencies ?? $current->currencies;
            $locales = $command->locales ?? $current->locales;
            $defaultCurrency = $command->defaultCurrency ?? $current->defaultCurrency;
            $defaultLocale = $command->defaultLocale ?? $current->defaultLocale;

            if (! ChannelSettings::hasCurrency($currencies, $defaultCurrency)) {
                throw new CurrencyNotAllowed($current->code, $defaultCurrency->code);
            }

            if (! in_array($defaultLocale, $locales, true)) {
                throw new InvalidCommand('defaultLocale', "'{$defaultLocale}' is not among the channel locales", 'stores.invalid_locale');
            }

            $this->db()->table($this->tables->table('channels'))->where('id', $current->id->value)->update([
                'name' => $command->name ?? $current->name,
                'currencies' => self::codes($currencies),
                'default_currency' => $defaultCurrency->code,
                'locales' => json_encode($locales, JSON_THROW_ON_ERROR),
                'default_locale' => $defaultLocale,
                'prices_include_tax' => $command->pricesIncludeTax ?? $current->pricesIncludeTax,
                'updated_at' => self::now(),
            ]);

            $view = $this->find($current->id);
            $this->events->dispatch(new ChannelUpdated($view));

            return $view;
        });
    }

    public function archive(ArchiveChannel $command): ChannelView
    {
        return $this->transactions->run(function () use ($command): ChannelView {
            $current = $this->lock($command->channel);

            if ($current->status === ChannelStatus::Archived) {
                return $current;
            }

            $this->db()->table($this->tables->table('channels'))->where('id', $current->id->value)->update([
                'status' => ChannelStatus::Archived->value,
                'updated_at' => self::now(),
            ]);

            $view = $this->find($current->id);
            $this->events->dispatch(new ChannelUpdated($view));

            return $view;
        });
    }

    public function find(ChannelId $id): ChannelView
    {
        $row = $this->db()->table($this->tables->table('channels'))->where('id', $id->value)->first();

        return $row === null ? throw new ChannelNotFound($id->value) : StoreRows::channel($row);
    }

    public function list(ListChannels $query): Page
    {
        $builder = $this->db()->table($this->tables->table('channels'))->orderBy('code')->limit($query->first + 1);

        if ($query->store !== null) {
            $builder->where('store_id', $query->store->value);
        }

        if (! $query->includeArchived) {
            $builder->where('status', ChannelStatus::Active->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(StoreRows::channel(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    public function assertActive(ChannelId $id, ?Currency $currency = null): ChannelView
    {
        $channel = $this->find($id);

        if ($channel->status === ChannelStatus::Archived) {
            throw new ChannelArchived($channel->code);
        }

        if ($currency instanceof Currency && ! $channel->allows($currency)) {
            throw new CurrencyNotAllowed($channel->code, $currency->code);
        }

        return $channel;
    }

    private function lock(ChannelId $id): ChannelView
    {
        $row = $this->db()->table($this->tables->table('channels'))->where('id', $id->value)->lock('for update')->first();

        return $row === null ? throw new ChannelNotFound($id->value) : StoreRows::channel($row);
    }

    /** @param  list<Currency>  $currencies */
    private static function codes(array $currencies): string
    {
        return json_encode(array_map(static fn (Currency $currency): string => $currency->code, $currencies), JSON_THROW_ON_ERROR);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }

    private static function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
