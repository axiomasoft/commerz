<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** Required attributes of the product type are missing at creation, or a command tried to remove them. */
#[Api(since: '0.1')]
final class MissingRequiredAttributes extends CommerzException
{
    /** @param  list<string>  $codes */
    public function __construct(public readonly array $codes)
    {
        parent::__construct('Required attributes are missing: '.implode(', ', $codes).'.');
    }

    public function code(): string
    {
        return 'catalog.missing_required_attributes';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['codes' => implode(',', $this->codes)];
    }
}
