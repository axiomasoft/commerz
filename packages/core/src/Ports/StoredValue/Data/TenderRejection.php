<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Packman\Attributes\Api;

/** Explains why a presented stored-value tender could not be applied to a quote. */
#[Api(since: '0.1')]
final readonly class TenderRejection
{
    public function __construct(
        public string $reference,
        public string $reason,
        public string $message,
    ) {}
}
