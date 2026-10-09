<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionDescription;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginDescription;
use Commerz\Plugins\PluginRegistrar;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Turns plugin classes into one FrozenContributions:
 *
 *   1. create each plugin with `new $class()`, read its manifest and run `register()` once;
 *   2. check ids, dependencies (a required plugin must exist, an optional one only orders), and cycles;
 *   3. order topologically (dependencies first, ties by id), so the registration order never matters;
 *   4. merge kinds, then contributions: the kind must be declared, owned by the plugin or something it requires,
 *      the contribution must implement the kind's contract, and kind + key must be unique.
 *
 * Every plugin implicitly depends on `commerz.core` when it is present. The first problem found fails the
 * compilation with an InvalidConfiguration naming the plugin and where it was registered.
 */
final readonly class PluginGraphCompiler
{
    public const string CORE_ID = 'commerz.core';

    private const string KEY_PATTERN = '/\A[a-z][a-z0-9]*([._-][a-z0-9]+)*\z/';

    public function __construct(private Container $container) {}

    /**
     * @param  list<PluginEntry>  $entries
     *
     * @throws InvalidConfiguration
     */
    public function compile(array $entries): FrozenContributions
    {
        $nodes = $this->nodes($entries);
        $dependencies = $this->dependencies($nodes);
        $order = $this->order($nodes, $dependencies);
        $ancestors = $this->ancestors($order, $this->dependencies($nodes, includeOptional: false));

        $kinds = [];
        $declaredBy = [];

        foreach ($order as $id) {
            foreach ($nodes[$id]->declarations->kinds() as $kind) {
                if (isset($kinds[$kind->name])) {
                    throw $this->fail($nodes[$id], "kind '{$kind->name}' is already declared by '{$declaredBy[$kind->name]}'");
                }

                $kinds[$kind->name] = $kind;
                $declaredBy[$kind->name] = $id;
            }
        }

        $items = [];
        $owners = [];
        $descriptions = [];

        foreach ($order as $id) {
            $node = $nodes[$id];
            $contributions = [];

            foreach ($node->declarations->contributions() as $declared) {
                $kind = $kinds[$declared['kind']] ?? throw $this->fail($node, "contributes to unknown kind '{$declared['kind']}'");

                if ($kind->ownerPluginId !== $id && ! isset($ancestors[$id][$kind->ownerPluginId])) {
                    throw $this->fail($node, "kind '{$kind->name}' belongs to '{$kind->ownerPluginId}', which this plugin does not require");
                }

                $contribution = $this->instance($node, $kind, $declared['contribution']);
                $key = $this->key($node, $kind, $contribution);

                if (isset($items[$kind->name][$key])) {
                    throw $this->fail($node, "{$kind->name}/{$key} is already contributed by '{$owners[$kind->name][$key]}'");
                }

                $items[$kind->name][$key] = $contribution;
                $owners[$kind->name][$key] = $id;
                $contributions[] = new ContributionDescription($kind->name, $key, $contribution::class);
            }

            $descriptions[] = new PluginDescription(
                $id,
                $node->manifest->version,
                $node->entry->source,
                $node->plugin::class,
                $node->manifest->requires,
                $node->manifest->optional,
                $node->declarations->kinds(),
                $contributions,
            );
        }

        return new FrozenContributions($kinds, $items, $descriptions);
    }

    /**
     * @param  list<PluginEntry>  $entries
     * @return array<string, PluginNode> by plugin id
     */
    private function nodes(array $entries): array
    {
        $nodes = [];

        foreach ($entries as $entry) {
            if (! class_exists($entry->class) || ! is_subclass_of($entry->class, CommerzPlugin::class)) {
                throw new InvalidConfiguration($entry->origin, "{$entry->class} must be a class implementing ".CommerzPlugin::class);
            }

            try {
                $plugin = new ($entry->class)();
                $manifest = $plugin->manifest();
                $declarations = new PluginDeclarations;
                $plugin->register(new PluginRegistrar($declarations, $manifest->id));
                $declarations->seal();
            } catch (InvalidConfiguration $e) {
                throw new InvalidConfiguration($entry->origin, "{$entry->class}: {$e->path}: {$e->reason}");
            }

            if (isset($nodes[$manifest->id])) {
                throw new InvalidConfiguration($entry->origin, "plugin id '{$manifest->id}' is already registered by {$nodes[$manifest->id]->entry->origin}");
            }

            $nodes[$manifest->id] = new PluginNode($entry, $plugin, $manifest, $declarations);
        }

        return $nodes;
    }

    /**
     * @param  array<string, PluginNode>  $nodes
     * @return array<string, list<string>> plugin id => ids it loads after
     */
    private function dependencies(array $nodes, bool $includeOptional = true): array
    {
        $dependencies = [];

        foreach ($nodes as $id => $node) {
            foreach ($node->manifest->requires as $required) {
                if (! isset($nodes[$required])) {
                    throw $this->fail($node, "requires '{$required}', which is not registered");
                }
            }

            $after = $node->manifest->requires;

            foreach ($includeOptional ? $node->manifest->optional : [] as $optional) {
                if (isset($nodes[$optional])) {
                    $after[] = $optional;
                }
            }

            if ($id !== self::CORE_ID && isset($nodes[self::CORE_ID])) {
                $after[] = self::CORE_ID;
            }

            $dependencies[$id] = array_values(array_unique($after));
        }

        return $dependencies;
    }

    /**
     * @param  array<string, PluginNode>  $nodes
     * @param  array<string, list<string>>  $dependencies
     * @return list<string> ids, dependencies first, ties by id
     */
    private function order(array $nodes, array $dependencies): array
    {
        $remaining = $dependencies;
        $order = [];

        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, static fn (array $after): bool => array_diff($after, $order) === []));

            if ($ready === []) {
                $cycle = array_keys($remaining);
                sort($cycle, SORT_STRING);

                throw $this->fail($nodes[$cycle[0]], 'dependency cycle among '.implode(', ', $cycle));
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
     * @return array<string, array<string, true>> plugin id => every plugin it transitively loads after
     */
    private function ancestors(array $order, array $dependencies): array
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

    /** @param  object|class-string  $declared */
    private function instance(PluginNode $node, ContributionKind $kind, object|string $declared): object
    {
        if (is_object($declared)) {
            $contribution = $declared;
        } else {
            if (! class_exists($declared)) {
                throw $this->fail($node, "{$kind->name} contribution {$declared} is not a class");
            }

            try {
                $made = $this->container->make($declared);
            } catch (Throwable $e) {
                throw $this->fail($node, "cannot create {$kind->name} contribution {$declared}: {$e->getMessage()}");
            }

            $contribution = is_object($made) ? $made : throw $this->fail($node, "{$kind->name} contribution {$declared} did not resolve to an object");
        }

        if (! $contribution instanceof $kind->interface) {
            throw $this->fail($node, "{$kind->name} contribution ".$contribution::class." must implement {$kind->interface}");
        }

        return $contribution;
    }

    private function key(PluginNode $node, ContributionKind $kind, object $contribution): string
    {
        $key = method_exists($contribution, 'key') ? $contribution->key() : null;

        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw $this->fail($node, "{$kind->name} contribution ".$contribution::class.' must have a key() returning a lowercase dotted key');
        }

        return $key;
    }

    private function fail(PluginNode $node, string $reason): InvalidConfiguration
    {
        return new InvalidConfiguration($node->entry->origin, "plugin '{$node->manifest->id}' ({$node->entry->class}): {$reason}");
    }
}
