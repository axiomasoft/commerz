<?php

declare(strict_types=1);

namespace Commerz\Inventory\Internal\Data;

use Packman\Attributes\Internal;

#[Internal]
final readonly class ReconciliationReport
{
    /** @param  list<Discrepancy>  $discrepancies */
    public function __construct(public array $discrepancies) {}

    public function clean(): bool
    {
        return $this->discrepancies === [];
    }

    /** @return list<Discrepancy> */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->discrepancies, static fn (Discrepancy $d): bool => $d->kind === $kind));
    }
}
