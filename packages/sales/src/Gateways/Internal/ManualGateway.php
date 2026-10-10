<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Internal;

use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;

/**
 * Offline payment (invoice, cash, bank transfer): no network and no state at the provider. A payment stays pending
 * until the operator records the money as a `captured` event; a refund is booked by the operator, so it succeeds at once.
 */
final readonly class ManualGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'manual';
    }

    public function initiate(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        return new GatewayPaymentResult(GatewayPaymentStatus::Pending, meta: ['instructions' => 'offline']);
    }

    public function find(string $reference): GatewayPaymentResult
    {
        return new GatewayPaymentResult(GatewayPaymentStatus::Pending, meta: ['instructions' => 'offline']);
    }

    public function refund(GatewayRefundRequest $request): GatewayRefundResult
    {
        return new GatewayRefundResult(GatewayRefundStatus::Succeeded);
    }

    public function findRefund(string $reference): GatewayRefundResult
    {
        return new GatewayRefundResult(GatewayRefundStatus::Succeeded);
    }
}
