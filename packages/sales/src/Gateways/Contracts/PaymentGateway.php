<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Contracts;

use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** A payment gateway, a contribution of kind `payment_gateway`. Called only by sales and only outside a database transaction. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface PaymentGateway
{
    public const string KIND = 'payment_gateway';

    /** Contribution key, e.g. `manual` or `acme.pay`. */
    public function key(): string;

    /**
     * Idempotent by `$request->reference` at the provider: the adapter passes it as the idempotency key or metadata.
     *
     * @throws PaymentDeclined a final refusal
     *
     * Any other exception means the outcome is unknown (a timeout, a broken connection): the attempt turns `unknown`.
     */
    public function initiate(GatewayPaymentRequest $request): GatewayPaymentResult;

    /**
     * Reconciles an unknown outcome with the provider's current state.
     *
     * @return GatewayPaymentResult|null null only when the provider confirms it knows no such attempt
     */
    public function find(string $reference): ?GatewayPaymentResult;

    /**
     * Idempotent by `$request->reference`.
     *
     * @throws PaymentDeclined a final refusal of the refund
     */
    public function refund(GatewayRefundRequest $request): GatewayRefundResult;

    public function findRefund(string $reference): ?GatewayRefundResult;
}
