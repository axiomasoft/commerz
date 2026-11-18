<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

/** Root of Commerz domain errors, with Packman descriptors and stable domain code/context metadata. */
#[Api(since: '0.1')]
abstract class CommerzException extends CodedException
{
    abstract public function code(): string;

    public function category(): ErrorCategory
    {
        return $this->errorDescriptor()->category;
    }

    /** @return array<string, scalar|null> */
    abstract public function context(): array;
}
