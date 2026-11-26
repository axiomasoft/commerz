<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Contracts;

use Commerz\Catalog\Attributes\Exceptions\AttributeNotFilterable;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Catalog\Search\Data\CatalogSearchResult;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/**
 * Search and faceted browsing SPI for the catalog.
 *
 * Commerz ships a built-in PostgreSQL adapter over `cmz_attribute_index`. Downstream hosts can bind an external search
 * engine adapter (Meilisearch, OpenSearch, Elasticsearch, Typesense) to this interface; the catalog outbox subscriber
 * will forward index and removal batches automatically.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface CatalogSearchPort
{
    /**
     * Searches catalog products with filters, optional disjunctive facet counts, sorting, and keyset pagination.
     *
     * @throws AttributeNotFound|AttributeNotFilterable|InvalidAttributeValue|InvalidCommand|UnsupportedContribution
     */
    public function search(CatalogSearchQuery $query): CatalogSearchResult;

    /**
     * Indexes or refreshes the given products in the search backend.
     *
     * @param  list<ProductId>  $productIds
     */
    public function indexProducts(array $productIds): void;

    /**
     * Removes the given products from the search backend.
     *
     * @param  list<ProductId>  $productIds
     */
    public function removeProducts(array $productIds): void;
}
