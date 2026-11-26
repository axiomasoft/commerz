<?php

declare(strict_types=1);

namespace Commerz\Testkit\Tests\Unit;

use Commerz\Money\Money;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Testkit\Contracts\AssertsPaymentProviderCompliance;
use PHPUnit\Framework\TestCase;

final class PaymentProviderComplianceTest extends TestCase
{
    use AssertsPaymentProviderCompliance;

    private ?PaymentGateway $gateway = null;

    protected function paymentGateway(): PaymentGateway
    {
        return $this->gateway ??= new class implements PaymentGateway
        {
            /** @var array<string, GatewayPaymentResult> */
            private array $payments = [];

            /** @var array<string, GatewayRefundResult> */
            private array $refunds = [];

            public function key(): string
            {
                return 'test.gateway';
            }

            public function initiate(GatewayPaymentRequest $request): GatewayPaymentResult
            {
                if (($request->meta['force_decline'] ?? false) === true) {
                    throw new PaymentDeclined($this->key(), 'insufficient_funds');
                }

                return $this->payments[$request->reference] ??= new GatewayPaymentResult(
                    status: GatewayPaymentStatus::Authorized,
                    externalId: 'ext-'.$request->reference,
                    authorized: $request->amount,
                );
            }

            public function find(string $reference): ?GatewayPaymentResult
            {
                return $this->payments[$reference] ?? null;
            }

            public function refund(GatewayRefundRequest $request): GatewayRefundResult
            {
                return $this->refunds[$request->reference] ??= new GatewayRefundResult(
                    status: GatewayRefundStatus::Succeeded,
                    externalId: 'ref-'.$request->reference,
                );
            }

            public function findRefund(string $reference): ?GatewayRefundResult
            {
                return $this->refunds[$reference] ?? null;
            }
        };
    }

    /** @return list<GatewayPaymentRequest> */
    protected function declinedPaymentRequests(): array
    {
        return [
            new GatewayPaymentRequest(
                reference: 'payment:compliance-declined-1',
                orderRef: 'order:compliance-declined-1',
                orderNumber: 'ORD-DECLINED-1',
                amount: Money::ofMinor(500, $this->paymentCurrency()),
                meta: ['force_decline' => true],
            ),
        ];
    }
}
