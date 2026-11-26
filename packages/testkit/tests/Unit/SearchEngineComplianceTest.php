<?php

declare(strict_types=1);

namespace Commerz\Testkit\Tests\Unit;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\Search\Contracts\CatalogSearchPort;
use Commerz\Measures\LocalizedText;
use Commerz\Testkit\Contracts\AssertsSearchEngineCompliance;
use Commerz\Testkit\Fakes\FakeSearchEngine;
use Commerz\Testkit\Fakes\FrozenClock;
use PHPUnit\Framework\TestCase;

final class SearchEngineComplianceTest extends TestCase
{
    use AssertsSearchEngineCompliance;

    private ?FakeSearchEngine $engine = null;

    private ?ProductId $productId = null;

    protected function searchEngine(): CatalogSearchPort
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        $clock = new FrozenClock('2026-01-01T00:00:00+00:00');
        $now = $clock->now();
        $this->productId = ProductId::generate();

        return $this->engine = (new FakeSearchEngine)->add(
            new ProductView(
                id: $this->productId,
                type: ProductTypeId::generate(),
                handle: 'compliance-widget',
                name: LocalizedText::of('en', 'Compliance Widget'),
                description: null,
                status: ProductStatus::Active,
                attributes: [],
                axes: [],
                createdAt: $now,
                updatedAt: $now,
            ),
        );
    }

    protected function complianceProductId(): ?ProductId
    {
        $this->searchEngine();

        return $this->productId;
    }
}
