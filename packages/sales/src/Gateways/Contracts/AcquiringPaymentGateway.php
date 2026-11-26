<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Contracts;

use Commerz\Sales\Gateways\Data\GatewayAuthorizeRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureResult;
use Commerz\Sales\Gateways\Data\GatewayIncrementalAuthRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayVoidRequest;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Additive flexible-acquiring extension of {@see PaymentGateway} supporting separate authorize / capture / void,
 * multicapture (`isFinalCapture`), incremental authorization, overcapture, and Level 3 line items according to
 * {@see CapablePaymentGateway::capabilities()}.
 *
 * Called only by sales and only outside a database transaction (PAY-02, D33).
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface AcquiringPaymentGateway extends CapablePaymentGateway
{
    /**
     * Authorizes funds without capturing them; idempotent by `$request->reference`.
     *
     * @throws PaymentDeclined
     */
    public function authorize(GatewayAuthorizeRequest $request): GatewayPaymentResult;

    /**
     * Captures all or part of an authorization; idempotent by `$request->reference`.
     *
     * @throws PaymentDeclined
     */
    public function capture(GatewayCaptureRequest $request): GatewayCaptureResult;

    /**
     * Voids an uncaptured authorization; idempotent by `$request->reference`.
     *
     * @throws PaymentDeclined
     */
    public function void(GatewayVoidRequest $request): GatewayPaymentResult;

    /**
     * Increases the authorized amount of an open authorization; idempotent by `$request->reference`.
     *
     * @throws PaymentDeclined
     */
    public function incrementalAuthorize(GatewayIncrementalAuthRequest $request): GatewayPaymentResult;
}
