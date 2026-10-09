<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Data\CarrierBooking;
use Commerz\Shipping\Carriers\Exceptions\CarrierRejected;
use RuntimeException;

/**
 * A carrier that appends every call, `<book|find> <reference> <attempt>`, to a log shared by forked processes. The
 * shipment's meta steers it: `sleep_ms` (each call), `fail` (`timeout`, `reject` or `invalid`) for the book calls of
 * attempts up to `fail_attempts` (all by default), `found` (find returns the shipment) and `find_fail` (find throws).
 */
final readonly class RecordingCarrier implements Carrier
{
    public const string KEY = 'recording';

    public function key(): string
    {
        return self::KEY;
    }

    public function book(CarrierBooking $booking): BookedShipment
    {
        self::record('book', $booking);
        $meta = $booking->shipment->meta;
        $fail = $meta['fail'] ?? null;

        if (is_string($fail) && $booking->attempt <= (int) ($meta['fail_attempts'] ?? PHP_INT_MAX)) {
            return match ($fail) {
                'timeout' => throw new RuntimeException('The carrier timed out.'),
                'reject' => throw new CarrierRejected(self::KEY, 'address_unreachable', 'The carrier does not serve the address.'),
                'invalid' => new BookedShipment(self::KEY, ''),
                default => throw new RuntimeException("Unknown failure '{$fail}'."),
            };
        }

        return self::shipment($booking);
    }

    public function find(CarrierBooking $booking): ?BookedShipment
    {
        self::record('find', $booking);
        $meta = $booking->shipment->meta;

        if (($meta['find_fail'] ?? false) === true) {
            throw new RuntimeException('The carrier cannot be reached.');
        }

        return ($meta['found'] ?? false) === true ? self::shipment($booking) : null;
    }

    public static function log(): string
    {
        return storage_path('carrier-calls.log');
    }

    public static function reset(): void
    {
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

    private static function shipment(CarrierBooking $booking): BookedShipment
    {
        $reference = $booking->shipment->reference;

        return new BookedShipment(self::KEY, "TRK-{$reference}", "label:{$reference}", ['attempt' => $booking->attempt]);
    }

    private static function record(string $kind, CarrierBooking $booking): void
    {
        $handle = fopen(self::log(), 'a') ?: throw new RuntimeException('Cannot open the carrier log.');

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, "{$kind} {$booking->shipment->reference} {$booking->attempt}\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $sleep = $booking->shipment->meta['sleep_ms'] ?? 0;

        if (is_int($sleep) && $sleep > 0) {
            usleep($sleep * 1000);
        }
    }
}
