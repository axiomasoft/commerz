<?php

declare(strict_types=1);

namespace Commerz\Pagination;

use Packman\Attributes\Api;

/**
 * One page of a cursor-paginated list.
 *
 * @template-covariant T of object
 */
#[Api(since: '0.1')]
final readonly class Page
{
    /** @param  list<T>  $items */
    public function __construct(public array $items, public ?Cursor $next = null) {}

    public function hasMore(): bool
    {
        return $this->next !== null;
    }
}
