<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-stored-value`.
 */
#[Api(since: '0.1')]
final readonly class StoredValueOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'max_tenders_per_quote',
        'default_hold_ttl_seconds',
        'gift_cards',
    ];

    /** @var list<string> */
    private const array GIFT_CARD_KEYS = [
        'code_length',
        'code_alphabet',
        'code_prefix',
        'default_expiry_days',
    ];

    private const string DEFAULT_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        public int $maxTendersPerQuote = 5,
        public ?int $defaultHoldTtlSeconds = 1800,
        public int $codeLength = 16,
        public string $codeAlphabet = self::DEFAULT_ALPHABET,
        public string $codePrefix = 'GC',
        public ?int $defaultExpiryDays = null,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-stored-value'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $maxTenders = self::int(
            $validator,
            array_key_exists('max_tenders_per_quote', $config) ? $config['max_tenders_per_quote'] : 5,
            1,
            50,
            $path.'.max_tenders_per_quote',
        );

        $defaultHoldTtl = self::nullableInt(
            $validator,
            array_key_exists('default_hold_ttl_seconds', $config) ? $config['default_hold_ttl_seconds'] : 1800,
            60,
            2_592_000,
            $path.'.default_hold_ttl_seconds',
        );

        $giftCards = array_key_exists('gift_cards', $config) ? $config['gift_cards'] : [];

        if (! is_array($giftCards)) {
            $validator->add($path.'.gift_cards', 'expected an array');
            $giftCards = [];
        } else {
            $validator->rejectUnknownKeys($giftCards, self::GIFT_CARD_KEYS, $path.'.gift_cards');
        }

        $codeLength = self::int(
            $validator,
            array_key_exists('code_length', $giftCards) ? $giftCards['code_length'] : 16,
            8,
            32,
            $path.'.gift_cards.code_length',
        );

        $codeAlphabet = self::alphabet(
            $validator,
            array_key_exists('code_alphabet', $giftCards) ? $giftCards['code_alphabet'] : self::DEFAULT_ALPHABET,
            $path.'.gift_cards.code_alphabet',
        );

        $codePrefix = self::prefix(
            $validator,
            array_key_exists('code_prefix', $giftCards) ? $giftCards['code_prefix'] : 'GC',
            $path.'.gift_cards.code_prefix',
        );

        $defaultExpiryDays = self::nullableInt(
            $validator,
            array_key_exists('default_expiry_days', $giftCards) ? $giftCards['default_expiry_days'] : null,
            1,
            36500,
            $path.'.gift_cards.default_expiry_days',
        );

        $validator->failIfAny();

        return new self(
            maxTendersPerQuote: $maxTenders,
            defaultHoldTtlSeconds: $defaultHoldTtl,
            codeLength: $codeLength,
            codeAlphabet: $codeAlphabet,
            codePrefix: $codePrefix,
            defaultExpiryDays: $defaultExpiryDays,
        );
    }

    private static function int(ConfigurationValidator $validator, mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $validator->add($path, "expected an integer from {$min} to {$max}");

            return $min;
        }

        return $value;
    }

    private static function nullableInt(ConfigurationValidator $validator, mixed $value, int $min, int $max, string $path): ?int
    {
        if ($value === null) {
            return null;
        }

        return self::int($validator, $value, $min, $max, $path);
    }

    private static function alphabet(ConfigurationValidator $validator, mixed $value, string $path): string
    {
        if (! is_string($value)
            || preg_match('/\A[A-Z0-9]{16,64}\z/', $value) !== 1
            || count(array_unique(str_split($value))) !== strlen($value)) {
            $validator->add($path, 'expected 16 to 64 distinct characters of A-Z and 0-9');

            return self::DEFAULT_ALPHABET;
        }

        return $value;
    }

    private static function prefix(ConfigurationValidator $validator, mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/\A[A-Z0-9]{0,8}\z/', $value) !== 1) {
            $validator->add($path, 'expected 0 to 8 uppercase alphanumeric characters');

            return 'GC';
        }

        return $value;
    }
}
