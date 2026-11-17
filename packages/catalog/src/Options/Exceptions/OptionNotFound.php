<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

#[Api(since: '0.1')]
final class OptionNotFound extends NotFound
{
    /** @param  string  $reference  an option id or code */
    public function __construct(string $reference)
    {
        parent::__construct('Option', $reference, 'catalog.option_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::OptionNotFound,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}
