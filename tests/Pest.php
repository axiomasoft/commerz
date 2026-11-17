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
uses()->group('pg')->in(
    '../packages/core/tests/Feature/CommittedEventsTest.php',
    '../packages/core/tests/Feature/ConcurrencyTest.php',
    '../packages/core/tests/Feature/OperationReceiptsTest.php',
    '../packages/core/tests/Feature/OutboxTest.php',
    '../packages/core/tests/Feature/SchemaTest.php',
    '../packages/core/tests/Feature/TransactionsTest.php',
    '../packages/core/tests/Feature/Quotes/CrmScenarioTest.php',
    '../packages/core/tests/Feature/Stores/ConsoleTest.php',
    '../packages/core/tests/Feature/Stores/StoresTest.php',
);
uses(CatalogTestCase::class)->in('../packages/catalog/tests/Feature');
uses(InventoryTestCase::class)->in('../packages/inventory/tests/Feature');
uses(PricingTestCase::class)->in('../packages/pricing/tests/Feature');
uses(PromotionsTestCase::class)->in('../packages/promotions/tests/Feature');
uses(ShippingTestCase::class)->in('../packages/shipping/tests/Feature');
uses(SalesTestCase::class)->in('../packages/sales/tests/Feature');
uses(EndToEndTestCase::class)->in('EndToEnd');
