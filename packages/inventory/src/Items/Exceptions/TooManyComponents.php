<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A sellable (kit) is made of at most {@see MAX} stock items. */
#[Api(since: '0.1')]
final class TooManyComponents extends CommerzException
{
    public const int MAX = 20;

    public function __construct(public readonly string $sellable)
    {
        parent::__construct("Sellable '{$sellable}' already has ".self::MAX.' components.');
    }

    public function code(): string
    {
        return 'inventory.too_many_components';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['sellable' => $this->sellable, 'max' => self::MAX];
    }
}
