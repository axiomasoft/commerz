<?php

declare(strict_types=1);

namespace Commerz\Schema\Contracts;

use Commerz\Attributes\Api;

/** Логическое имя ('stock_levels') → физическое ('cmz_stock_levels'); префикс заморожен в cmz_schema_state. */
#[Api(since: '1.0')]
interface TableNames
{
    public function of(string $logical): string;
}
