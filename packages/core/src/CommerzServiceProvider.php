<?php

declare(strict_types=1);

namespace Commerz;

use Commerz\Configuration\CommerzOptions;
use Commerz\Laravel\CommerzPluginServiceProvider;
use Commerz\Laravel\Registrars\CommerzCoreRegistrar;
use Commerz\Laravel\Registrars\CommerzPortsRegistrar;
use Commerz\Laravel\Registrars\CommerzStoresRegistrar;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Contributions;

#[Api(since: '0.1')]
final class CommerzServiceProvider extends CommerzPluginServiceProvider
{
    /** Table nouns owned by the core package. */
    public const array NOUNS = ['store', 'channel', 'schema', 'operation', 'outbox', 'entity_link'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        CommerzCoreRegistrar::class,
        CommerzPortsRegistrar::class,
        CommerzStoresRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        if ($this->app->getProviders(self::class) !== []) {
            return;
        }

        parent::register();
        $app = $this->app;
        $app->beforeResolving(Contributions::class, static function () use ($app): void {
            $config = $app->make(ConfigRepository::class);
            $selection = $config->get('commerz', []);
            $options = $app->bound(CommerzOptions::class)
                ? $app->make(CommerzOptions::class)
                : CommerzOptions::fromArray(is_array($selection) ? array_intersect_key($selection, array_flip(['plugins', 'disabled_plugins'])) : [], 'commerz');

            foreach (['plugins' => $options->plugins, 'disabled_plugins' => $options->disabledPlugins] as $key => $values) {
                $configured = $config->get('packman.'.$key, []);
                if (! is_array($configured)) {
                    throw new InvalidConfiguration(['packman.'.$key.': expected an array']);
                }
                $additional = array_filter($values, static fn (string $value): bool => ! in_array($value, $configured, true));
                $config->set('packman.'.$key, array_values([...$configured, ...$additional]));
            }
        });
    }
}
