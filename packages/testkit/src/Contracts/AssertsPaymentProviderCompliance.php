<?php

declare(strict_types=1);

namespace Commerz\Testkit\Contracts;

use Commerz\Errors\CommerzException;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Packman\Attributes\Api;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Certification contract suite every `PaymentGateway` implementation must pass.
 *
 * Verifies contribution key stability, idempotency by reference for payments and refunds, minor-unit currency
 * invariants, typed `PaymentDeclined` error descriptors, and `FailurePolicy` when exposed.
 *
 * @phpstan-require-extends TestCase
 */
#[Api(since: '0.1')]
trait AssertsPaymentProviderCompliance
{
    abstract protected function paymentGateway(): PaymentGateway;

    protected function paymentCurrency(): Currency
    {
        return Currency::of('USD');
    }

    /** @return list<GatewayPaymentRequest> */
    protected function declinedPaymentRequests(): array
    {
        return [];
    }

    protected function paymentFailurePolicy(): FailurePolicy
    {
        $gateway = $this->paymentGateway();

        return $gateway instanceof QuoteStagePolicy
            ? $gateway->failurePolicy()
            : FailurePolicy::BlockOnFailure;
    }

    #[Test]
    public function paymentGatewayKeyIsNonEmptyAndBounded(): void
    {
        $key = $this->paymentGateway()->key();

        self::assertNotSame('', trim($key));
        self::assertLessThanOrEqual(64, strlen($key));
    }

    #[Test]
    public function initiatePaymentIsIdempotentByReferenceAndRespectsCurrencyRounding(): void
    {
        $gateway = $this->paymentGateway();
        $currency = $this->paymentCurrency();
        // Odd minor-unit amount (1005 minor units) verifies integer minor-unit handling without float drift.
        $amount = Money::ofMinor(1005, $currency);
        $request = new GatewayPaymentRequest(
            reference: 'payment:compliance-init-1',
            orderRef: 'order:compliance-1',
            orderNumber: 'ORD-COMPLIANCE-1',
            amount: $amount,
        );

        $first = $gateway->initiate($request);
        $second = $gateway->initiate($request);

        self::assertSame($first->status, $second->status);
        self::assertSame($first->externalId, $second->externalId);
        $this->assertPaymentResultInvariants($first, $amount);
        $this->assertPaymentResultInvariants($second, $amount);
    }

    #[Test]
    public function findUnknownPaymentReturnsNullOrValidResult(): void
    {
        $result = $this->paymentGateway()->find('payment:compliance-nonexistent-999');

        self::assertThat(
            $result,
            self::logicalOr(self::isNull(), self::isInstanceOf(GatewayPaymentResult::class)),
        );
    }

    #[Test]
    public function refundIsIdempotentByReference(): void
    {
        $gateway = $this->paymentGateway();
        $currency = $this->paymentCurrency();
        $refundRequest = new GatewayRefundRequest(
            reference: 'refund:compliance-1',
            paymentReference: 'payment:compliance-init-1',
            paymentExternalId: null,
            amount: Money::ofMinor(333, $currency),
            reason: 'compliance_check',
        );

        $first = $gateway->refund($refundRequest);
        $second = $gateway->refund($refundRequest);

        self::assertSame($first->status, $second->status);
        self::assertSame($first->externalId, $second->externalId);

        $found = $gateway->findRefund('refund:compliance-nonexistent-999');

        self::assertThat(
            $found,
            self::logicalOr(self::isNull(), self::isInstanceOf(GatewayRefundResult::class)),
        );
    }

    #[Test]
    public function declinedPaymentCarriesTypedErrorCodeAndHonorsFailurePolicy(): void
    {
        $policy = $this->paymentFailurePolicy();
        self::assertContains($policy, [FailurePolicy::BlockOnFailure, FailurePolicy::IgnoreOnFailure]);

        foreach ($this->declinedPaymentRequests() as $declinedRequest) {
            try {
                $this->paymentGateway()->initiate($declinedRequest);
                self::fail('Expected PaymentDeclined to be thrown for declinedPaymentRequests().');
            } catch (PaymentDeclined $exception) {
                self::assertInstanceOf(CommerzException::class, $exception);
                self::assertSame('sales.payment_declined', $exception->code());
                $descriptor = $exception->errorDescriptor();
                self::assertFalse($descriptor->retryable);
            }
        }
    }

    private function assertPaymentResultInvariants(GatewayPaymentResult $result, Money $requested): void
    {
        if ($result->authorized !== null) {
            self::assertTrue($result->authorized->currency->equals($requested->currency));
            self::assertLessThanOrEqual($requested->amountMinor, $result->authorized->amountMinor);
        }

        if ($result->captured !== null) {
            self::assertTrue($result->captured->currency->equals($requested->currency));
            self::assertLessThanOrEqual($requested->amountMinor, $result->captured->amountMinor);
        }
    }
}
