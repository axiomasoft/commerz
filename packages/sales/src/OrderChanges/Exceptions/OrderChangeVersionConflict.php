<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Optimistic concurrency conflict (409, INV-OMS-001): the order version changed before confirm().
 */
#[Api(since: '0.1')]
final class OrderChangeVersionConflict extends CommerzException
{
    public const string DOMAIN_CODE = 'ORDERS_ORDER_CHANGE_VERSION_CONFLICT';

    public const string INVARIANT = 'INV-OMS-001';

    public function __construct(
        public readonly string $change,
        public readonly string $order,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(sprintf(
            '%s (%s): order \'%s\' is at version %d, expected %d while confirming change \'%s\'.',
            self::DOMAIN_CODE,
            self::INVARIANT,
            $order,
            $actualVersion,
            $expectedVersion,
            $change,
        ));
    }

    public function code(): string
    {
        return SalesErrorCode::OrderChangeVersionConflict->value;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'change' => $this->change,
            'order' => $this->order,
            'expected_version' => $this->expectedVersion,
            'actual_version' => $this->actualVersion,
            'domain_code' => self::DOMAIN_CODE,
            'invariant' => self::INVARIANT,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::OrderChangeVersionConflict,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
