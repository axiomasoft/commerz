<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/**
 * Either no rate of a tax class applies in a zone at the moment of a calculation (fail-closed: a missing rate is a
 * configuration error, never a silent zero; a zero rate is set explicitly), or no rate has an id.
 */
#[Api(since: '0.1')]
final class TaxRateNotFound extends CommerzException
{
    /** @param  array<string, string>  $context */
    private function __construct(string $message, private readonly array $context)
    {
        parent::__construct($message);
    }

    /** @param  string  $at  the moment of the calculation as ISO 8601 text */
    public static function forClass(string $taxClass, string $zone, string $at): self
    {
        return new self(
            "No rate of tax class '{$taxClass}' applies in tax zone '{$zone}' at {$at}.",
            ['tax_class' => $taxClass, 'zone' => $zone, 'at' => $at],
        );
    }

    public static function forId(string $id): self
    {
        return new self("Tax rate '{$id}' was not found.", ['id' => $id]);
    }

    public function code(): string
    {
        return 'pricing.tax_rate_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return $this->context;
    }
}
