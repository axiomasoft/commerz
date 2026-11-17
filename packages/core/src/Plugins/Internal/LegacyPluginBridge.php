<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\CorePlugin;
use Commerz\Plugins\PluginRegistrar as LegacyRegistrar;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration as PackmanInvalidConfiguration;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Extensions\ExtensionInspector;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Contributions\ContributionDescription;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Plugin\PluginSource;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;
use Packman\Plugins\Testing\PluginGraph;
use Throwable;

/**
 * Slice-A transition registry/compiler bridging legacy CommerzPlugin classes alongside ExtensionPlugin classes.
 *
 * @deprecated Remove in Slice B once all feature subpackages implement ExtensionPlugin directly.
 */
#[Internal]
final class LegacyPluginBridge
{
    /** @var list<class-string> */
    private array $builtIn = [];

    private bool $sealed = false;

    /** @param class-string $plugin */
    public function builtIn(string $plugin): void
    {
        if ($this->sealed) {
            throw new LogicException('The plugin graph is already compiled; register built-in plugins before resolving Contributions.');
        }

        if (! in_array($plugin, $this->builtIn, true)) {
            $this->builtIn[] = $plugin;
        }
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    /** @return list<class-string<ExtensionPlugin>> */
    public function builtInExtensions(): array
    {
        $extensions = [];

        foreach ($this->builtIn as $class) {
            if (is_subclass_of($class, ExtensionPlugin::class)) {
                $extensions[] = $class;
            }
        }

        return $extensions;
    }

    /** @return list<class-string> */
    public function builtInClasses(): array
    {
        return $this->builtIn;
    }

    public function hasLegacy(): bool
    {
        return array_any($this->builtIn, static fn (string $class): bool => ! is_subclass_of($class, ExtensionPlugin::class));
    }

    /**
     * @param  list<class-string>  $builtIn
     * @param  list<class-string>  $configured
     * @param  list<string>  $disabled
     */
    public static function compile(
        Container $container,
        array $builtIn,
        array $configured,
        array $disabled = [],
    ): Contributions&PluginInspector {
        $entries = [];

        foreach ($builtIn as $class) {
            $entries[] = ['class' => $class, 'source' => PluginSource::BuiltIn, 'origin' => 'built-in plugin'];
        }

        foreach ($configured as $index => $class) {
            $entries[] = ['class' => $class, 'source' => PluginSource::Config, 'origin' => "commerz.plugins.{$index}"];
        }

        $allLegacyOrMixed = array_any($entries, static fn (array $e): bool => ! is_subclass_of($e['class'], ExtensionPlugin::class));

        if (! $allLegacyOrMixed) {
            /** @var list<class-string<ExtensionPlugin>> $builtInExt */
            $builtInExt = $builtIn;

            return PluginGraph::compile(
                container: $container,
                plugins: $configured,
                corePluginId: CorePlugin::ID,
                builtIn: $builtInExt,
                disabled: $disabled,
            );
        }

        return self::compileMixed($container, $entries, $disabled);
    }

    /**
     * @param  list<array{class: class-string, source: PluginSource, origin: string}>  $entries
     * @param  list<string>  $disabled
     */
    private static function compileMixed(Container $container, array $entries, array $disabled): Contributions&PluginInspector
    {
        $nodes = [];

        foreach ($entries as $entry) {
            $class = $entry['class'];

            if (! class_exists($class) || (! is_subclass_of($class, ExtensionPlugin::class) && ! is_subclass_of($class, CommerzPlugin::class))) {
                throw new InvalidConfiguration($entry['origin'], "{$class} must implement ".ExtensionPlugin::class.' or '.CommerzPlugin::class);
            }

            try {
                $plugin = new $class;
                $manifest = $plugin->manifest();
            } catch (InvalidConfiguration $e) {
                throw new InvalidConfiguration($entry['origin'], "{$class}: {$e->path}: {$e->reason}");
            } catch (PackmanInvalidConfiguration $e) {
                throw new InvalidConfiguration($entry['origin'], "{$class}: ".$e->getMessage());
            }

            if (isset($nodes[$manifest->id])) {
                throw new InvalidConfiguration($entry['origin'], "plugin id '{$manifest->id}' is already registered by {$nodes[$manifest->id]['origin']}");
            }

            $nodes[$manifest->id] = [
                'plugin' => $plugin,
                'manifest' => $manifest,
                'source' => $entry['source'],
                'origin' => $entry['origin'],
                'class' => $class,
            ];
        }

        $disabledSet = array_flip($disabled);
        $activeNodes = array_filter($nodes, static fn (string $id): bool => ! isset($disabledSet[$id]), ARRAY_FILTER_USE_KEY);
        $disabledIds = array_values(array_diff(array_keys($nodes), array_keys($activeNodes)));
        sort($disabledIds, SORT_STRING);

        $dependencies = [];

        foreach ($activeNodes as $id => $node) {
            /** @var PluginManifest $manifest */
            $manifest = $node['manifest'];

            foreach ($manifest->requires as $req) {
                if (! isset($activeNodes[$req])) {
                    throw new InvalidConfiguration($node['origin'], "plugin '{$id}' ({$node['class']}): requires '{$req}', which is not active");
                }
            }

            $after = $manifest->requires;

            foreach ($manifest->optional as $opt) {
                if (isset($activeNodes[$opt])) {
                    $after[] = $opt;
                }
            }

            if ($id !== CorePlugin::ID && isset($activeNodes[CorePlugin::ID])) {
                $after[] = CorePlugin::ID;
            }

            $dependencies[$id] = array_values(array_unique($after));
        }

        $order = self::sort($activeNodes, $dependencies);
        $strictDependencies = [];

        foreach ($activeNodes as $id => $node) {
            $after = $node['manifest']->requires;

            if ($id !== CorePlugin::ID && isset($activeNodes[CorePlugin::ID])) {
                $after[] = CorePlugin::ID;
            }

            $strictDependencies[$id] = array_values(array_unique($after));
        }

        $ancestors = self::ancestors($order, $strictDependencies);

        $allKinds = [];
        $kindOwners = [];
        $items = [];
        $descriptions = [];
        $extDeclarations = [];
        $facets = [];
        /** @var list<class-string<ExtensionPlugin>> $extensionClasses */
        $extensionClasses = [];

        // Extract kinds & contributions using ExtensionPlugin::register or CommerzPlugin::register.
        foreach ($order as $id) {
            $node = $activeNodes[$id];
            $plugin = $node['plugin'];
            $facets[$id] = ExtensionInspector::inspect($plugin);

            if ($plugin instanceof ExtensionPlugin) {
                $extensionClasses[] = $plugin::class;
                $tempItems = self::captureExtension($container, $id, $extensionClasses);
                $extDeclarations[$id] = $tempItems;
            } else {
                $registrar = new LegacyRegistrar($id);

                try {
                    $plugin->register($registrar);
                } catch (InvalidConfiguration|PackmanInvalidConfiguration $e) {
                    throw new InvalidConfiguration($node['origin'], "{$node['class']}: ".$e->getMessage());
                }

                $registrar->seal();
                $extDeclarations[$id] = ['kinds' => $registrar->kinds(), 'contributions' => $registrar->contributions()];
            }

            foreach ($extDeclarations[$id]['kinds'] as $kind) {
                if (isset($allKinds[$kind->name])) {
                    throw new InvalidConfiguration($node['origin'], "kind '{$kind->name}' is already declared by '{$kindOwners[$kind->name]}'");
                }

                $allKinds[$kind->name] = $kind;
                $kindOwners[$kind->name] = $id;
            }
        }

        $keyOwners = [];

        foreach ($order as $id) {
            $node = $activeNodes[$id];
            $declaredContributions = $extDeclarations[$id]['contributions'];
            $descs = [];

            foreach ($declaredContributions as $declared) {
                $kind = $allKinds[$declared['kind']] ?? throw new InvalidConfiguration($node['origin'], "plugin '{$id}': contributes to unknown kind '{$declared['kind']}'");

                if ($kind->ownerPluginId !== $id && ! isset($ancestors[$id][$kind->ownerPluginId])) {
                    throw new InvalidConfiguration($node['origin'], "plugin '{$id}': kind '{$kind->name}' belongs to '{$kind->ownerPluginId}', which this plugin does not require");
                }

                $instance = self::resolveContribution($container, $node['origin'], $id, $kind, $declared['contribution']);
                $key = self::extractKey($node['origin'], $id, $kind, $instance);

                if (isset($items[$kind->name][$key])) {
                    throw new InvalidConfiguration($node['origin'], "{$kind->name}/{$key} is already contributed by '{$keyOwners[$kind->name][$key]}'");
                }

                $items[$kind->name][$key] = $instance;
                $keyOwners[$kind->name][$key] = $id;
                $descs[] = new ContributionDescription($kind->name, $key, $instance::class);
            }

            $descriptions[] = new PluginDescription(
                id: $id,
                version: $node['manifest']->version,
                source: $node['source'],
                class: $node['class'],
                requires: $node['manifest']->requires,
                optional: $node['manifest']->optional,
                kinds: $extDeclarations[$id]['kinds'],
                contributions: $descs,
            );
        }

        return new readonly class($allKinds, $items, $descriptions, $disabledIds, $facets) implements Contributions, PluginInspector
        {
            /**
             * @param  array<string, ContributionKind>  $kinds
             * @param  array<string, array<string, object>>  $items
             * @param  list<PluginDescription>  $descriptions
             * @param  list<string>  $disabled
             * @param  array<string, ExtensionFacetProfile>  $facets
             */
            public function __construct(
                private array $kinds,
                private array $items,
                private array $descriptions,
                private array $disabled,
                private array $facets,
            ) {}

            public function get(string $kind, string $key, string $interface): object
            {
                if (! isset($this->kinds[$kind])) {
                    throw new UnsupportedContribution($kind, $key, 'unknown contribution kind');
                }

                $entry = $this->items[$kind][$key] ?? null;

                if ($entry === null) {
                    throw new UnsupportedContribution($kind, $key, 'no plugin contributed this key');
                }

                if (! $entry instanceof $interface) {
                    throw new UnsupportedContribution($kind, $key, 'contribution does not implement '.$interface);
                }

                return $entry;
            }

            public function has(string $kind, string $key): bool
            {
                return isset($this->items[$kind][$key]);
            }

            public function keys(string $kind): array
            {
                return array_map(strval(...), array_keys($this->items[$kind] ?? []));
            }

            public function describe(): array
            {
                return $this->descriptions;
            }

            public function slots(): array
            {
                return [];
            }

            public function slotContributions(): array
            {
                return [];
            }

            public function disabledIds(): array
            {
                return $this->disabled;
            }

            public function facetsOf(string $pluginId): ?ExtensionFacetProfile
            {
                return $this->facets[$pluginId] ?? null;
            }
        };
    }

    /**
     * @param  list<class-string<ExtensionPlugin>>  $extensionClasses
     * @return array{kinds: list<ContributionKind>, contributions: list<array{kind: string, contribution: object|class-string}>}
     */
    private static function captureExtension(Container $container, string $pluginId, array $extensionClasses): array
    {
        $temp = PluginGraph::compile($container, [], CorePlugin::ID, $extensionClasses);
        $kinds = [];
        $contributions = [];

        foreach ($temp->describe() as $desc) {
            if ($desc->id !== $pluginId) {
                continue;
            }

            $kinds = $desc->kinds;

            foreach ($desc->contributions as $c) {
                $contributions[] = [
                    'kind' => $c->kind,
                    'contribution' => $temp->get($c->kind, $c->key, $c->class),
                ];
            }
        }

        return ['kinds' => $kinds, 'contributions' => $contributions];
    }

    /**
     * @param  array<string, array{origin: string}>  $nodes
     * @param  array<string, list<string>>  $dependencies
     * @return list<string>
     */
    private static function sort(array $nodes, array $dependencies): array
    {
        $remaining = $dependencies;
        $order = [];

        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, static fn (array $after): bool => array_diff($after, $order) === []));

