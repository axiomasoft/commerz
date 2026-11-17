<?php

declare(strict_types=1);

namespace Commerz\Testkit;

use Commerz\Plugins\CorePlugin;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;
use Packman\Plugins\Testing\PluginGraph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Contract tests every Commerz plugin should pass. Use it in a Testbench test case (e.g. `PackageTestCase`):
 *
 * ```php
 * final class AcmePluginTest extends PackageTestCase
 * {
 *     use PluginContractTests;
 *
 *     protected function pluginClass(): string { return AcmePlugin::class; }
 *     protected function pluginDependencies(): array { return [CommerzPromotionsPlugin::class]; }
 * }
 * ```
 *
 * The plugin is compiled the way the application does it, together with `commerz.core` and its dependencies.
 *
 * @phpstan-require-extends TestCase
 */
#[Api(since: '0.1')]
trait PluginContractTests
{
    /** @return class-string<ExtensionPlugin> */
    abstract protected function pluginClass(): string;

    /** @return list<class-string<ExtensionPlugin>> plugins the one under test requires, in any order */
    protected function pluginDependencies(): array
    {
        return [];
    }

    /**
     * The contribution keys the plugin must provide, by kind; an empty array skips the check. Pin it so a renamed
     * key, which silently orphans stored rules, fails a test.
     *
     * @return array<string, list<string>>
     */
    protected function expectedContributions(): array
    {
        return [];
    }

    #[Test]
    public function contributionKeysAreStable(): void
    {
        $first = $this->contributionKeys($this->compile(true));
        $second = $this->contributionKeys($this->compile(true));

        self::assertSame($first, $second, 'Contribution keys must not change between compilations.');

        foreach ($first as $kind => $keys) {
            self::assertSame(array_values(array_unique($keys)), $keys, "Kind {$kind} lists a key twice.");
        }

        $expected = $this->expectedContributions();

        if ($expected !== []) {
            ksort($expected);
            self::assertSame($expected, $first, 'The plugin contributes different keys than expected.');
        }
    }

    #[Test]
    public function registeringThePluginTwiceFails(): void
    {
        $plugins = [...$this->plugins(true), $this->pluginClass()];

        try {
            $this->compilePlugins($plugins);
        } catch (InvalidConfiguration $e) {
            self::assertStringContainsString('already registered', $e->getMessage());

            return;
        }

        self::fail('A second registration of the same plugin must fail the compilation.');
    }

    #[Test]
    public function aPluginThatIsNotRegisteredLeavesNoContributions(): void
    {
        $with = $this->compile(true);
        $without = $this->compile(false);

        foreach ($this->contributionKeys($with) as $kind => $keys) {
            foreach ($keys as $key) {
                if ($this->ownedBy($with, $kind, $key)) {
                    self::assertFalse($without->has($kind, $key), "{$kind}/{$key} exists without the plugin being registered.");
                }
            }
        }
    }

    #[Test]
    public function anUnknownKeyIsUnsupported(): void
    {
        $registry = $this->compile(true);

        foreach ($this->contributionKeys($registry) as $kind => $keys) {
            $contribution = $registry->get($kind, $keys[0], $this->interfaceOf($registry, $kind));
            self::assertInstanceOf($this->interfaceOf($registry, $kind), $contribution);

            try {
                $registry->get($kind, 'contract.test-missing-key', $contribution::class);
            } catch (UnsupportedContribution $e) {
                self::assertSame($kind, $e->kind);

                continue;
            }

            self::fail("Kind {$kind} returned something for a key nobody contributed.");
        }

        $this->expectException(UnsupportedContribution::class);
        $registry->get('contract_test_missing_kind', 'x', stdClass::class);
    }

    private function compile(bool $withPlugin): Contributions&PluginInspector
    {
        return $this->compilePlugins($this->plugins($withPlugin));
    }

    /** @param  list<class-string<ExtensionPlugin>>  $plugins */
    private function compilePlugins(array $plugins): Contributions&PluginInspector
    {
        return PluginGraph::compile(
            container: app(),
            plugins: $plugins,
            corePluginId: CorePlugin::ID,
            builtIn: [CorePlugin::class],
        );
    }

    /** @return list<class-string<ExtensionPlugin>> */
    private function plugins(bool $withPlugin): array
    {
        $classes = $this->pluginDependencies();

        if ($withPlugin) {
            $classes[] = $this->pluginClass();
        }

        return $classes;
    }

    /**
     * Keys contributed by the plugin under test, by kind.
     *
     * @return array<string, list<string>>
     */
    private function contributionKeys(Contributions $registry): array
    {
        $keys = [];

        foreach ($this->describe($registry) as $plugin) {
            if ($plugin->class !== $this->pluginClass()) {
                continue;
            }

            foreach ($plugin->contributions as $contribution) {
                $keys[$contribution->kind][] = $contribution->key;
            }
        }

        ksort($keys);

        return $keys;
    }

    private function ownedBy(Contributions $registry, string $kind, string $key): bool
    {
        return in_array($key, $this->contributionKeys($registry)[$kind] ?? [], true);
    }

    /** @return class-string */
    private function interfaceOf(Contributions $registry, string $kind): string
    {
        foreach ($this->describe($registry) as $plugin) {
            foreach ($plugin->kinds as $declared) {
                if ($declared->name === $kind) {
                    return $declared->interface;
                }
            }
        }

        self::fail("No plugin declares kind {$kind}.");
    }

    /** @return list<PluginDescription> */
    private function describe(Contributions $registry): array
    {
        self::assertInstanceOf(PluginInspector::class, $registry);

        return $registry->describe();
    }
}
