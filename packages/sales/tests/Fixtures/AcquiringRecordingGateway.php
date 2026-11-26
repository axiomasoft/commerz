<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Sales\Gateways\Contracts\AcquiringPaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayAuthorizeRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureResult;
use Commerz\Sales\Gateways\Data\GatewayIncrementalAuthRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Data\GatewayVoidRequest;
use Commerz\Sales\Gateways\Data\PaymentCapabilities;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Override;
use RuntimeException;

/**
 * In-memory acquiring gateway fixture for unit and feature tests covering authorize/capture/void, multicapture
 * (`isFinalCapture`), incremental authorization, overcapture, and Level 3 line items.
 */
final class AcquiringRecordingGateway implements AcquiringPaymentGateway
{
    public const string KEY = 'acquiring.test';

    private static ?PaymentCapabilities $capabilities = null;

    /** @var array<string, scalar|null> */
    private static array $scenario = [];

    /** @var list<array{op: string, reference: string, amount?: int, is_final?: bool, line_items?: int}> */
    private static array $calls = [];

    public static function configure(
        ?PaymentCapabilities $capabilities = null,
        array $scenario = [],
    ): void {
        self::$capabilities = $capabilities ?? new PaymentCapabilities(
            authorizeCaptureVoid: true,
            multicapture: true,
            incrementalAuth: true,
            overcapture: true,
            lineItemsL3: true,
        );
        self::$scenario = $scenario;
        self::$calls = [];
    }

    public static function reset(): void
    {
        self::configure();
    }

    /** @return list<array{op: string, reference: string, amount?: int, is_final?: bool, line_items?: int}> */
    public static function calls(): array
    {
        return self::$calls;
    }

    #[Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[Override]
    public function capabilities(): PaymentCapabilities
    {
        return self::$capabilities ?? new PaymentCapabilities(
            authorizeCaptureVoid: true,
            multicapture: true,
            incrementalAuth: true,
            overcapture: true,
            lineItemsL3: true,
        );
    }

    #[Override]
    public function initiate(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        self::$calls[] = ['op' => 'initiate', 'reference' => $request->reference, 'amount' => $request->amount->amountMinor];

        return match (self::$scenario['initiate_fail'] ?? null) {
            'decline' => throw new PaymentDeclined(self::KEY, 'initiate_declined'),
            'timeout' => throw new RuntimeException('Acquiring gateway timed out.'),
            default => new GatewayPaymentResult(
                status: GatewayPaymentStatus::Captured,
                externalId: 'ext-'.$request->reference,
                authorized: $request->amount,
                captured: $request->amount,
                meta: ['gateway' => self::KEY],
            ),
        };
    }

    #[Override]
    public function find(string $reference): ?GatewayPaymentResult
    {
        return null;
    }

    #[Override]
    public function refund(GatewayRefundRequest $request): GatewayRefundResult
    {
        return new GatewayRefundResult(GatewayRefundStatus::Succeeded, 'ref-'.$request->reference);
    }

    #[Override]
    public function findRefund(string $reference): ?GatewayRefundResult
    {
        return null;
    }

    #[Override]
    public function authorize(GatewayAuthorizeRequest $request): GatewayPaymentResult
    {
        self::$calls[] = [
            'op' => 'authorize',
            'reference' => $request->reference,
            'amount' => $request->amount->amountMinor,
            'line_items' => count($request->lineItems),
        ];

        return match (self::$scenario['authorize_fail'] ?? null) {
            'decline' => throw new PaymentDeclined(self::KEY, 'card_declined'),
            'timeout' => throw new RuntimeException('Acquiring gateway timed out.'),
            default => new GatewayPaymentResult(
                status: GatewayPaymentStatus::Authorized,
                externalId: 'auth-'.$request->reference,
                authorized: $request->amount,
                meta: ['gateway' => self::KEY],
            ),
        };
    }

    #[Override]
    public function capture(GatewayCaptureRequest $request): GatewayCaptureResult
    {
        self::$calls[] = [
            'op' => 'capture',
            'reference' => $request->reference,
            'amount' => $request->amount->amountMinor,
            'is_final' => $request->isFinalCapture,
            'line_items' => count($request->lineItems),
        ];

        return match (self::$scenario['capture_fail'] ?? null) {
            'decline' => throw new PaymentDeclined(self::KEY, 'capture_declined'),
            'timeout' => throw new RuntimeException('Capture timed out.'),
            default => new GatewayCaptureResult(
                status: GatewayPaymentStatus::Captured,
                captured: $request->amount,
                isFinalCapture: $request->isFinalCapture,
                externalId: 'cap-'.$request->reference,
                meta: ['gateway' => self::KEY],
            ),
        };
    }

    #[Override]
    public function void(GatewayVoidRequest $request): GatewayPaymentResult
    {
        self::$calls[] = ['op' => 'void', 'reference' => $request->reference];

        return match (self::$scenario['void_fail'] ?? null) {
            'decline' => throw new PaymentDeclined(self::KEY, 'void_declined'),
            'timeout' => throw new RuntimeException('Void timed out.'),
            default => new GatewayPaymentResult(
                status: GatewayPaymentStatus::Cancelled,
                externalId: $request->paymentExternalId,
                meta: ['voided' => true],
            ),
        };
    }

    #[Override]
    public function incrementalAuthorize(GatewayIncrementalAuthRequest $request): GatewayPaymentResult
    {
        self::$calls[] = [
            'op' => 'incremental_authorize',
            'reference' => $request->reference,
            'amount' => $request->additionalAmount->amountMinor,
        ];

        return match (self::$scenario['incremental_fail'] ?? null) {
            'decline' => throw new PaymentDeclined(self::KEY, 'incremental_declined'),
            'timeout' => throw new RuntimeException('Incremental auth timed out.'),
            default => new GatewayPaymentResult(
                status: GatewayPaymentStatus::Authorized,
                externalId: $request->paymentExternalId,
                authorized: $request->newAuthorizedTotal,
                meta: ['incremented' => true],
            ),
        };
    }
}
