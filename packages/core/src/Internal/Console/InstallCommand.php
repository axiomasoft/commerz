<?php

declare(strict_types=1);

namespace Commerz\Internal\Console;

use Commerz\Schema\CommerzSchemaSets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;

#[Description('Publish the Commerz config and run the migrations.')]
#[Internal]
#[Signature('commerz:install {--force : Overwrite the published config and migrate in production} {--no-migrate : Only publish the config}')]
final class InstallCommand extends Command
{
    public function handle(): int
    {
        $published = $this->call('vendor:publish', ['--tag' => 'commerz-config', '--force' => $this->option('force') === true]);

        if ($published !== self::SUCCESS) {
            return $published;
        }

        if ($this->option('no-migrate') === true) {
            $this->components->info('Config published. Run `php artisan migrate`, then `php artisan packman:doctor --prefix=commerz`.');

            return self::SUCCESS;
        }

        CommerzSchemaSets::assertConnectionSupported($this->laravel);

        $arguments = ['--force' => $this->option('force') === true];
        if ($this->laravel->make(Repository::class)->get('packman.schema.auto_load_migrations', true) !== true) {
            $arguments['--path'] = CommerzSchemaSets::migrationPaths($this->laravel);
            $arguments['--realpath'] = true;
        }

        $migrated = $this->call('migrate', $arguments);

        if ($migrated !== self::SUCCESS) {
            return $migrated;
        }

        $this->components->info('Commerz installed. Run `php artisan packman:doctor --prefix=commerz` to verify.');

        return self::SUCCESS;
    }
}
