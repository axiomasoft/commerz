<?php

declare(strict_types=1);

use Commerz\Catalog\Tests\TestCase as CatalogTestCase;
use Commerz\EndToEnd\Support\EndToEndTestCase;
use Commerz\Inventory\Tests\TestCase as InventoryTestCase;
use Commerz\Pricing\Tests\TestCase as PricingTestCase;
use Commerz\Promotions\Tests\TestCase as PromotionsTestCase;
use Commerz\Sales\Tests\TestCase as SalesTestCase;
use Commerz\Shipping\Tests\TestCase as ShippingTestCase;
use Commerz\Tests\TestCase as CoreTestCase;

uses(CoreTestCase::class)->in('../packages/core/tests/Feature');
uses(CatalogTestCase::class)->in('../packages/catalog/tests/Feature');
uses(InventoryTestCase::class)->in('../packages/inventory/tests/Feature');
uses(PricingTestCase::class)->in('../packages/pricing/tests/Feature');
uses(PromotionsTestCase::class)->in('../packages/promotions/tests/Feature');
uses(ShippingTestCase::class)->in('../packages/shipping/tests/Feature');
uses(SalesTestCase::class)->in('../packages/sales/tests/Feature');
uses(EndToEndTestCase::class)->in('EndToEnd');
