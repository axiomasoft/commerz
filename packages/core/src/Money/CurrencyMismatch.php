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
final class CurrencyMismatch extends InvalidCommand
{
    public function __construct(public readonly string $left, public readonly string $right)
    {
        parent::__construct('currency', "{$left} and {$right} cannot be combined", 'money.currency_mismatch');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::CurrencyMismatch,
            category: PackmanErrorCategory::Validation,
            retryable: false,
            details: [
                'field' => $this->field,
                'reason' => $this->reason,
                'left' => $this->left,
                'right' => $this->right,
            ],
        );
    }
}
