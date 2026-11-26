<?php

declare(strict_types=1);

namespace Commerz\Testkit\Contracts;

use Commerz\Context\CommerceContext;
use Commerz\Errors\CommerzException;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Taxes\Data\TaxExemption;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Certification contract suite every `TaxCalculator` implementation must pass.
 *
 * Verifies contribution key stability, deterministic calculation, currency and integer minor-unit rounding invariants,
 * tax-inclusive upper-bound invariants, exemption and B2B reverse-charge handling, typed `CommerzException` codes,
 * and `FailurePolicy` when exposed.
 *
 * @phpstan-require-extends TestCase
 */
#[Api(since: '0.1')]
trait AssertsTaxCalculatorCompliance
{
    abstract protected function taxCalculator(): TaxCalculator;

    protected function taxContext(): CommerceContext
    {
        return CommerceContext::for(
            channel: ChannelId::fromString('01950000-0000-7000-8000-000000000001'),
            currency: Currency::of('USD'),
            locale: 'en',
            at: new DateTimeImmutable('2026-01-01T00:00:00+00:00', new DateTimeZone('UTC')),
        );
    }

    protected function taxableRequest(?CommerceContext $context = null): TaxRequest
    {
        $context ??= $this->taxContext();

        return new TaxRequest(
            context: $context,
            destination: new Address('US', 'US-CA', 'San Francisco', '94107'),
            items: [
                new TaxableItem(
                    target: AdjustmentTarget::Line,
                    lineId: 'line-1',
                    sellable: SellableRef::of('variant', 'compliance-1'),
                    taxClass: 'standard',
                    quantity: 1,
                    // Odd minor-unit base (333 cents) exercises deterministic rounding without float drift.
                    base: Money::ofMinor(333, $context->currency),
                    included: false,
                ),
            ],
        );
    }

    /** @return list<TaxRequest> */
    protected function unresolvableTaxRequests(): array
    {
        return [];
    }

    protected function taxFailurePolicy(): FailurePolicy
    {
        $calculator = $this->taxCalculator();

        return $calculator instanceof QuoteStagePolicy
            ? $calculator->failurePolicy()
            : FailurePolicy::BlockOnFailure;
    }

    #[Test]
    public function taxCalculatorKeyIsNonEmptyAndBounded(): void
    {
        $key = $this->taxCalculator()->key();

        self::assertNotSame('', trim($key));
        self::assertLessThanOrEqual(64, strlen($key));
    }

    #[Test]
    public function calculateIsDeterministicAndPreservesCurrencyAndRoundingInvariants(): void
    {
        $calculator = $this->taxCalculator();
        $request = $this->taxableRequest();

        $first = $calculator->calculate($request);
        $second = $calculator->calculate($request);

        self::assertInstanceOf(TaxResult::class, $first);
        self::assertSame($first->exempt, $second->exempt);
        self::assertSame($first->reverseCharge, $second->reverseCharge);
        self::assertCount(count($first->amounts), $second->amounts);

        $itemsByKey = [];

        foreach ($request->items as $item) {
            $itemsByKey[$item->key()] = $item;
        }

        $sumByKey = [];

        foreach ($first->amounts as $index => $taxAmount) {
            $itemKey = $taxAmount->itemKey();
            self::assertArrayHasKey($itemKey, $itemsByKey, "TaxAmount refers to unknown item '{$itemKey}'.");
            self::assertTrue($taxAmount->amount->currency->equals($request->context->currency));
            self::assertFalse($taxAmount->amount->isNegative());
            self::assertSame($taxAmount->amount->amountMinor, $second->amounts[$index]->amount->amountMinor);

            $sumByKey[$itemKey] = ($sumByKey[$itemKey] ?? 0) + $taxAmount->amount->amountMinor;
        }

        foreach ($sumByKey as $itemKey => $taxMinor) {
            if ($itemsByKey[$itemKey]->included) {
                self::assertLessThanOrEqual($itemsByKey[$itemKey]->base->amountMinor, $taxMinor);
            }
        }
    }

    #[Test]
    public function calculateHonorsTaxExemptionAndReverseCharge(): void
    {
        $calculator = $this->taxCalculator();
        $context = $this->taxContext();
        $baseItems = $this->taxableRequest($context)->items;

        $exemptResult = $calculator->calculate(new TaxRequest(
            context: $context,
            destination: new Address('US', 'US-CA', 'San Francisco', '94107'),
            items: $baseItems,
            exemption: TaxExemption::exempt('nonprofit_certificate'),
        ));

        self::assertTrue($exemptResult->exempt);

        foreach ($exemptResult->amounts as $amount) {
            self::assertTrue($amount->amount->isZero());
        }

        $reverseChargeResult = $calculator->calculate(new TaxRequest(
            context: $context,
            destination: new Address('DE', null, 'Berlin', '10115'),
            items: $baseItems,
            exemption: TaxExemption::reverseCharge('DE123456789', 'FR', 'eu_b2b'),
            sellerCountry: 'FR',
        ));

        self::assertTrue($reverseChargeResult->reverseCharge);

        foreach ($reverseChargeResult->amounts as $amount) {
            self::assertTrue($amount->amount->isZero());
        }
    }

    #[Test]
    public function unresolvableTaxThrowsTypedErrorCodeAndHonorsFailurePolicy(): void
    {
        $policy = $this->taxFailurePolicy();
        self::assertContains($policy, [FailurePolicy::BlockOnFailure, FailurePolicy::IgnoreOnFailure]);

        foreach ($this->unresolvableTaxRequests() as $unresolvable) {
            try {
                $this->taxCalculator()->calculate($unresolvable);
                self::fail('Expected CommerzException for unresolvableTaxRequests().');
            } catch (CommerzException $exception) {
                self::assertNotSame('', $exception->code());
                $exception->errorDescriptor();
            }
        }
    }
}
