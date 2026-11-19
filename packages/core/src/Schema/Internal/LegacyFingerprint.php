<?php

declare(strict_types=1);

namespace Commerz\Schema\Internal;

use Illuminate\Database\ConnectionInterface;
use Packman\Attributes\Internal;

/** Recognizes the Commerz layout that predates an explicit fingerprint owner. */
#[Internal]
final class LegacyFingerprint
{
    public static function owns(ConnectionInterface $db, string $prefix): bool
    {
        $row = $db->selectOne(<<<'SQL'
            select 1
            from information_schema.columns
            where table_schema = current_schema()
              and ((table_name = ? and column_name in ('default_currency', 'order_number_prefix'))
                or (table_name = ? and column_name in ('scope', 'fingerprint', 'result')))
            having count(*) = 5
            SQL, [$prefix.'stores', $prefix.'operation_receipts'], useReadPdo: false);

        return $row !== null;
    }
}
