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
    '../packages/catalog/tests/Feature/Attributes/AttributesTest.php',
    '../packages/catalog/tests/Feature/CatalogScenarioTest.php',
    '../packages/catalog/tests/Feature/Categories/CategoriesTest.php',
    '../packages/catalog/tests/Feature/Categories/MoveConcurrencyTest.php',
    '../packages/catalog/tests/Feature/Listings/ListingsTest.php',
    '../packages/catalog/tests/Feature/Options/OptionsTest.php',
    '../packages/catalog/tests/Feature/ProductTypes/ProductTypesTest.php',
    '../packages/catalog/tests/Feature/Products/AttributeWriterTest.php',
    '../packages/catalog/tests/Feature/Products/ProductSearchTest.php',
    '../packages/catalog/tests/Feature/Products/ProductsTest.php',
    '../packages/catalog/tests/Feature/Products/ProjectionConcurrencyTest.php',
    '../packages/catalog/tests/Feature/SchemaTest.php',
    '../packages/catalog/tests/Feature/Sellables/VariantSellablesTest.php',
    '../packages/catalog/tests/Feature/Variants/GenerateConcurrencyTest.php',
    '../packages/catalog/tests/Feature/Variants/VariantsTest.php',
    '../packages/inventory/tests/Feature/Availability/AvailabilityTest.php',
    '../packages/inventory/tests/Feature/Console/ReconcileTest.php',
    '../packages/inventory/tests/Feature/InventoryScenarioTest.php',
    '../packages/inventory/tests/Feature/Items/StockItemsTest.php',
    '../packages/inventory/tests/Feature/Locations/LocationsTest.php',
    '../packages/inventory/tests/Feature/Reservations/ReservationConcurrencyTest.php',
    '../packages/inventory/tests/Feature/Reservations/ReservationsTest.php',
    '../packages/inventory/tests/Feature/SchemaTest.php',
    '../packages/inventory/tests/Feature/Sources/StockSourcesTest.php',
    '../packages/inventory/tests/Feature/Stock/LedgerConcurrencyTest.php',
    '../packages/inventory/tests/Feature/Stock/StockLevelsTest.php',
    '../packages/inventory/tests/Feature/Stock/StockReturnsTest.php',
    '../packages/inventory/tests/Feature/Transfers/TransferConcurrencyTest.php',
    '../packages/inventory/tests/Feature/Transfers/TransfersTest.php',
    '../packages/pricing/tests/Feature/Calculation/RatesTaxCalculatorTest.php',
    '../packages/pricing/tests/Feature/Calculation/TaxStageTest.php',
    '../packages/pricing/tests/Feature/PriceLists/PriceListsTest.php',
    '../packages/pricing/tests/Feature/Prices/PriceConcurrencyTest.php',
    '../packages/pricing/tests/Feature/Prices/PriceResolutionTest.php',
    '../packages/pricing/tests/Feature/Prices/PricesTest.php',
    '../packages/pricing/tests/Feature/PricingScenarioTest.php',
    '../packages/pricing/tests/Feature/SchemaTest.php',
    '../packages/pricing/tests/Feature/Taxes/TaxConcurrencyTest.php',
    '../packages/pricing/tests/Feature/Taxes/TaxesTest.php',
    'Integration/SalesShippingQuoteTest.php',
);
uses(CatalogTestCase::class)->in('../packages/catalog/tests/Feature');
uses(InventoryTestCase::class)->in('../packages/inventory/tests/Feature');
uses(PricingTestCase::class)->in('../packages/pricing/tests/Feature');
uses(PromotionsTestCase::class)->in('../packages/promotions/tests/Feature');
uses(ShippingTestCase::class)->in('../packages/shipping/tests/Feature');
uses(SalesTestCase::class)->in('../packages/sales/tests/Feature');
uses(EndToEndTestCase::class)->in('EndToEnd');
