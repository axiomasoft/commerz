<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Data;

use Commerz\Sales\Gateways\Contracts\CapablePaymentGateway;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Packman\Attributes\Api;

/**
 * Declares optional flexible-acquiring capabilities supported by a {@see PaymentGateway}.
 *
 * Gateways that do not implement {@see CapablePaymentGateway} default to {@see self::basic()}, preserving full
 * backward compatibility with `initiate` / `find` / `refund` / `findRefund` adapters.
 */
#[Api(since: '0.1')]
final readonly class PaymentCapabilities
{
    public function __construct(
        public bool $authorizeCaptureVoid = false,
        public bool $multicapture = false,
        public bool $incrementalAuth = false,
        public bool $overcapture = false,
        public bool $lineItemsL3 = false,
    ) {}

    public static function basic(): self
    {
        return new self;
    }

    public static function of(PaymentGateway $gateway): self
    {
        return $gateway instanceof CapablePaymentGateway ? $gateway->capabilities() : self::basic();
    }
}
