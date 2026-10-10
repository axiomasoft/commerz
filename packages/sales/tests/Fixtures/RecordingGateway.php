<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use RuntimeException;

/**
 * A gateway that appends every call, `<initiate|find|refund|findRefund> <reference>`, to a log shared by forked
 * processes. The request's meta, over the static scenario, steers `initiate`: `sleep_ms`, `fail` (`timeout`,
 * `decline` or `invalid`: an answer in a foreign currency) and `status` (`pending` by default, `requires_action`,
 * `authorized`, `captured`, `failed`, `cancelled`). `find` follows the scenario's `found`: null by default (the
 * provider knows no such payment), `fail` (it throws) or a status.
 */
final class RecordingGateway implements PaymentGateway
{
    public const string KEY = 'recording';

    /** @var array<string, scalar|null> set before the processes fork, so each of them sees it */
    private static array $scenario = [];

    public function key(): string
    {
        return self::KEY;
    }

    /** @param  array<string, scalar|null>  $scenario */
    public static function scenario(array $scenario): void
    {
        self::$scenario = $scenario;
    }

    public function initiate(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        $meta = $request->meta + self::$scenario;
        self::record('initiate', $request->reference, $meta['sleep_ms'] ?? 0);

        return match ($meta['fail'] ?? null) {
            'timeout' => throw new RuntimeException('The gateway timed out.'),
            'decline' => throw new PaymentDeclined(self::KEY, 'card_declined'),
            'invalid' => new GatewayPaymentResult(GatewayPaymentStatus::Captured, 'ext-'.$request->reference, captured: Money::ofMinor($request->amount->amountMinor, 'EUR')),
            default => self::result($request->reference, is_string($meta['status'] ?? null) ? $meta['status'] : 'pending', $request->amount),
        };
    }

    public function find(string $reference): ?GatewayPaymentResult
    {
        self::record('find', $reference, self::$scenario['sleep_ms'] ?? 0);
        $found = self::$scenario['found'] ?? null;

        return match ($found) {
            null => null,
            'fail' => throw new RuntimeException('The gateway cannot be reached.'),
            default => self::result($reference, (string) $found, null),
        };
    }

    public function refund(GatewayRefundRequest $request): GatewayRefundResult
    {
        self::record('refund', $request->reference, 0);

        return new GatewayRefundResult(GatewayRefundStatus::Succeeded, 'ext-'.$request->reference);
    }

    public function findRefund(string $reference): ?GatewayRefundResult
    {
        self::record('findRefund', $reference, 0);

        return null;
    }

    public static function log(): string
    {
        return storage_path('gateway-calls.log');
    }

    public static function reset(): void
    {
        self::$scenario = [];

        if (is_file(self::log())) {
            unlink(self::log());
        }
    }

    /** @return list<string> the calls of every process, in order */
    public static function calls(?string $kind = null): array
    {
        $lines = is_file(self::log()) ? file(self::log(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $lines = $lines === false ? [] : $lines;

        return array_values($kind === null ? $lines : array_filter($lines, static fn (string $line): bool => str_starts_with($line, "{$kind} ")));
    }

    /** @param  Money|null  $amount  the amount of the request; a found payment reports none */
    private static function result(string $reference, string $status, ?Money $amount): GatewayPaymentResult
    {
        $status = GatewayPaymentStatus::from($status);

        return new GatewayPaymentResult(
            $status,
            'ext-'.$reference,
            authorized: $status === GatewayPaymentStatus::Authorized ? $amount : null,
            captured: $status === GatewayPaymentStatus::Captured ? $amount : null,
            actionUrl: $status === GatewayPaymentStatus::RequiresAction ? 'https://pay.example/3ds/'.$reference : null,
            meta: ['gateway' => self::KEY],
        );
    }

    private static function record(string $kind, string $reference, mixed $sleepMs): void
    {
        $handle = fopen(self::log(), 'a') ?: throw new RuntimeException('Cannot open the gateway log.');

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, "{$kind} {$reference}\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (is_int($sleepMs) && $sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}
