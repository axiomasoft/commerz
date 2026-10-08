<?php

declare(strict_types=1);

namespace Commerz\Console;

use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\CommerzException;
use Commerz\Errors\InvalidConfiguration;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Schema\Internal\SchemaFingerprint;
use Commerz\Schema\TableNames;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

#[Description('Check the Commerz configuration, install fingerprint and plugin registry.')]
#[Signature('commerz:doctor')]
final class DoctorCommand extends Command
{
    private bool $failed = false;

    public function handle(Container $container): int
    {
        if ($this->check('Configuration', fn (): string => $this->configuration($container)) === null) {
            return self::FAILURE;
        }

        $this->check('Database and fingerprint', fn (): string => $this->fingerprint($container, $container->make(CommerzConfig::class)));
        $this->check('Plugins', fn (): string => $this->plugins($container));

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function configuration(Container $container): string
    {
        $config = $container->make(CommerzConfig::class);

        return "prefix {$config->tablePrefix}, stock {$config->stock->value}, ".($config->customers ?? 'no customer directory');
    }

    private function fingerprint(Container $container, CommerzConfig $config): string
    {
        $connection = $container->make(ConnectionResolverInterface::class)->connection($config->connection);
        $table = $container->make(TableNames::class)->table('schema_state');

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            throw new InvalidConfiguration('commerz.table_prefix', "table '{$table}' does not exist: run `php artisan commerz:install`");
        }

        SchemaFingerprint::verify($connection, $config->tablePrefix);

        $row = $connection->selectOne(sprintf('select value::text as value from "%s" where key = ?', $table), [SchemaFingerprint::KEY], useReadPdo: false);
        $value = is_object($row) ? (get_object_vars($row)['value'] ?? null) : null;
        $stored = is_string($value) ? json_decode($value, true) : null;
        $hash = is_array($stored) && is_string($stored['hash'] ?? null) ? $stored['hash'] : null;

        if ($hash === null) {
            throw new InvalidConfiguration('commerz.table_prefix', 'the install fingerprint is missing; the schema was not created by commerz:install');
        }

        if ($hash !== SchemaFingerprint::document($config->tablePrefix)['hash']) {
            throw new InvalidConfiguration('commerz.table_prefix', 'the install fingerprint differs from this package version; run `php artisan migrate`');
        }

        return 'fingerprint '.substr($hash, 0, 12);
    }

    private function plugins(Container $container): string
    {
        $plugins = $container->make(PluginInspector::class)->describe();
        $contributions = 0;

        foreach ($plugins as $plugin) {
            $contributions += count($plugin->contributions);
        }

        return count($plugins).' plugins, '.$contributions.' contributions';
    }

    /**
     * @param  callable(): string  $probe
     */
    private function check(string $label, callable $probe): ?string
    {
        try {
            $detail = $probe();
        } catch (CommerzException $e) {
            return $this->reportFailure($label, $e->getMessage());
        } catch (Throwable $e) {
            return $this->reportFailure($label, $e::class.': '.$e->getMessage());
        }

        $this->components->twoColumnDetail($label, "<fg=green>OK</> {$detail}");

        return $detail;
    }

    private function reportFailure(string $label, string $message): null
    {
        $this->failed = true;
        $this->components->twoColumnDetail($label, "<fg=red>FAIL</> {$message}");

        return null;
    }
}
