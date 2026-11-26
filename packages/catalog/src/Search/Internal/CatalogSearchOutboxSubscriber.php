<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Internal;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Search\Contracts\CatalogSearchPort;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Outbox\Events\IntegrationEventDelivered;
use Packman\Attributes\Internal;

/**
 * Outbox subscriber that keeps external `CatalogSearchPort` adapters synchronized with catalog mutations.
 *
 * Handles both single-entity events (`catalog.product_*`, `catalog.variant_*`) and compressed batch events
 * (`catalog.products_upserted`, `catalog.variants_upserted`), forwarding deduplicated `ProductId` batches to
 * `CatalogSearchPort::indexProducts()` and `CatalogSearchPort::removeProducts()`.
 */
#[Internal]
final readonly class CatalogSearchOutboxSubscriber
{
    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(
        private CatalogSearchPort $search,
    ) {}

    public function handle(IntegrationEventDelivered $delivered): void
    {
        $this->syncEvent($delivered->event);
    }

    public function syncEvent(IntegrationEvent $event): void
    {
        $payload = $event->payload;

        match ($event->type) {
            'catalog.product_created',
            'catalog.product_published',
            'catalog.product_unpublished' => $this->dispatchIndex(self::singleId($payload, 'product_id')),
            'catalog.product_updated' => self::isArchived($payload)
                ? $this->dispatchRemove(self::singleId($payload, 'product_id'))
                : $this->dispatchIndex(self::singleId($payload, 'product_id')),
            'catalog.product_archived',
            'catalog.product_removed',
            'catalog.product_deleted' => $this->dispatchRemove(self::singleId($payload, 'product_id')),
            'catalog.variant_created',
            'catalog.variant_updated' => $this->dispatchIndex(self::singleId($payload, 'product_id')),
            'catalog.products_upserted' => $this->syncBatchProducts($payload),
            'catalog.variants_upserted' => $this->dispatchIndex(self::idList($payload, 'product_ids')),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function syncBatchProducts(array $payload): void
    {
        $archived = self::idList($payload, 'archived_ids');
        $archivedSet = [];

        foreach ($archived as $id) {
            $archivedSet[$id->value] = true;
        }

        $toIndex = [];

        foreach (self::idList($payload, 'product_ids') as $id) {
            if (! isset($archivedSet[$id->value])) {
                $toIndex[] = $id;
            }
        }

        $this->dispatchIndex($toIndex);
        $this->dispatchRemove($archived);
    }

    /** @param  list<ProductId>  $ids */
    private function dispatchIndex(array $ids): void
    {
        if ($ids !== []) {
            $this->search->indexProducts($ids);
        }
    }

    /** @param  list<ProductId>  $ids */
    private function dispatchRemove(array $ids): void
    {
        if ($ids !== []) {
            $this->search->removeProducts($ids);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ProductId>
     */
    private static function singleId(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || preg_match(self::UUID_PATTERN, $value) !== 1) {
            return [];
        }

        return [new ProductId($value)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ProductId>
     */
    private static function idList(array $payload, string $key): array
    {
        $raw = $payload[$key] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $seen = [];
        $ids = [];

        foreach ($raw as $item) {
            if (! is_string($item) || preg_match(self::UUID_PATTERN, $item) !== 1 || isset($seen[$item])) {
                continue;
            }

            $seen[$item] = true;
            $ids[] = new ProductId($item);
        }

        return $ids;
    }

    /** @param  array<string, mixed>  $payload */
    private static function isArchived(array $payload): bool
    {
        return ($payload['status'] ?? null) === ProductStatus::Archived->value;
    }
}
