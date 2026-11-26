<?php

declare(strict_types=1);

namespace Commerz\Promotions\Configuration;

use Commerz\Money\RoundingMode;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-promotions`.
 */
#[Api(since: '0.1')]
final readonly class PromotionsOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'rounding',
        'max_codes',
        'restore_on',
        'hold_on_checkout',
        'hold_ttl_seconds',
        'near_miss',
        'coupons',
    ];

    /** @var list<string> */
    private const array NEAR_MISS_KEYS = [
        'min_progress_bp',
        'timeout_ms',
    ];

    /** @var list<string> */
    private const array COUPON_KEYS = [
        'alphabet',
        'max_generate',
    ];

    private const string DEFAULT_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** @param list<RestoreReason> $restoreOn */
    public function __construct(
        public RoundingMode $rounding = RoundingMode::HalfUp,
        public int $maxCodes = 5,
        public array $restoreOn = [RestoreReason::Cancelled, RestoreReason::Expired],
        public string $couponAlphabet = self::DEFAULT_ALPHABET,
        public int $maxGenerate = 50000,
        public bool $holdOnCheckout = false,
        public int $holdTtlSeconds = 900,
        public int $nearMissMinProgressBp = 0,
        public int $nearMissTimeoutMs = 200,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-promotions'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $roundingRaw = array_key_exists('rounding', $config) ? $config['rounding'] : RoundingMode::HalfUp;
        $rounding = $roundingRaw instanceof RoundingMode ? $roundingRaw : (is_string($roundingRaw) ? RoundingMode::tryFrom($roundingRaw) : null);

        if ($rounding === null) {
            $validator->add($path.'.rounding', 'expected a rounding mode such as half_up or half_even');
            $rounding = RoundingMode::HalfUp;
        }

        $maxCodes = self::int($validator, array_key_exists('max_codes', $config) ? $config['max_codes'] : 5, 1, 50, $path.'.max_codes');
        $restoreOn = self::restoreOn(
            $validator,
            array_key_exists('restore_on', $config) ? $config['restore_on'] : ['cancelled', 'expired'],
            $path.'.restore_on',
        );

        $holdOnCheckoutRaw = array_key_exists('hold_on_checkout', $config) ? $config['hold_on_checkout'] : false;

        if (! is_bool($holdOnCheckoutRaw)) {
            $validator->add($path.'.hold_on_checkout', 'expected a boolean');
            $holdOnCheckout = false;
        } else {
            $holdOnCheckout = $holdOnCheckoutRaw;
        }

        $holdTtlSeconds = self::int(
            $validator,
            array_key_exists('hold_ttl_seconds', $config) ? $config['hold_ttl_seconds'] : 900,
            1,
            86_400,
            $path.'.hold_ttl_seconds',
        );

        $nearMiss = array_key_exists('near_miss', $config) ? $config['near_miss'] : [];

        if (! is_array($nearMiss)) {
            $validator->add($path.'.near_miss', 'expected an array');
            $nearMiss = [];
        } else {
            $validator->rejectUnknownKeys($nearMiss, self::NEAR_MISS_KEYS, $path.'.near_miss');
        }

        $nearMissMinProgressBp = self::int(
            $validator,
            array_key_exists('min_progress_bp', $nearMiss) ? $nearMiss['min_progress_bp'] : 0,
            0,
            9_999,
            $path.'.near_miss.min_progress_bp',
        );
        $nearMissTimeoutMs = self::int(
            $validator,
            array_key_exists('timeout_ms', $nearMiss) ? $nearMiss['timeout_ms'] : 200,
            1,
            30_000,
            $path.'.near_miss.timeout_ms',
        );

        $coupons = array_key_exists('coupons', $config) ? $config['coupons'] : [];

        if (! is_array($coupons)) {
            $validator->add($path.'.coupons', 'expected an array');
            $coupons = [];
        } else {
            $validator->rejectUnknownKeys($coupons, self::COUPON_KEYS, $path.'.coupons');
        }

        $couponAlphabet = self::alphabet(
            $validator,
            array_key_exists('alphabet', $coupons) ? $coupons['alphabet'] : self::DEFAULT_ALPHABET,
            $path.'.coupons.alphabet',
        );
        $maxGenerate = self::int(
            $validator,
            array_key_exists('max_generate', $coupons) ? $coupons['max_generate'] : 50000,
            1,
            1_000_000,
            $path.'.coupons.max_generate',
        );

        $validator->failIfAny();

        return new self(
            $rounding,
            $maxCodes,
            $restoreOn,
            $couponAlphabet,
            $maxGenerate,
            $holdOnCheckout,
            $holdTtlSeconds,
            $nearMissMinProgressBp,
            $nearMissTimeoutMs,
        );
    }

    public function restores(RestoreReason $reason): bool
    {
        return in_array($reason, $this->restoreOn, true);
    }

    private static function int(ConfigurationValidator $validator, mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $validator->add($path, "expected an integer from {$min} to {$max}");

            return $min;
        }

        return $value;
    }

    /** @return list<RestoreReason> */
    private static function restoreOn(ConfigurationValidator $validator, mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $validator->add($path, 'expected a list of restore reasons');

            return [];
        }

        $reasons = [];

        foreach ($value as $item) {
            $reason = $item instanceof RestoreReason ? $item : (is_string($item) ? RestoreReason::tryFrom($item) : null);

            if ($reason === null) {
                $validator->add($path, 'expected restore reasons: cancelled, expired or refunded');

                continue;
            }

            if (! in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    private static function alphabet(ConfigurationValidator $validator, mixed $value, string $path): string
    {
        if (! is_string($value)
            || preg_match('/\A[A-Z0-9]{2,64}\z/', $value) !== 1
            || count(array_unique(str_split($value))) !== strlen($value)) {
            $validator->add($path, 'expected 2 to 64 distinct characters of A-Z and 0-9');

            return self::DEFAULT_ALPHABET;
        }

        return $value;
    }
}
