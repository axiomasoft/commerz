<?php

declare(strict_types=1);

namespace Commerz\Stores\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A currency is outside the channel's currency list (including a default currency that is not in it). */
#[Api(since: '0.1')]
final class CurrencyNotAllowed extends CommerzException
{
    public function __construct(public readonly string $channel, public readonly string $currency)
    {
        parent::__construct("Currency {$currency} is not allowed in channel '{$channel}'.");
    }

    public function code(): string
    {
        return 'stores.currency_not_allowed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['channel' => $this->channel, 'currency' => $this->currency];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::CurrencyNotAllowed,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
