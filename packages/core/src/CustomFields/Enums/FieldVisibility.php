<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Enums;

use Packman\Attributes\Api;

/**
 * Controls whether a custom field is exposed on the public storefront or restricted to private backoffice/admin surfaces.
 */
#[Api(since: '0.1')]
enum FieldVisibility: string
{
    case PublicStorefront = 'public_storefront';
    case PrivateAdmin = 'private_admin';

    public function isPublic(): bool
    {
        return $this === self::PublicStorefront;
    }

    public function isPrivate(): bool
    {
        return $this === self::PrivateAdmin;
    }

    /**
     * Whether an actor/projection at `$this` audience level is permitted to read or filter a field of `$fieldVisibility`.
     */
    public function canAccess(self $fieldVisibility): bool
    {
        return $this === self::PrivateAdmin || $fieldVisibility === self::PublicStorefront;
    }
}
