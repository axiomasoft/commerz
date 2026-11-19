<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Internal;

use Commerz\Catalog\Common\Internal\Clock;
use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Catalog\Listings\Contracts\Listings;
use Commerz\Catalog\Listings\Data\ListingView;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Listings\Data\UnpublishProduct;
use Commerz\Catalog\Listings\Enums\ListingStatus;
use Commerz\Catalog\Listings\Events\ProductPublished;
use Commerz\Catalog\Listings\Events\ProductUnpublished;
use Commerz\Catalog\Listings\Exceptions\ListingNotFound;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/**
 * The channel belongs to the core: it is checked through `Channels`, never joined or referenced by a foreign key.
 * Archiving a channel after a publication is not blocked across packages; such a listing is harmless.
 */
#[Internal]
final readonly class DatabaseListings implements Listings
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private Channels $channels,
    ) {}

    public function publish(PublishProduct $command): ListingView
    {
        return $this->transactions->run(function () use ($command): ListingView {
            $product = $this->lockProduct($command->product);

            if (Rows::string($product, 'status') === ProductStatus::Archived->value) {
                throw new ProductArchived(Rows::string($product, 'handle'));
            }

            $this->channels->assertActive($command->channel);

            $from = self::timestamp($command->availableFrom);
            $until = self::timestamp($command->availableUntil);
            $current = $this->row($command->product, $command->channel);

            if ($current !== null && $this->view($current)->status === ListingStatus::Published
                && self::same($current, 'available_from', $command->availableFrom)
                && self::same($current, 'available_until', $command->availableUntil)) {
                return $this->view($current);
            }

            $now = Clock::now();

            $this->db()->table($this->tables->table('listings'))->upsert(
                [[
                    'product_id' => $command->product->value,
                    'channel_id' => $command->channel->value,
                    'status' => ListingStatus::Published->value,
                    'available_from' => $from,
                    'available_until' => $until,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['product_id', 'channel_id'],
                ['status', 'available_from', 'available_until', 'updated_at'],
            );

            $view = $this->find($command->product, $command->channel);
            $this->events->dispatch(new ProductPublished($view));

            return $view;
        });
    }

    public function unpublish(UnpublishProduct $command): ListingView
    {
        return $this->transactions->run(function () use ($command): ListingView {
            $this->lockProduct($command->product);
            $row = $this->row($command->product, $command->channel)
                ?? throw new ListingNotFound($command->product->value, $command->channel->value);

            if (Rows::string($row, 'status') === ListingStatus::Unpublished->value) {
                return $this->view($row);
            }

            $this->db()->table($this->tables->table('listings'))
                ->where('product_id', $command->product->value)
                ->where('channel_id', $command->channel->value)
                ->update(['status' => ListingStatus::Unpublished->value, 'updated_at' => Clock::now()]);

            $view = $this->find($command->product, $command->channel);
            $this->events->dispatch(new ProductUnpublished($view));

            return $view;
        });
    }

    public function find(ProductId $product, ChannelId $channel): ListingView
    {
        return $this->view($this->row($product, $channel) ?? throw new ListingNotFound($product->value, $channel->value));
    }

    public function ofProduct(ProductId $product): array
    {
        $db = $this->db();

        if (! $db->table($this->tables->table('products'))->where('id', $product->value)->exists()) {
            throw new ProductNotFound($product->value);
        }

        $rows = $db->table($this->tables->table('listings'))
            ->where('product_id', $product->value)
            ->orderBy('channel_id')
            ->get()
            ->all();

        return array_values(array_map($this->view(...), $rows));
    }

    /** The product row, `for no key update`: publications of one product run one after another. */
    private function lockProduct(ProductId $id): object
    {
        $row = $this->db()->table($this->tables->table('products'))->where('id', $id->value)->lock('for no key update')->first();

        return $row ?? throw new ProductNotFound($id->value);
    }

    private function row(ProductId $product, ChannelId $channel): ?object
    {
        return $this->db()->table($this->tables->table('listings'))
            ->where('product_id', $product->value)
            ->where('channel_id', $channel->value)
            ->first();
    }

    private function view(object $row): ListingView
    {
        return new ListingView(
            new ProductId(Rows::string($row, 'product_id')),
            new ChannelId(Rows::string($row, 'channel_id')),
            ListingStatus::from(Rows::string($row, 'status')),
            self::date($row, 'available_from'),
            self::date($row, 'available_until'),
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        );
    }

    private static function date(object $row, string $column): ?DateTimeImmutable
    {
        $value = Rows::nullableString($row, $column);

        return $value === null ? null : new DateTimeImmutable($value);
    }

    private static function same(object $row, string $column, ?DateTimeImmutable $value): bool
    {
        return self::timestamp(self::date($row, $column)) === self::timestamp($value);
    }

    private static function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
