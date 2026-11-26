<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class InitiateApprovalFlow
{
    public string $subjectRef;

    public function __construct(
        public BusinessUnitId $businessUnitId,
        public CustomerRef $requestedBy,
        string $subjectRef,
        public Money $amount,
    ) {
        $trimmed = trim($subjectRef);

        if (strlen($trimmed) > 191 || preg_match('/\A[a-z0-9_.\-]{1,64}:[^\p{Cc}]{1,120}\z/u', $trimmed) !== 1) {
            throw new InvalidCommand('subjectRef', 'must look like type:id and fit 191 bytes', 'b2b.invalid_subject_ref');
        }

        if ($amount->isNegative()) {
            throw new InvalidCommand('amount', 'must not be negative', 'b2b.invalid_amount');
        }

        $this->subjectRef = $trimmed;
    }
}
