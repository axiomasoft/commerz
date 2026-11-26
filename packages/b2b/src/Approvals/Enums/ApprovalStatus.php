<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Canceled = 'canceled';
}
