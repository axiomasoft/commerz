<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Fixtures;

use Commerz\Money\Money;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use RuntimeException;

/**
 * Multi-process payment gateway fixture backed by a file-locked JSON ledger on disk.
 *
 * Models true network timeouts across forked processes:
 * - `timeout_after_capture`: records the capture on the provider side, then throws a network timeout so Commerz marks
 *   the attempt `unknown`; subsequent `find()` calls report the captured charge.
 * - `timeout_before_capture`: throws a network timeout without recording any charge; subsequent `find()` returns null.
 * - `capture`: records the capture and returns `GatewayPaymentStatus::Captured` immediately.
 */
final class StressPaymentGateway implements PaymentGateway
{
    public const string KEY = 'stress_gateway';

    public function key(): string
    {
        return self::KEY;
    }

    public static function reset(): void
    {
        $path = self::statePath();

        if (is_file($path)) {
            unlink($path);
        }
    }

    public static function configure(string $initiateBehavior, int $sleepMs = 0): void
    {
        self::mutate(static function (array $state) use ($initiateBehavior, $sleepMs): array {
            $state['behavior'] = $initiateBehavior;
            $state['sleep_ms'] = $sleepMs;

            return $state;
        });
    }

    /** @return list<string> */
    public static function initiateCalls(): array
    {
        return self::readState()['initiate_calls'];
    }

    /** @return list<string> */
    public static function findCalls(): array
    {
        return self::readState()['find_calls'];
    }

    /** @return array<string, array{amount_minor: int, currency: string}> */
    public static function charges(): array
    {
        return self::readState()['charges'];
    }

    public function initiate(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        $snapshot = self::mutate(static function (array $state) use ($request): array {
            $state['initiate_calls'][] = $request->reference;

            if ($state['behavior'] !== 'timeout_before_capture') {
                $state['charges'][$request->reference] = [
                    'amount_minor' => $request->amount->amountMinor,
                    'currency' => $request->amount->currency->code,
                ];
            }

            return $state;
        });

        if ($snapshot['sleep_ms'] > 0) {
            usleep($snapshot['sleep_ms'] * 1000);
        }

        return match ($snapshot['behavior']) {
            'timeout_after_capture' => throw new RuntimeException('Simulated gateway network timeout after provider capture.'),
            'timeout_before_capture' => throw new RuntimeException('Simulated gateway network timeout before reaching provider.'),
            default => new GatewayPaymentResult(
                status: GatewayPaymentStatus::Captured,
                externalId: 'ext-'.$request->reference,
                captured: $request->amount,
                meta: ['gateway' => self::KEY],
            ),
        };
    }

    public function find(string $reference): ?GatewayPaymentResult
    {
        $snapshot = self::mutate(static function (array $state) use ($reference): array {
            $state['find_calls'][] = $reference;

            return $state;
        });

        if ($snapshot['sleep_ms'] > 0) {
            usleep($snapshot['sleep_ms'] * 1000);
        }

        $charge = $snapshot['charges'][$reference] ?? null;

        if ($charge === null) {
            return null;
        }

        return new GatewayPaymentResult(
            status: GatewayPaymentStatus::Captured,
            externalId: 'ext-'.$reference,
            captured: Money::ofMinor($charge['amount_minor'], $charge['currency']),
            meta: ['gateway' => self::KEY],
        );
    }

    public function refund(GatewayRefundRequest $request): GatewayRefundResult
    {
        return new GatewayRefundResult(GatewayRefundStatus::Succeeded, 'ext-ref-'.$request->reference);
    }

    public function findRefund(string $reference): ?GatewayRefundResult
    {
        return new GatewayRefundResult(GatewayRefundStatus::Succeeded, 'ext-ref-'.$reference);
    }

    private static function statePath(): string
    {
        return storage_path('stress-gateway-state.json');
    }

    /**
     * @return array{behavior: string, sleep_ms: int, initiate_calls: list<string>, find_calls: list<string>, charges: array<string, array{amount_minor: int, currency: string}>}
     */
    private static function emptyState(): array
    {
        return [
            'behavior' => 'capture',
            'sleep_ms' => 0,
            'initiate_calls' => [],
            'find_calls' => [],
            'charges' => [],
        ];
    }

    /**
     * @return array{behavior: string, sleep_ms: int, initiate_calls: list<string>, find_calls: list<string>, charges: array<string, array{amount_minor: int, currency: string}>}
     */
    private static function readState(): array
    {
        return self::mutate(static fn (array $state): array => $state);
    }

    /**
     * @param  callable(array{behavior: string, sleep_ms: int, initiate_calls: list<string>, find_calls: list<string>, charges: array<string, array{amount_minor: int, currency: string}>}): array{behavior: string, sleep_ms: int, initiate_calls: list<string>, find_calls: list<string>, charges: array<string, array{amount_minor: int, currency: string}>}  $fn
     * @return array{behavior: string, sleep_ms: int, initiate_calls: list<string>, find_calls: list<string>, charges: array<string, array{amount_minor: int, currency: string}>}
     */
    private static function mutate(callable $fn): array
    {
        $path = self::statePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Cannot open stress gateway state file '{$path}'.");
        }

        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
            /** @var array{behavior: string, sleep_ms: int, initiate_calls: list<string>, find_calls: list<string>, charges: array<string, array{amount_minor: int, currency: string}>} $state */
            $state = is_array($decoded) ? $decoded : self::emptyState();

            $updated = $fn($state);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($updated, JSON_THROW_ON_ERROR));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $updated;
        } finally {
            fclose($handle);
        }
    }
}
