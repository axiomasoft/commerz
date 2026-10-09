<?php

declare(strict_types=1);

namespace Commerz\Shipping\Common\Data;

use Commerz\Attributes\Api;

/** The only normalization of a postal code in the package, for zone members and for addresses alike. */
#[Api(since: '0.1')]
final class PostalCode
{
    /**
     * Upper-cases and drops spaces and dashes.
     *
     * @return string|null `[A-Z0-9]{1,10}`, or null when nothing usable is left
     */
    public static function normalize(?string $postalCode): ?string
    {
        if ($postalCode === null) {
            return null;
        }

        $canonical = str_replace([' ', '-'], '', strtoupper($postalCode));

        return preg_match('/\A[A-Z0-9]{1,10}\z/', $canonical) === 1 ? $canonical : null;
    }
}
