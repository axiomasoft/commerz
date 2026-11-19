<?php

declare(strict_types=1);

namespace Commerz\Diagnostics;

use Closure;
use Commerz\Configuration\CommerzOptions;
use Commerz\Schema\TableNames;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Schema\Data\Compiler\SchemaFingerprint;
use Throwable;

/**
 * Verifies Commerz configuration, database install fingerprint, and compiled plugin registry health.
 */
#[Api(since: '0.1')]
final readonly class CommerzDoctorCheck implements DoctorCheck
{
    /**
     * @param  (Closure(): CommerzOptions)|null  $optionsResolver
     * @param  (Closure(): TableNames)|null  $tablesResolver
     * @param  (Closure(): PluginInspector)|null  $pluginsResolver
     */
    public function __construct(
        private ConfigRepository $config,
        private ConnectionResolverInterface $connections,
        private ?Closure $optionsResolver = null,
        private ?Closure $tablesResolver = null,
        private ?Closure $pluginsResolver = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'commerz.runtime.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz configuration, install fingerprint, and plugin registry';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.doctor.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.doctor.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz.php can be compiled cleanly.',
            )];
        }

        if ($options->connection !== null && ! is_array($this->config->get('database.connections.'.$options->connection))) {
            return [DoctorFinding::error(
                code: 'commerz.doctor.missing_connection',
                message: sprintf('Configured Commerz database connection "%s" is not defined in database.connections.', $options->connection),
                remediation: 'Define the connection in config/database.php or set commerz.connection to null.',
                evidence: ['connection' => $options->connection],
            )];
        }

        $findings = [];
        $prefix = $options->tablePrefix->value();

        if ($this->tablesResolver !== null) {
            $findings = array_merge($findings, $this->checkDatabaseFingerprint($options, $prefix));
        }

        if ($this->pluginsResolver !== null) {
            $findings = array_merge($findings, $this->checkPlugins());
        }

        if ($findings !== []) {
            return $findings;
        }

        return [DoctorFinding::ok(
            code: 'commerz.doctor.healthy',
            message: sprintf(
                'Configuration OK (prefix %s, stock %s, %s); install fingerprint and plugins healthy.',
                $prefix,
                $options->stock->value,
                $options->customers ?? 'no customer directory',
            ),
            evidence: [
                'table_prefix' => $prefix,
                'stock' => $options->stock->value,
                'plugins' => count($options->plugins),
            ],
        )];
    }

    private function resolveOptions(): CommerzOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz', []);

        return CommerzOptions::fromArray(is_array($raw) ? $raw : [], 'commerz');
    }

    /** @return list<DoctorFinding> */
    private function checkDatabaseFingerprint(CommerzOptions $options, string $prefix): array
    {
        assert($this->tablesResolver !== null);

        try {
            $connection = $this->connections->connection($options->connection);
            $table = ($this->tablesResolver)()->table('schema_state');

            if (! $connection instanceof Connection || ! $connection->getSchemaBuilder()->hasTable($table)) {
                return [DoctorFinding::error(
                    code: 'commerz.doctor.schema_not_installed',
                    message: "Commerz schema state table '{$table}' does not exist.",
                    remediation: 'Run `php artisan commerz:install` or `php artisan migrate`.',
                    evidence: ['table' => $table],
                )];
            }

            TableNames::verifyFingerprint($connection, $prefix);

            $row = $connection->selectOne(
                sprintf('select value::text as value from "%s" where key = ?', $table),
                [SchemaFingerprint::KEY],
                useReadPdo: false,
            );
            $value = is_object($row) ? (get_object_vars($row)['value'] ?? null) : null;
            $stored = is_string($value) ? json_decode($value, true) : null;
            $hash = is_array($stored) && is_string($stored['hash'] ?? null) ? $stored['hash'] : null;

            if ($hash === null) {
                return [DoctorFinding::error(
                    code: 'commerz.doctor.missing_fingerprint',
                    message: "Commerz schema fingerprint row is missing in '{$table}'.",
                    remediation: 'Run `php artisan commerz:install` to initialize the install fingerprint.',
                    evidence: ['table' => $table],
                )];
            }

            $expected = SchemaFingerprint::document($prefix, isset($stored['owner']) ? TableNames::OWNER : null)['hash'];

            if ($hash !== $expected) {
                return [DoctorFinding::error(
                    code: 'commerz.doctor.fingerprint_mismatch',
                    message: 'The installed schema fingerprint differs from this Commerz package version.',
                    remediation: 'Run `php artisan migrate` to align schema state.',
                    evidence: ['stored' => substr($hash, 0, 12), 'expected' => substr($expected, 0, 12)],
                )];
            }
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.doctor.schema_error',
                message: $exception->getMessage(),
                remediation: 'Verify the Commerz database connection and run migrations.',
            )];
        }

        return [];
    }

    /** @return list<DoctorFinding> */
    private function checkPlugins(): array
    {
        assert($this->pluginsResolver !== null);

        try {
            ($this->pluginsResolver)()->describe();
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.doctor.plugin_graph_error',
                message: $exception->getMessage(),
                remediation: 'Verify plugin declarations and configuration under commerz.plugins.',
            )];
        }

        return [];
    }
}
