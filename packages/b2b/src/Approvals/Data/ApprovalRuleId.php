<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ApprovalRuleId extends EntityId {}
