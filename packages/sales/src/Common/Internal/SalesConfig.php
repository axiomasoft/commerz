<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Errors\InvalidConfiguration;

/** The only reader of `config('commerz-sales.*')`. */
final readonly class SalesConfig
{
    public function __construct(
        public int $maxCartLines,
        public int $maxLineQuantity,
        public bool $requireDelivery,
        public string $numberGenerator,
        public ?int $paymentDueMinutes,
        public int $expireBatch,
        public int $leaseSeconds,
    ) {}

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config, string $path = 'commerz-sales'): self
    {
        $carts = self::section($config, 'carts', $path);
        $checkout = self::section($config, 'checkout', $path);
        $orders = self::section($config, 'orders', $path);
        $payments = self::section($config, 'payments', $path);

        $requireDelivery = $checkout['require_delivery'] ?? true;

        if (! is_bool($requireDelivery)) {
            throw new InvalidConfiguration("{$path}.checkout.require_delivery", 'expected true or false');
        }

        $generator = $checkout['number_generator'] ?? 'sequence';

        if (! is_string($generator) || preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $generator) !== 1) {
            throw new InvalidConfiguration("{$path}.checkout.number_generator", 'expected a contribution key of 1..64 characters [a-z0-9_.-]');
        }

        $due = array_key_exists('payment_due_minutes', $orders) ? $orders['payment_due_minutes'] : 4320;

        return new self(
            self::int($carts['max_lines'] ?? 250, 1, 10_000, "{$path}.carts.max_lines"),
            self::int($carts['max_quantity'] ?? 100_000, 1, 1_000_000, "{$path}.carts.max_quantity"),
            $requireDelivery,
            $generator,
            $due === null ? null : self::int($due, 1, 525_600, "{$path}.orders.payment_due_minutes"),
            self::int($orders['expire_batch'] ?? 100, 1, 1000, "{$path}.orders.expire_batch"),
            self::int($payments['lease_seconds'] ?? 120, 10, 3600, "{$path}.payments.lease_seconds"),
        );
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $key, string $path): array
    {
        $section = $config[$key] ?? [];

        return is_array($section) ? $section : throw new InvalidConfiguration("{$path}.{$key}", 'expected an array');
    }

    private static function int(mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidConfiguration($path, "expected an integer from {$min} to {$max}");
        }

        return $value;
    }
}
