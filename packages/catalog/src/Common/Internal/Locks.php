<?php

declare(strict_types=1);

namespace Commerz\Catalog\Common\Internal;

use Illuminate\Database\ConnectionInterface;

/** Advisory locks of the catalog; take them inside a transaction, they are released at its end. */
final class Locks
{
    /** `crc32('commerz.catalog.category_tree')` */
    private const int CATEGORY_TREE = 2_849_870_896;

    /** Serialises every change of the category tree (moves must not form a cycle). */
    public static function categoryTree(ConnectionInterface $db): void
    {
        $db->statement('select pg_advisory_xact_lock(?)', [self::CATEGORY_TREE]);
    }
}