            if ($ready === []) {
                $cycle = array_keys($remaining);
                sort($cycle, SORT_STRING);

                throw new InvalidConfiguration($nodes[$cycle[0]]['origin'], 'dependency cycle among '.implode(', ', $cycle));
            }

            sort($ready, SORT_STRING);
            $next = $ready[0];
            $order[] = $next;
            unset($remaining[$next]);
        }

        return $order;
    }

    /**
     * @param  list<string>  $order
     * @param  array<string, list<string>>  $dependencies
     * @return array<string, array<string, true>>
     */
    private static function ancestors(array $order, array $dependencies): array
    {
        $ancestors = [];

        foreach ($order as $id) {
            $ancestors[$id] = [];

            foreach ($dependencies[$id] as $dependency) {
                $ancestors[$id][$dependency] = true;
                $ancestors[$id] += $ancestors[$dependency];
            }
        }

        return $ancestors;
    }

    /** @param object|class-string $contribution */
    private static function resolveContribution(Container $container, string $origin, string $pluginId, ContributionKind $kind, object|string $contribution): object
    {
        if (is_string($contribution)) {
            try {
                $instance = $container->make($contribution);
            } catch (Throwable $e) {
                throw new InvalidConfiguration($origin, "plugin '{$pluginId}': cannot resolve '{$contribution}': ".$e->getMessage());
            }
        } else {
            $instance = $contribution;
        }

        if (! is_object($instance) || ! $instance instanceof ($kind->interface)) {
            throw new InvalidConfiguration($origin, "plugin '{$pluginId}': contribution must implement {$kind->interface}");
        }

        return $instance;
    }

    private static function extractKey(string $origin, string $pluginId, ContributionKind $kind, object $contribution): string
    {
        if (! method_exists($contribution, 'key')) {
            throw new LogicException("Contract {$kind->interface} must declare key(): string.");
        }

        $key = $contribution->key();

        if (! is_string($key) || $key === '') {
            throw new InvalidConfiguration($origin, "plugin '{$pluginId}': contribution key for '{$kind->name}' must be a non-empty string");
        }

        return $key;
    }
}
