<?php

declare(strict_types=1);

namespace Commerz\Sales\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-sales`.
 */
#[Api(since: '0.1')]
final readonly class SalesOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'carts',
        'checkout',
        'orders',
        'payments',
    ];

    /** @var list<string> */
    private const array CARTS_KEYS = [
        'max_lines',
        'max_quantity',
    ];

    /** @var list<string> */
    private const array CHECKOUT_KEYS = [
        'require_delivery',
        'number_generator',
    ];

    /** @var list<string> */
    private const array ORDERS_KEYS = [
        'payment_due_minutes',
        'expire_batch',
    ];

    /** @var list<string> */
    private const array PAYMENTS_KEYS = [
        'lease_seconds',
    ];

    public function __construct(
        public int $maxCartLines = 250,
        public int $maxLineQuantity = 100_000,
        public bool $requireDelivery = true,
        public string $numberGenerator = 'sequence',
        public ?int $paymentDueMinutes = 4320,
        public int $expireBatch = 100,
        public int $leaseSeconds = 120,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-sales'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $carts = self::section($validator, $config, 'carts', self::CARTS_KEYS, $path);
        $checkout = self::section($validator, $config, 'checkout', self::CHECKOUT_KEYS, $path);
        $orders = self::section($validator, $config, 'orders', self::ORDERS_KEYS, $path);
        $payments = self::section($validator, $config, 'payments', self::PAYMENTS_KEYS, $path);

        $maxCartLines = self::int($validator, array_key_exists('max_lines', $carts) ? $carts['max_lines'] : 250, 1, 10_000, $path.'.carts.max_lines');
        $maxLineQuantity = self::int($validator, array_key_exists('max_quantity', $carts) ? $carts['max_quantity'] : 100_000, 1, 1_000_000, $path.'.carts.max_quantity');

        $requireDelivery = array_key_exists('require_delivery', $checkout) ? $checkout['require_delivery'] : true;

        if (! is_bool($requireDelivery)) {
            $validator->add($path.'.checkout.require_delivery', 'expected true or false');
            $requireDelivery = true;
        }

        $generator = array_key_exists('number_generator', $checkout) ? $checkout['number_generator'] : 'sequence';

        if (! is_string($generator) || preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $generator) !== 1) {
            $validator->add($path.'.checkout.number_generator', 'expected a contribution key of 1..64 characters [a-z0-9_.-]');
            $generator = 'sequence';
        }

        $dueRaw = array_key_exists('payment_due_minutes', $orders) ? $orders['payment_due_minutes'] : 4320;
        $paymentDueMinutes = $dueRaw === null
            ? null
            : self::int($validator, $dueRaw, 1, 525_600, $path.'.orders.payment_due_minutes');

        $expireBatch = self::int($validator, array_key_exists('expire_batch', $orders) ? $orders['expire_batch'] : 100, 1, 1000, $path.'.orders.expire_batch');
        $leaseSeconds = self::int($validator, array_key_exists('lease_seconds', $payments) ? $payments['lease_seconds'] : 120, 10, 3600, $path.'.payments.lease_seconds');

        $validator->failIfAny();

        return new self(
            $maxCartLines,
            $maxLineQuantity,
            $requireDelivery,
            $generator,
            $paymentDueMinutes,
            $expireBatch,
            $leaseSeconds,
        );
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  list<string>  $allowedKeys
     * @return array<array-key, mixed>
     */
    private static function section(
        ConfigurationValidator $validator,
        array $config,
        string $key,
        array $allowedKeys,
        string $path,
    ): array {
        $section = array_key_exists($key, $config) ? $config[$key] : [];

        if (! is_array($section)) {
            $validator->add("{$path}.{$key}", 'expected an array');

            return [];
        }

        $validator->rejectUnknownKeys($section, $allowedKeys, "{$path}.{$key}");

        return $section;
    }

    private static function int(ConfigurationValidator $validator, mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $validator->add($path, "expected an integer from {$min} to {$max}");

            return $min;
        }

        return $value;
    }
}
