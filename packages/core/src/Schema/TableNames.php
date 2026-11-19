<?php

declare(strict_types=1);

namespace Commerz\Schema;

use Closure;
use Commerz\Schema\Internal\LegacyFingerprint;
use Illuminate\Database\ConnectionInterface;
use Packman\Attributes\Api;
use Packman\Schema\Data\Compiler\SchemaFingerprint;
use Packman\Schema\Data\Compiler\TableNames as PackmanTables;

/** Resolves tables in the Commerz schema configured for this application. */
#[Api(since: '0.1')]
final class TableNames
{
    public const string OWNER = 'axiomasoft/commerz-core';

    private PackmanTables $tables;

    /** @param Closure(): PackmanTables $resolve */
    public function __construct(private readonly Closure $resolve) {}

    public static function verifyFingerprint(ConnectionInterface $connection, string $prefix): void
    {
        SchemaFingerprint::verify($connection, $prefix, 'commerz.table_prefix', self::OWNER, LegacyFingerprint::owns(...));
    }

    public function resolver(): PackmanTables
    {
        return $this->tables ??= ($this->resolve)();
    }

    public function table(string $logical): string
    {
        return $this->resolver()->table($logical);
    }

    public function prefix(): string
    {
        return $this->resolver()->prefix();
    }

    public function connection(): ?string
    {
        return $this->resolver()->connection();
    }
}
