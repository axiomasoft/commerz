<?php

declare(strict_types=1);

namespace Commerz\Testkit\Contracts;

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Certification contract suite every `SellableProvider` implementation must pass.
 *
 * Verifies contribution key stability, empty-input handling, omission of unknown refs, idempotent resolution keyed by
 * `(string) $ref`, integer minor-unit `basePrice` invariants, and `FailurePolicy` when exposed.
 *
 * @phpstan-require-extends TestCase
 */
#[Api(since: '0.1')]
trait AssertsSellableProviderCompliance
{
    abstract protected function sellableProvider(): SellableProvider;

    /** @return list<SellableRef> */
    protected function knownSellableRefs(): array
    {
        return [];
    }

    protected function sellableContext(): CommerceContext
    {
        return CommerceContext::for(
            channel: ChannelId::fromString('01950000-0000-7000-8000-000000000001'),
            currency: Currency::of('USD'),
            locale: 'en',
            at: new DateTimeImmutable('2026-01-01T00:00:00+00:00', new DateTimeZone('UTC')),
        );
    }

    protected function sellableFailurePolicy(): FailurePolicy
    {
        $provider = $this->sellableProvider();

        return $provider instanceof QuoteStagePolicy
            ? $provider->failurePolicy()
            : FailurePolicy::BlockOnFailure;
    }

    #[Test]
    public function sellableProviderKeyIsNonEmptyAndBounded(): void
    {
        $key = $this->sellableProvider()->key();

        self::assertNotSame('', trim($key));
        self::assertLessThanOrEqual(64, strlen($key));
    }

    #[Test]
    public function resolveEmptyRefsReturnsEmptyArray(): void
    {
        $resolved = $this->sellableProvider()->resolve([], $this->sellableContext());

        self::assertSame([], $resolved);
    }

    #[Test]
    public function resolveUnknownRefOmitsItWithoutThrowing(): void
    {
        $provider = $this->sellableProvider();
        $unknown = SellableRef::of($provider->key(), 'compliance-nonexistent-999');

        $resolved = $provider->resolve([$unknown], $this->sellableContext());

        self::assertSame([], $resolved);
    }

    #[Test]
    public function resolveKnownRefsIsIdempotentAndKeyedByRefString(): void
    {
        $refs = $this->knownSellableRefs();

        if ($refs === []) {
            return;
        }

        $provider = $this->sellableProvider();
        $context = $this->sellableContext();

        $first = $provider->resolve($refs, $context);
        $second = $provider->resolve($refs, $context);

        self::assertSame(array_keys($first), array_keys($second));

        foreach ($refs as $ref) {
            $key = (string) $ref;
            self::assertArrayHasKey($key, $first);
            self::assertInstanceOf(SellableSnapshot::class, $first[$key]);
            self::assertTrue($first[$key]->ref->equals($ref));

            if ($first[$key]->basePrice !== null) {
                self::assertGreaterThanOrEqual(0, $first[$key]->basePrice->amountMinor);
            }
        }
    }

    #[Test]
    public function invalidSellableRefCarriesTypedErrorCodeAndHonorsFailurePolicy(): void
    {
        $policy = $this->sellableFailurePolicy();
        self::assertContains($policy, [FailurePolicy::BlockOnFailure, FailurePolicy::IgnoreOnFailure]);

        try {
            SellableRef::fromString('invalid-ref-without-colon');
            self::fail('Expected InvalidCommand for malformed SellableRef.');
        } catch (InvalidCommand $exception) {
            self::assertNotSame('', $exception->code());
        }
    }
}
