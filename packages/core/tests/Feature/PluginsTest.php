<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidConfiguration;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Internal\CorePlugin;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\Internal\PluginEntry;
use Commerz\Plugins\Internal\PluginGraphCompiler;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Plugins\PluginSource;

interface Greeter
{
    public function key(): string;

    public function greet(): string;
}

final class HelloGreeter implements Greeter
{
    public function key(): string
    {
        return 'test.hello';
    }

    public function greet(): string
    {
        return 'hello';
    }
}

final class HolaGreeter implements Greeter
{
    public function key(): string
    {
        return 'test.hola';
    }

    public function greet(): string
    {
        return 'hola';
    }
}

final class NotAGreeter
{
    public function key(): string
    {
        return 'test.impostor';
    }
}

final class BadKeyGreeter implements Greeter
{
    public function key(): string
    {
        return 'Bad Key';
    }

    public function greet(): string
    {
        return '';
    }
}

abstract class PlgBase implements CommerzPlugin
{
    /** @return array{0: string, 1?: list<string>, 2?: list<string>} */
    abstract protected function spec(): array;

    public function manifest(): PluginManifest
    {
        [$id, $requires, $optional] = $this->spec() + [1 => [], 2 => []];

        return new PluginManifest($id, '1.0.0', $requires, $optional);
    }

    public function register(PluginRegistrar $registrar): void {}
}

final class PlgOwner extends PlgBase
{
    protected function spec(): array
    {
        return ['test.owner'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind('greeter', Greeter::class, 'test.owner'));
        $registrar->contribute('greeter', HelloGreeter::class);
    }
}

final class PlgExtension extends PlgBase
{
    protected function spec(): array
    {
        return ['test.extension', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', new HolaGreeter);
    }
}

final class PlgOptional extends PlgBase
{
    protected function spec(): array
    {
        return ['test.optional', [], ['test.absent', 'test.owner']];
    }
}

final class PlgCycleA extends PlgBase
{
    protected function spec(): array
    {
        return ['test.cycle-a', ['test.cycle-b']];
    }
}

final class PlgCycleB extends PlgBase
{
    protected function spec(): array
    {
        return ['test.cycle-b', ['test.cycle-a']];
    }
}

final class PlgMissingRequirement extends PlgBase
{
    protected function spec(): array
    {
        return ['test.needy', ['test.absent']];
    }
}

final class PlgDuplicateKey extends PlgBase
{
    protected function spec(): array
    {
        return ['test.dup-key', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', HelloGreeter::class);
    }
}

final class PlgDuplicateKind extends PlgBase
{
    protected function spec(): array
    {
        return ['test.dup-kind', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind('greeter', Greeter::class, 'test.dup-kind'));
    }
}

final class PlgUnknownKind extends PlgBase
{
    protected function spec(): array
    {
        return ['test.unknown-kind'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('nonexistent', HelloGreeter::class);
    }
}

final class PlgWrongInterface extends PlgBase
{
    protected function spec(): array
    {
        return ['test.wrong-interface', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', NotAGreeter::class);
    }
}

final class PlgBadKey extends PlgBase
{
    protected function spec(): array
    {
        return ['test.bad-key', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', BadKeyGreeter::class);
    }
}

final class PlgNotRequired extends PlgBase
{
    protected function spec(): array
    {
        return ['test.not-required'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', HelloGreeter::class);
    }
}

final class PlgForeignOwner extends PlgBase
{
    protected function spec(): array
    {
        return ['test.foreign'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind('stolen', Greeter::class, 'test.owner'));
    }
}

final class PlgBadManifest implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('NoVendor', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void {}
}

final class PlgLate extends PlgBase
{
    public static ?PluginRegistrar $registrar = null;

