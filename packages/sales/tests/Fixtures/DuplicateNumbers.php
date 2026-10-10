<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Sales\Numbering\Data\OrderNumbering;

/** Numbers every order `DUP-1`, so the second order of a store collides. */
final readonly class DuplicateNumbers implements OrderNumberGenerator
{
    public function key(): string
    {
        return 'duplicate';
    }

    public function next(OrderNumbering $numbering): string
    {
        return 'DUP-1';
    }
}
