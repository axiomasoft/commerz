<?php

declare(strict_types=1);

namespace Commerz\Testkit\Contracts;

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Contracts\PickupPointProvider;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Certification contract suite every `PickupPointProvider` implementation must pass.
 *
 * Verifies contribution key stability, deterministic search ordering, limit and country/mode invariants, oversized
 * parcel rejection, unknown-point nullability, and typed `InvalidCommand` codes.
 *
 * @phpstan-require-extends TestCase
 */
#[Api(since: '0.1')]
trait AssertsPickupPointCompliance
{
    abstract protected function pickupPointProvider(): PickupPointProvider;

    protected function pickupPointContext(): CommerceContext
    {
        return CommerceContext::for(
            channel: ChannelId::fromString('01950000-0000-7000-8000-000000000001'),
            currency: Currency::of('USD'),
            locale: 'en',
            at: new DateTimeImmutable('2026-01-01T00:00:00+00:00', new DateTimeZone('UTC')),
        );
    }

    protected function pickupPointQuery(): PickupPointQuery
    {
        return new PickupPointQuery(
            context: $this->pickupPointContext(),
            country: 'US',
            limit: 10,
        );
    }

    /** @return list<string> */
    protected function knownPickupPointIds(): array
    {
        return [];
    }

    protected function pickupPointFailurePolicy(): FailurePolicy
    {
        $provider = $this->pickupPointProvider();

        return $provider instanceof QuoteStagePolicy
            ? $provider->failurePolicy()
            : FailurePolicy::BlockOnFailure;
    }

    #[Test]
    public function pickupPointProviderKeyIsNonEmptyAndBounded(): void
    {
        $key = $this->pickupPointProvider()->key();

        self::assertNotSame('', trim($key));
        self::assertLessThanOrEqual(64, strlen($key));
    }

    #[Test]
    public function searchIsIdempotentAndRespectsCountryModeAndLimit(): void
    {
        $provider = $this->pickupPointProvider();
        $query = $this->pickupPointQuery();

        $first = $provider->search($query);
        $second = $provider->search($query);

        self::assertLessThanOrEqual($query->limit, count($first));
        self::assertSame(
            array_map(static fn (PickupPoint $p): string => $p->id, $first),
            array_map(static fn (PickupPoint $p): string => $p->id, $second),
        );

        foreach ($first as $point) {
            self::assertSame($query->country, $point->address->country);
            self::assertNotSame(DeliveryMode::Shipping, $point->mode);

            if ($query->parcel !== null) {
                self::assertTrue($point->accepts($query->parcel, $query->items));
            }
        }
    }

    #[Test]
    public function findUnknownPickupPointReturnsNull(): void
    {
        $point = $this->pickupPointProvider()->find('compliance-nonexistent-point-999', $this->pickupPointContext());

        self::assertNull($point);
    }

    #[Test]
    public function findKnownPickupPointReturnsMatchingPoint(): void
    {
        $knownIds = $this->knownPickupPointIds();
        self::assertNotSame('', $this->pickupPointProvider()->key());

        foreach ($knownIds as $knownId) {
            $point = $this->pickupPointProvider()->find($knownId, $this->pickupPointContext());

            self::assertInstanceOf(PickupPoint::class, $point);
            self::assertSame($knownId, $point->id);
        }
    }

    #[Test]
    public function invalidPickupQueryCarriesTypedErrorCodeAndHonorsFailurePolicy(): void
    {
        $policy = $this->pickupPointFailurePolicy();
        self::assertContains($policy, [FailurePolicy::BlockOnFailure, FailurePolicy::IgnoreOnFailure]);

        try {
            new PickupPointQuery(
                context: $this->pickupPointContext(),
                country: 'INVALID',
                parcel: new Parcel(100, Money::ofMinor(100, Currency::of('USD'))),
            );
            self::fail('Expected InvalidCommand for invalid country.');
        } catch (InvalidCommand $exception) {
            self::assertSame('delivery.invalid_pickup_query', $exception->code());
        }
    }
}
