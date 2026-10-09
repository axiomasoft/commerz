<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The product already has a variant (active or archived) with this combination of axis values (CAT-01). */
#[Api(since: '0.1')]
final class DuplicateCombination extends CommerzException
{
    /** @param  array<string, string>  $axes  option code => value code; empty for the single variant of a product without axes */
    public function __construct(public readonly array $axes)
    {
        parent::__construct($axes === [] ? 'The product already has its variant.' : 'The product already has a variant '.self::describe($axes).'.');
    }

    public function code(): string
    {
        return 'catalog.duplicate_combination';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['axes' => self::describe($this->axes)];
    }

    /** @param  array<string, string>  $axes */
    private static function describe(array $axes): string
    {
        return implode(',', array_map(static fn (string $option, string $value): string => "{$option}={$value}", array_keys($axes), $axes));
    }
}
