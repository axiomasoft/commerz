<?php

declare(strict_types=1);

namespace Commerz\Internal\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Publish the Commerz config and run the migrations.')]
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
            $this->components->info('Config published. Run `php artisan migrate`, then `php artisan commerz:doctor`.');

            return self::SUCCESS;
        }

        $migrated = $this->call('migrate', ['--force' => $this->option('force') === true]);

        if ($migrated !== self::SUCCESS) {
            return $migrated;
        }

        $this->components->info('Commerz installed. Run `php artisan commerz:doctor` to verify.');

        return self::SUCCESS;
    }
}
