<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal\Console;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Plugins\Contracts\PluginInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

#[Description('List the compiled Commerz plugins, their contribution kinds and keys.')]
#[Signature('commerz:plugins {--json : Print machine-readable JSON}')]
final class PluginsCommand extends Command
{
    public function handle(Container $container): int
    {
        try {
            $plugins = $container->make(PluginInspector::class)->describe();
        } catch (InvalidConfiguration $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode($plugins, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->kinds as $kind) {
                $rows[] = [$plugin->id, $plugin->version, $plugin->source->value, $kind->name, '(kind)', $kind->interface];
            }

            foreach ($plugin->contributions as $contribution) {
                $rows[] = [$plugin->id, $plugin->version, $plugin->source->value, $contribution->kind, $contribution->key, $contribution->class];
            }

            if ($plugin->kinds === [] && $plugin->contributions === []) {
                $rows[] = [$plugin->id, $plugin->version, $plugin->source->value, '-', '-', $plugin->class];
            }
        }

        $this->table(['Plugin', 'Version', 'Source', 'Kind', 'Key', 'Class'], $rows);

        return self::SUCCESS;
    }
}
