<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum AssociateRole: string
{
    case Admin = 'admin';
    case Approver = 'approver';
    case Buyer = 'buyer';
    case Viewer = 'viewer';

    public function canPurchase(): bool
    {
        return $this !== self::Viewer;
    }

    public function canApprove(): bool
    {
        return $this === self::Admin || $this === self::Approver;
    }

    public function satisfiesApproverRole(self $required): bool
    {
        if ($this === self::Admin) {
            return true;
        }

        return $this === self::Approver && $required === self::Approver;
    }
}
