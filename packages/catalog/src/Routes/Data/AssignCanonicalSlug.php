<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/** Assigns the canonical slug for an entity in a channel and locale, retiring any previous canonical slug for 301 redirects. */
#[Api(since: '0.1')]
final readonly class AssignCanonicalSlug
{
    public function __construct(
        public ChannelId $channel,
        public string $locale,
        public string $slug,
        public string $entityType,
        public string $entityId,
        public ?SeoMeta $seoMeta = null,
    ) {
        if (preg_match('/\A[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*\z/', $locale) !== 1 || strlen($locale) > 16) {
            throw new InvalidCommand('locale', 'must be a valid locale tag up to 16 bytes');
        }

        if (preg_match('/\A[a-z0-9]+(?:[a-z0-9\-\/]*[a-z0-9])?\z/', $slug) !== 1 || strlen($slug) > 255 || str_contains($slug, '//')) {
            throw new InvalidCommand('slug', 'must be 1 to 255 lowercase URL path characters without leading/trailing/double slashes');
        }

        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $entityType) !== 1) {
            throw new InvalidCommand('entityType', 'must match [a-z][a-z0-9_]{0,63}');
        }

        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $entityId) !== 1) {
            throw new InvalidCommand('entityId', 'must be a valid UUID');
        }
    }
}