    protected function spec(): array
    {
        return ['test.late'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        self::$registrar = $registrar;
    }
}

/** @param  class-string  ...$plugins  host plugins, in config order; the core plugin is always built in */
function compilePlugins(string ...$plugins): Contributions&PluginInspector
{
    $entries = [PluginEntry::builtIn(CorePlugin::class)];

    foreach (array_values($plugins) as $index => $plugin) {
        $entries[] = PluginEntry::config($plugin, $index);
    }

    return new PluginGraphCompiler(app())->compile($entries);
}

function compileError(string ...$plugins): string
{
    try {
        compilePlugins(...$plugins);
    } catch (InvalidConfiguration $e) {
        return $e->path.' | '.$e->reason;
    }

    return 'compiled';
}

it('validates manifests', function (): void {
    expect(fn () => new PluginManifest('acme', '1.0.0'))->toThrow(InvalidConfiguration::class, 'not a plugin id')
        ->and(fn () => new PluginManifest('Acme.Shop', '1.0.0'))->toThrow(InvalidConfiguration::class)
        ->and(fn () => new PluginManifest('acme.shop', '1.0'))->toThrow(InvalidConfiguration::class, 'semver')
        ->and(fn () => new PluginManifest('acme.shop', '1.0.0', ['acme.shop']))->toThrow(InvalidConfiguration::class, 'lists itself')
        ->and(fn () => new PluginManifest('acme.shop', '1.0.0', ['a.b', 'a.b']))->toThrow(InvalidConfiguration::class, 'twice')
        ->and(fn () => new PluginManifest('acme.shop', '1.0.0', ['a.b'], ['a.b']))->toThrow(InvalidConfiguration::class, 'both')
        ->and(new PluginManifest('acme.shop-v2', '1.2.3-rc.1+build.5', ['a.b'], ['c.d'])->id)->toBe('acme.shop-v2');
});

it('validates contribution kinds', function (): void {
    expect(fn () => new ContributionKind('Greeter', Greeter::class, 'a.b'))->toThrow(InvalidConfiguration::class, 'snake_case')
        ->and(fn () => new ContributionKind('greeter', 'Nope\\Missing', 'a.b'))->toThrow(InvalidConfiguration::class, 'does not exist')
        ->and(fn () => new ContributionKind('greeter', Greeter::class, 'owner'))->toThrow(InvalidConfiguration::class, 'plugin id');
});

it('compiles the core plugin alone', function (): void {
    $registry = compilePlugins();

    expect($registry->keys(OutboxConsumer::KIND))->toBe([])
        ->and(array_map(fn ($p): string => $p->id, $registry->describe()))->toBe(['commerz.core'])
        ->and($registry->describe()[0]->kinds[0]->name)->toBe('outbox_consumer');
});

it('merges contributions from required plugins in load order', function (): void {
    $registry = compilePlugins(PlgExtension::class, PlgOwner::class); // registered before its dependency

    expect(array_map(fn ($p): string => $p->id, $registry->describe()))->toBe(['commerz.core', 'test.owner', 'test.extension'])
        ->and($registry->keys('greeter'))->toBe(['test.hello', 'test.hola'])
        ->and($registry->has('greeter', 'test.hola'))->toBeTrue()
        ->and($registry->get('greeter', 'test.hola', Greeter::class)->greet())->toBe('hola')
        ->and($registry->get('greeter', 'test.hello', Greeter::class))->toBe($registry->get('greeter', 'test.hello', Greeter::class));

    $description = $registry->describe()[2];
    expect([$description->version, $description->source, $description->requires])->toBe(['1.0.0', PluginSource::Config, ['test.owner']])
        ->and($description->contributions[0]->class)->toBe(HolaGreeter::class);
});

it('orders independent plugins by id whatever the registration order', function (): void {
    $forward = array_map(fn ($p): string => $p->id, compilePlugins(PlgOwner::class, PlgOptional::class, PlgExtension::class)->describe());
    $reverse = array_map(fn ($p): string => $p->id, compilePlugins(PlgExtension::class, PlgOptional::class, PlgOwner::class)->describe());

    expect($forward)->toBe($reverse)->toBe(['commerz.core', 'test.owner', 'test.extension', 'test.optional']);
});

it('ignores an optional plugin that is absent and loads a present one first', function (): void {
    expect(compileError(PlgOptional::class))->toBe('compiled')
        ->and(array_map(fn ($p): string => $p->id, compilePlugins(PlgOptional::class, PlgOwner::class)->describe()))->toBe(['commerz.core', 'test.owner', 'test.optional']);
});

it('reports graph problems with the plugin and where it was registered', function (string $problem, array $plugins, string $path, string $reason): void {
    expect(compileError(...$plugins))->toBe("{$path} | {$reason}");
})->with([
    'cycle' => ['cycle', [PlgCycleA::class, PlgCycleB::class], 'commerz.plugins.0', "plugin 'test.cycle-a' (PlgCycleA): dependency cycle among test.cycle-a, test.cycle-b"],
    'missing requirement' => ['missing', [PlgOptional::class, PlgMissingRequirement::class], 'commerz.plugins.1', "plugin 'test.needy' (PlgMissingRequirement): requires 'test.absent', which is not registered"],
    'duplicate id' => ['dup id', [PlgOwner::class, PlgExtension::class, PlgOwner::class], 'commerz.plugins.2', "plugin id 'test.owner' is already registered by commerz.plugins.0"],
    'duplicate key' => ['dup key', [PlgOwner::class, PlgDuplicateKey::class], 'commerz.plugins.1', "plugin 'test.dup-key' (PlgDuplicateKey): greeter/test.hello is already contributed by 'test.owner'"],
    'duplicate kind' => ['dup kind', [PlgOwner::class, PlgDuplicateKind::class], 'commerz.plugins.1', "plugin 'test.dup-kind' (PlgDuplicateKind): kind 'greeter' is already declared by 'test.owner'"],
    'unknown kind' => ['unknown kind', [PlgUnknownKind::class], 'commerz.plugins.0', "plugin 'test.unknown-kind' (PlgUnknownKind): contributes to unknown kind 'nonexistent'"],
    'wrong interface' => ['interface', [PlgOwner::class, PlgWrongInterface::class], 'commerz.plugins.1', "plugin 'test.wrong-interface' (PlgWrongInterface): greeter contribution NotAGreeter must implement Greeter"],
    'bad key' => ['key', [PlgOwner::class, PlgBadKey::class], 'commerz.plugins.1', "plugin 'test.bad-key' (PlgBadKey): greeter contribution BadKeyGreeter must have a key() returning a lowercase dotted key"],
    'kind of a plugin it does not require' => ['not required', [PlgOwner::class, PlgNotRequired::class], 'commerz.plugins.1', "plugin 'test.not-required' (PlgNotRequired): kind 'greeter' belongs to 'test.owner', which this plugin does not require"],
    'kind declared for another owner' => ['foreign', [PlgForeignOwner::class], 'commerz.plugins.0', "PlgForeignOwner: plugin.kind.owner: plugin 'test.foreign' declared kind 'stolen' for 'test.owner'"],
    'bad manifest' => ['manifest', [PlgBadManifest::class], 'commerz.plugins.0', "PlgBadManifest: plugin.manifest.id: 'NoVendor' is not a plugin id (vendor.name, lowercase letters, digits, dots and dashes)"],
    'not a plugin' => ['class', [HelloGreeter::class], 'commerz.plugins.0', 'HelloGreeter must be a class implementing '.CommerzPlugin::class],
]);

it('fails closed on a missing or mistyped contribution', function (): void {
    $registry = compilePlugins(PlgOwner::class);

    expect(fn () => $registry->get('greeter', 'test.nope', Greeter::class))->toThrow(UnsupportedContribution::class, 'no registered plugin contributes this key')
        ->and(fn () => $registry->get('promotion_effect', 'x', Greeter::class))->toThrow(UnsupportedContribution::class, 'no installed plugin declares this contribution kind')
        ->and(fn () => $registry->get('greeter', 'test.hello', Countable::class))->toThrow(UnsupportedContribution::class, 'does not implement Countable')
        ->and($registry->has('promotion_effect', 'x'))->toBeFalse()
        ->and($registry->keys('promotion_effect'))->toBe([]);
});

it('rejects registrar calls after register() returned', function (): void {
    PlgLate::$registrar = null;
    compilePlugins(PlgLate::class);

    expect(fn () => PlgLate::$registrar?->contribute('outbox_consumer', HelloGreeter::class))->toThrow(LogicException::class, 'frozen')
        ->and(fn () => PlgLate::$registrar?->declareKind(new ContributionKind('late', Greeter::class, 'test.late')))->toThrow(LogicException::class);
});

it('builds the registry lazily, once, and freezes the catalog', function (): void {
    CommerzPlugins::builtIn(PlgOwner::class);

    expect(app()->resolved(Contributions::class))->toBeFalse();

    $registry = app(Contributions::class);

    expect($registry)->toBe(app(Contributions::class))->toBe(app(PluginInspector::class))
        ->and($registry->keys('greeter'))->toBe(['test.hello'])
        ->and(array_map(fn ($p): array => [$p->id, $p->source], $registry->describe()))->toBe([['commerz.core', PluginSource::BuiltIn], ['test.owner', PluginSource::BuiltIn]])
        ->and(fn () => CommerzPlugins::builtIn(PlgExtension::class))->toThrow(LogicException::class, 'already compiled');
});

it('merges built-in and configured plugins, built-in first', function (): void {
    config(['commerz.plugins' => [PlgExtension::class]]);
    CommerzPlugins::builtIn(PlgOwner::class);

    $registry = app(Contributions::class);

    expect($registry->keys('greeter'))->toBe(['test.hello', 'test.hola'])
        ->and(array_map(fn ($p): string => $p->source->value, $registry->describe()))->toBe(['builtin', 'builtin', 'config']);
});

it('fails the registry with the configuration error, again on every use', function (): void {
    config(['commerz.plugins' => [PlgCycleA::class, PlgCycleB::class]]);

    expect(fn () => app(Contributions::class))->toThrow(InvalidConfiguration::class, 'dependency cycle')
        ->and(fn () => app(Contributions::class))->toThrow(InvalidConfiguration::class, 'dependency cycle');
});

it('keeps the plugin list config:cache friendly', function (): void {
    $config = config('commerz');
    $config['plugins'] = [PlgOwner::class];

    expect(eval('return '.var_export($config, true).';'))->toBe($config)
        ->and(CommerzConfig::fromArray($config)->plugins)->toBe([PlgOwner::class])
        ->and(fn () => CommerzConfig::fromArray(['plugins' => [new PlgOwner]]))->toThrow(InvalidConfiguration::class, 'commerz.plugins.0')
        ->and(fn () => CommerzConfig::fromArray(['plugins' => [fn () => null]]))->toThrow(InvalidConfiguration::class);
});

it('lists plugins with commerz:plugins', function (): void {
    CommerzPlugins::builtIn(PlgOwner::class);
    config(['commerz.plugins' => [PlgExtension::class]]);

    $this->artisan('commerz:plugins')
        ->assertSuccessful()
        ->expectsOutputToContain('commerz.core')
        ->expectsOutputToContain('test.hello')
        ->expectsOutputToContain('HolaGreeter');
});

it('prints the registry as json', function (): void {
    CommerzPlugins::builtIn(PlgOwner::class);

    $this->artisan('commerz:plugins', ['--json' => true])->assertSuccessful()->expectsOutputToContain('"id": "test.owner"');
});

it('fails commerz:plugins with the configuration error', function (): void {
    config(['commerz.plugins' => [PlgCycleA::class, PlgCycleB::class]]);

    $this->artisan('commerz:plugins')->assertFailed()->expectsOutputToContain('commerz.plugins.0');
});

final class PlgOptionalExtension extends PlgBase
{
    protected function spec(): array
    {
        return ['test.optional-extension', [], ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', HolaGreeter::class);
    }
}

it('does not grant contribution ownership through an optional load-order dependency', function (): void {
    expect(fn () => compilePlugins(PlgOptionalExtension::class, PlgOwner::class))
        ->toThrow(InvalidConfiguration::class, 'which this plugin does not require');
});
