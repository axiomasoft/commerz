<?php

declare(strict_types=1);

namespace Commerz\Testkit\Contracts;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Search\Contracts\CatalogSearchPort;
use Commerz\Catalog\Search\Data\CatalogSearchQuery;
use Commerz\Catalog\Search\Data\CatalogSearchResult;
use Commerz\Errors\CommerzException;
use Commerz\Errors\InvalidCommand;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Enums\FailurePolicy;
use Packman\Attributes\Api;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Certification contract suite every `CatalogSearchPort` adapter must pass.
 *
 * Verifies deterministic search reads, idempotent indexing/removal, page size bounds, typed error codes, and
 * `FailurePolicy` when exposed.
 *
 * @phpstan-require-extends TestCase
 */
#[Api(since: '0.1')]
trait AssertsSearchEngineCompliance
{
    abstract protected function searchEngine(): CatalogSearchPort;

    protected function complianceProductId(): ?ProductId
    {
        return null;
    }

    protected function invalidSearchQuery(): ?CatalogSearchQuery
    {
        return null;
    }

    protected function searchFailurePolicy(): FailurePolicy
    {
        $engine = $this->searchEngine();

        return $engine instanceof QuoteStagePolicy
            ? $engine->failurePolicy()
            : FailurePolicy::BlockOnFailure;
    }

    #[Test]
    public function searchReturnsDeterministicAndBoundedResult(): void
    {
        $engine = $this->searchEngine();
        $query = new CatalogSearchQuery(first: 10);

        $first = $engine->search($query);
        $second = $engine->search($query);

        self::assertInstanceOf(CatalogSearchResult::class, $first);
        self::assertGreaterThanOrEqual(0, $first->total);
        self::assertLessThanOrEqual($query->first, count($first->items()));
        self::assertSame($first->total, $second->total);
        self::assertSame(
            array_map(static fn ($item): string => (string) $item->id, $first->items()),
            array_map(static fn ($item): string => (string) $item->id, $second->items()),
        );
    }

    #[Test]
    public function indexingAndRemovingProductsIsIdempotent(): void
    {
        $engine = $this->searchEngine();
        $engine->indexProducts([]);
        $engine->removeProducts([]);

        $productId = $this->complianceProductId();

        if ($productId === null) {
            return;
        }

        $engine->indexProducts([$productId]);
        $engine->indexProducts([$productId]);
        $afterIndex = $engine->search(new CatalogSearchQuery(first: 200));

        $engine->removeProducts([$productId]);
        $engine->removeProducts([$productId]);
        $afterRemove = $engine->search(new CatalogSearchQuery(first: 200));

        self::assertGreaterThanOrEqual($afterRemove->total, $afterIndex->total);
    }

    #[Test]
    public function invalidSearchInputsThrowTypedErrorCodesAndRespectFailurePolicy(): void
    {
        $policy = $this->searchFailurePolicy();
        self::assertContains($policy, [FailurePolicy::BlockOnFailure, FailurePolicy::IgnoreOnFailure]);

        try {
            new CatalogSearchQuery(first: 0);
            self::fail('Expected InvalidCommand for first=0.');
        } catch (InvalidCommand $exception) {
            self::assertSame('common.invalid_page_size', $exception->code());
        }

        $invalid = $this->invalidSearchQuery();

        if ($invalid === null) {
            return;
        }

        try {
            $this->searchEngine()->search($invalid);
            self::fail('Expected CommerzException for invalidSearchQuery().');
        } catch (CommerzException $exception) {
            self::assertNotSame('', $exception->code());
            $exception->errorDescriptor();
        }
    }
}
