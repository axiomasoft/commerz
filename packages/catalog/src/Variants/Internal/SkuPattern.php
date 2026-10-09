<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;

/** `TEE-{color}-{size}`: each `{optionCode}` is replaced by the value code of that axis. */
final readonly class SkuPattern
{
    private const string PLACEHOLDER = '/\{([^{}]*)\}/';

    /**
     * Checks the pattern against the generated axes: every placeholder names one of them, and every axis with more than
     * one value is named (otherwise two combinations would get one SKU).
     *
     * @param  array<array-key, list<string>>  $axes  option code => value codes
     */
    public function __construct(public string $pattern, array $axes)
    {
        preg_match_all(self::PLACEHOLDER, $pattern, $matches);
        $named = $matches[1];

        foreach ($named as $code) {
            if (! array_key_exists($code, $axes)) {
                throw new InvalidCommand('skuPattern', "'{{$code}}' names no axis of the generation", 'catalog.invalid_sku_pattern');
            }
        }

        foreach ($axes as $code => $values) {
            if (count($values) > 1 && ! in_array((string) $code, $named, true)) {
                throw new InvalidCommand('skuPattern', "axis '{$code}' has several values but no '{{$code}}' placeholder", 'catalog.invalid_sku_pattern');
            }
        }
    }

    /**
     * @param  array<string, string>  $combination  option code => value code
     *
     * @throws InvalidCommand `catalog.invalid_sku` when the result is not a valid SKU
     */
    public function render(array $combination): string
    {
        $sku = preg_replace_callback(self::PLACEHOLDER, static fn (array $match): string => $combination[$match[1]], $this->pattern);

        return CatalogCodes::sku((string) $sku);
    }
}
