<?php

declare(strict_types=1);

namespace Commerz\Catalog\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-catalog`.
 */
#[Api(since: '0.1')]
final readonly class CatalogOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'max_generated_variants',
    ];

    public function __construct(
        public int $maxGeneratedVariants = 100,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-catalog'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $max = array_key_exists('max_generated_variants', $config) ? $config['max_generated_variants'] : 100;

        if (! is_int($max) || $max < 1 || $max > 10000) {
            $validator->add($path.'.max_generated_variants', 'expected an integer from 1 to 10000');
            $max = 100;
        }

        $validator->failIfAny();

        return new self($max);
    }
}
