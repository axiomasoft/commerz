<?php

declare(strict_types=1);

namespace Commerz\Schema;

use Commerz\Attributes\Api;
use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidConfiguration;
use Commerz\Schema\Contracts\SchemaOwnership;
use Commerz\Schema\Internal\SchemaFingerprint;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;

/**
 * The only source of Commerz table names for migrations, models and raw SQL: logical `stock_levels` → physical
 * `cmz_stock_levels`. The first lookup in a process checks the install fingerprint (not at boot, so `migrate` works
 * on an empty database); a changed prefix fails with `InvalidConfiguration('commerz.table_prefix')`.
 */
#[Api(since: '0.1')]
final class TableNames
{
    private bool $verified = false;

    public function __construct(
        private readonly CommerzConfig $config,
        private readonly SchemaOwnership $ownership,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    /**
     * @throws InvalidArgumentException for a malformed logical name
     * @throws LogicException when no package declared the table's noun
     * @throws InvalidConfiguration when the configured prefix differs from the installed one
     */
    public function table(string $logical): string
    {
        if (preg_match('/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/', $logical) !== 1) {
            throw new InvalidArgumentException("Logical table '{$logical}' must be a lower-case snake name.");
        }

        $physical = $this->config->tablePrefix.$logical;

        if (strlen($physical) > ConstraintNames::MAX_IDENTIFIER) {
            throw new InvalidArgumentException("Table name '{$physical}' is longer than 63 bytes.");
        }

        if ($this->ownership->ownerOf($logical) === null) {
            throw new LogicException("Table '{$logical}' has no owner: declare its noun through SchemaOwnership.");
        }

        if (! $this->verified) {
            SchemaFingerprint::verify($this->connections->connection($this->config->connection), $this->config->tablePrefix);
            $this->verified = true;
        }

        return $physical;
    }

    /** The connection every Commerz table lives on; null is the application default. */
    public function connection(): ?string
    {
        return $this->config->connection;
    }

    public function prefix(): string
    {
        return $this->config->tablePrefix;
    }
}
