<?php

declare(strict_types=1);

namespace Commerz\B2b\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-b2b`.
 */
#[Api(since: '0.1')]
final readonly class B2bOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'default_quote_validity_days',
        'max_hierarchy_depth',
        'allow_self_approval',
        'quote_request_prefix',
    ];

    public function __construct(
        public int $defaultQuoteValidityDays = 30,
        public int $maxHierarchyDepth = 10,
        public bool $allowSelfApproval = false,
        public string $quoteRequestPrefix = 'RFQ',
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-b2b'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $validityDays = self::int(
            $validator,
            array_key_exists('default_quote_validity_days', $config) ? $config['default_quote_validity_days'] : 30,
            1,
            365,
            $path.'.default_quote_validity_days',
        );

        $maxDepth = self::int(
            $validator,
            array_key_exists('max_hierarchy_depth', $config) ? $config['max_hierarchy_depth'] : 10,
            0,
            32,
            $path.'.max_hierarchy_depth',
        );

        $allowSelfApproval = self::bool(
            $validator,
            array_key_exists('allow_self_approval', $config) ? $config['allow_self_approval'] : false,
            $path.'.allow_self_approval',
        );

        $prefix = self::prefix(
            $validator,
            array_key_exists('quote_request_prefix', $config) ? $config['quote_request_prefix'] : 'RFQ',
            $path.'.quote_request_prefix',
        );

        $validator->failIfAny();

        return new self(
            defaultQuoteValidityDays: $validityDays,
            maxHierarchyDepth: $maxDepth,
            allowSelfApproval: $allowSelfApproval,
            quoteRequestPrefix: $prefix,
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

    private static function bool(ConfigurationValidator $validator, mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            $validator->add($path, 'expected a boolean');

            return false;
        }

        return $value;
    }

    private static function prefix(ConfigurationValidator $validator, mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/\A[A-Z0-9]{1,12}\z/', $value) !== 1) {
            $validator->add($path, 'expected 1 to 12 uppercase alphanumeric characters');

            return 'RFQ';
        }

        return $value;
    }
}
