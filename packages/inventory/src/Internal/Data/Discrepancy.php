<?php

declare(strict_types=1);

namespace Commerz\Inventory\Internal\Data;

/** One disagreement the reconciler found; quantities are decimal strings so sums never overflow. */
final readonly class Discrepancy
{
    public const string LEDGER_SUM = 'ledger_sum';

    public const string LEDGER_BALANCE = 'ledger_balance';

    public const string RESERVED_SUM = 'reserved_sum';

    public const string RESERVATION_STATE = 'reservation_state';

    public const string TRANSFER_STATE = 'transfer_state';

    public function __construct(
        public string $kind,
        public string $ref,
        public string $expected,
        public string $actual,
    ) {}
}
