<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The slug is already claimed by another entity in the same channel and locale. */
#[Api(since: '0.1')]
final class DuplicateSlug extends CommerzException
{
    public function __construct(
        public readonly string $channel,
        public readonly string $locale,
        public readonly string $slug,
    ) {
        parent::__construct("Slug '{$slug}' is already taken in channel '{$channel}' for locale '{$locale}'.");
    }

    public function code(): string
    {
        return 'catalog.duplicate_slug';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'channel' => $this->channel,
            'locale' => $this->locale,
            'slug' => $this->slug,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::DuplicateSlug,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
