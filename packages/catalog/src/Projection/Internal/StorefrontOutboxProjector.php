<?php

declare(strict_types=1);

namespace Commerz\Catalog\Projection\Internal;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Configuration\CommerzOptions;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/**
 * Outbox consumer keeping `cmz_storefront_products` up to date on `catalog.*`, `pricing.*`, and `inventory.*` events.
 * Recomputing from committed state + core ports makes delivery idempotent and immune to out-of-order retries.
 */
#[Internal]
final readonly class StorefrontOutboxProjector implements OutboxConsumer
{
    private const string UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private DatabaseStorefrontProjection $projection,
    ) {}

    public function key(): string
    {
        return 'catalog.storefront_projection';
    }

    public function consume(IntegrationEvent $event): void
    {
        $prefix = strtok($event->type, '.');

        if (! in_array($prefix, ['catalog', 'pricing', 'inventory'], true)) {
            return;
        }

        $db = $this->connections->connection($this->config->connection);
        $productIds = $this->resolveProductIds($db, $event);
        $channelIds = $this->resolveChannelIds($event);

        if ($productIds !== []) {
            if ($channelIds !== []) {
                foreach ($channelIds as $channelId) {
                    $channel = new ChannelId($channelId);

                    foreach ($productIds as $productId) {
                        $this->projection->projectProduct(new ProductId($productId), $channel);
                    }
                }
            } else {
                foreach ($productIds as $productId) {
                    $this->projection->projectProduct(new ProductId($productId));
                }
            }

            return;
        }

        if ($channelIds !== []) {
            foreach ($channelIds as $channelId) {
                $this->projection->projectChannel(new ChannelId($channelId));
            }

            return;
        }

        // Broad pricing/inventory/catalog events without an explicit product or channel target refresh existing channels.
        if (in_array($prefix, ['pricing', 'inventory'], true)) {
            $existingChannels = $db->table($this->tables->table('storefront_products'))
                ->distinct()
                ->pluck('channel_id')
                ->all();

            foreach ($existingChannels as $channelId) {
                if (is_string($channelId) && self::isUuid($channelId)) {
                    $this->projection->projectChannel(new ChannelId($channelId));
                }
            }
        }
    }

    /**
     * @return list<string> deduplicated product UUIDs
     */
    private function resolveProductIds(ConnectionInterface $db, IntegrationEvent $event): array
    {
        $products = [];
        $variants = [];
        $skus = [];
        $handles = [];

        $this->collectFromRef($event->aggregateRef, $products, $variants);

        $payload = $event->payload;

        foreach (['product_id', 'product'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $id = strtolower($payload[$key]);
                if (self::isUuid($id)) {
                    $products[$id] = true;
                }
            }
        }

        if (isset($payload['product_ids']) && is_array($payload['product_ids'])) {
            foreach ($payload['product_ids'] as $value) {
                if (is_string($value) && self::isUuid(strtolower($value))) {
                    $products[strtolower($value)] = true;
                }
            }
        }

        foreach (['variant_id', 'variant'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && self::isUuid(strtolower($payload[$key]))) {
                $variants[strtolower($payload[$key])] = true;
            }
        }

        if (isset($payload['variant_ids']) && is_array($payload['variant_ids'])) {
            foreach ($payload['variant_ids'] as $value) {
                if (is_string($value) && self::isUuid(strtolower($value))) {
                    $variants[strtolower($value)] = true;
                }
            }
        }

        if (isset($payload['sellable_ref']) && is_string($payload['sellable_ref'])) {
            $this->collectFromRef($payload['sellable_ref'], $products, $variants);
        }

        foreach (['sellables', 'sellable_refs'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $ref) {
                    if (is_string($ref)) {
                        $this->collectFromRef($ref, $products, $variants);
                    }
                }
            }
        }

        if (isset($payload['sku']) && is_string($payload['sku']) && $payload['sku'] !== '') {
            $skus[$payload['sku']] = true;
        }

        if (isset($payload['skus']) && is_array($payload['skus'])) {
            foreach ($payload['skus'] as $sku) {
                if (is_string($sku) && $sku !== '') {
                    $skus[$sku] = true;
                }
            }
        }

        if (isset($payload['handle']) && is_string($payload['handle']) && $payload['handle'] !== '') {
            $handles[$payload['handle']] = true;
        }

        if (isset($payload['handles']) && is_array($payload['handles'])) {
            foreach ($payload['handles'] as $handle) {
                if (is_string($handle) && $handle !== '') {
                    $handles[$handle] = true;
                }
            }
        }

        if ($variants !== []) {
            $rows = $db->table($this->tables->table('variants'))
                ->whereIn('id', array_keys($variants))
                ->pluck('product_id')
                ->all();

            foreach ($rows as $row) {
                if (is_string($row)) {
                    $products[$row] = true;
                }
            }
        }

        if ($skus !== []) {
            $rows = $db->table($this->tables->table('variants'))
                ->whereIn('sku', array_keys($skus))
                ->pluck('product_id')
                ->all();

            foreach ($rows as $row) {
                if (is_string($row)) {
                    $products[$row] = true;
                }
            }
        }

        if ($handles !== []) {
            $rows = $db->table($this->tables->table('products'))
                ->whereIn('handle', array_keys($handles))
                ->select('id')
                ->get();

            foreach ($rows as $row) {
                $products[Rows::string($row, 'id')] = true;
            }
        }

        return array_keys($products);
    }

    /**
     * @return list<string> deduplicated channel UUIDs
     */
    private function resolveChannelIds(IntegrationEvent $event): array
    {
        $channels = [];

        if (str_starts_with($event->aggregateRef, 'channel:')) {
            $id = strtolower(substr($event->aggregateRef, strlen('channel:')));
            if (self::isUuid($id)) {
                $channels[$id] = true;
            }
        }

        $payload = $event->payload;

        if (isset($payload['channel_id']) && is_string($payload['channel_id'])) {
            $id = strtolower($payload['channel_id']);
            if (self::isUuid($id)) {
                $channels[$id] = true;
            }
        }

        if (isset($payload['channel_ids']) && is_array($payload['channel_ids'])) {
            foreach ($payload['channel_ids'] as $value) {
                if (is_string($value) && self::isUuid(strtolower($value))) {
                    $channels[strtolower($value)] = true;
                }
            }
        }

        return array_keys($channels);
    }

    /**
     * @param  array<string, true>  $products
     * @param  array<string, true>  $variants
     */
    private function collectFromRef(string $ref, array &$products, array &$variants): void
    {
        if (str_starts_with($ref, 'product:')) {
            $id = strtolower(substr($ref, strlen('product:')));
            if (self::isUuid($id)) {
                $products[$id] = true;
            }
        } elseif (str_starts_with($ref, 'variant:')) {
            $id = strtolower(substr($ref, strlen('variant:')));
            if (self::isUuid($id)) {
                $variants[$id] = true;
            }
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(self::UUID, $value) === 1;
    }
}
