<?php

declare(strict_types=1);

namespace Commerz\Promotions\Common\Internal;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Money\RoundingMode;
use Commerz\Ports\Promotions\Enums\RestoreReason;

/** The only reader of `config('commerz-promotions.*')`. */
final readonly class PromotionsConfig
{
    /** @param  list<RestoreReason>  $restoreOn */
    public function __construct(
        public RoundingMode $rounding,
        public int $maxCodes,
        public array $restoreOn,
        public string $couponAlphabet,
        public int $maxGenerate,
    ) {}

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config, string $path = 'commerz-promotions'): self
    {
        $rounding = $config['rounding'] ?? RoundingMode::HalfUp;
        $rounding = $rounding instanceof RoundingMode ? $rounding : (is_string($rounding) ? RoundingMode::tryFrom($rounding) : null);

        if ($rounding === null) {
            throw new InvalidConfiguration("{$path}.rounding", 'expected a rounding mode such as half_up or half_even');
        }

        $coupons = $config['coupons'] ?? [];

        if (! is_array($coupons)) {
            throw new InvalidConfiguration("{$path}.coupons", 'expected an array');
        }

        return new self(
            $rounding,
            self::int($config['max_codes'] ?? 5, 1, 50, "{$path}.max_codes"),
            self::restoreOn($config['restore_on'] ?? ['cancelled', 'expired'], "{$path}.restore_on"),
            self::alphabet($coupons['alphabet'] ?? '23456789ABCDEFGHJKMNPQRSTUVWXYZ', "{$path}.coupons.alphabet"),
            self::int($coupons['max_generate'] ?? 50000, 1, 1_000_000, "{$path}.coupons.max_generate"),
        );
    }

    public function restores(RestoreReason $reason): bool
    {
        return in_array($reason, $this->restoreOn, true);
    }

    private static function int(mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidConfiguration($path, "expected an integer from {$min} to {$max}");
        }

        return $value;
    }

    /** @return list<RestoreReason> */
    private static function restoreOn(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidConfiguration($path, 'expected a list of restore reasons');
        }

        $reasons = [];

        foreach ($value as $item) {
            $reason = $item instanceof RestoreReason ? $item : (is_string($item) ? RestoreReason::tryFrom($item) : null);

            if ($reason === null) {
                throw new InvalidConfiguration($path, 'expected restore reasons: cancelled, expired or refunded');
            }

            if (! in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    private static function alphabet(mixed $value, string $path): string
    {
        if (! is_string($value)
            || preg_match('/\A[A-Z0-9]{2,64}\z/', $value) !== 1
            || count(array_unique(str_split($value))) !== strlen($value)) {
            throw new InvalidConfiguration($path, 'expected 2 to 64 distinct characters of A-Z and 0-9');
        }

        return $value;
    }
}
