<?php

declare(strict_types=1);

namespace Commerz\Money;

use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\InvalidCommand;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

#[Api(since: '0.1')]
final class InvalidCurrency extends InvalidCommand
{
    public function __construct(public readonly string $given)
    {
        parent::__construct('currency', "'{$given}' is not a known ISO 4217 code", 'money.invalid_currency');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::InvalidCurrency,
            category: PackmanErrorCategory::Validation,
            retryable: false,
            details: [
                'field' => $this->field,
                'reason' => $this->reason,
                'given' => $this->given,
            ],
        );
    }
}
