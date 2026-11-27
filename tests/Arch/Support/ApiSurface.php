<?php

declare(strict_types=1);

namespace Commerz\Arch;

use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Packman\Attributes\Api;
use Packman\Attributes\Spi;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The classified PHP surface of every package, read from `src/` by the {@see SourceScanner} of the architecture gates:
 * the public types (outside `Internal`, marked `#[Api]` or `#[Spi]`) and the platform facts a manifest records
 * (providers, config, commands, plugin, table nouns, extension kinds).
 */
final class ApiSurface
{
    /** @var array<string, list<class-string>>|null package directory => class-likes of `src/`, sorted */
    private ?array $declared = null;

    /** @var array<string, array<string, array{return: bool, parameters: array<string, bool>}>> file => Class::method => where it declares `self` */
    private array $selfTypes = [];

    /** @var array<string, array<string, true>> class => properties declared with `self` */
    private array $selfProperties = [];

    public function __construct(private readonly SourceScanner $scanner) {}

    public static function fromRoot(string $root): self
    {
        /** @var array<string, array{kind: string, nouns: list<string>, extra?: list<string>}> $map */
        $map = require $root.'/tests/Arch/ownership.php';

        return new self(new SourceScanner($root, $map));
    }

    /** @return list<SourcePackage> the packages with code, by directory */
    public function packages(): array
    {
        return array_values(array_filter($this->scanner->packages(), static fn (SourcePackage $package): bool => is_dir($package->path.'/src')));
    }

    /** @return list<class-string> the public types of a package, sorted */
    public function types(SourcePackage $package): array
    {
        return array_values(array_filter($this->declared()[$package->directory] ?? [], static fn (string $type): bool => ! SourceScanner::isInternal($type)));
    }

    /**
     * The layer of a public type from its marker. A gate already requires one on every public type.
     *
     * @template T of object
     *
     * @param  ReflectionClass<T>  $type
     * @return array{layer: 'api'|'spi', since: string, spiKind?: string}
     */
    public static function classify(ReflectionClass $type): array
    {
        foreach ($type->getAttributes(Spi::class) as $attribute) {
            $spi = $attribute->newInstance();

            return ['layer' => 'spi', 'since' => $spi->since, 'spiKind' => $spi->kind->value];
        }

        foreach ($type->getAttributes(Api::class) as $attribute) {
            return ['layer' => 'api', 'since' => $attribute->newInstance()->since];
        }

        throw new RuntimeException("{$type->getName()} has neither #[Api] nor #[Spi].");
    }

    /**
     * What a package plugs into the host: its providers (Composer `extra.laravel.providers`), config key, Artisan
     * commands, plugin id, table nouns and the extension kinds its SPI contracts declare (`KIND` constants).
     *
     * @return array<string, mixed>
     */
    public function platform(SourcePackage $package): array
    {
        $extra = $package->composer['extra'] ?? [];
        $providers = is_array($extra) && is_array($extra['laravel'] ?? null) ? ($extra['laravel']['providers'] ?? []) : [];
        $configFiles = glob($package->path.'/config/*.php');
        if ($configFiles === false) {
            $configFiles = [];
        }
        $configs = array_map(static fn (string $file): string => basename($file, '.php'), $configFiles);
        $commands = [];
        $plugins = [];
        $kinds = [];

        foreach ($this->declared()[$package->directory] ?? [] as $type) {
            $reflection = new ReflectionClass($type);

            if ($reflection->isSubclassOf(Command::class) && ! $reflection->isAbstract()) {
                $commands[] = self::commandName($reflection);
            }

            if ($reflection->implementsInterface(ExtensionPlugin::class) && $reflection->hasConstant('ID')) {
                $plugins[] = self::stringConstant($reflection->getConstant('ID'), "{$type}::ID");
            }

            $hasSpi = $reflection->getAttributes(Spi::class) !== [];

            if (! SourceScanner::isInternal($type) && $reflection->isInterface() && $hasSpi && $reflection->hasConstant('KIND')) {
                $kinds[] = self::stringConstant($reflection->getConstant('KIND'), "{$type}::KIND");
            }
        }

        sort($configs);
        sort($commands);
        sort($plugins);
        sort($kinds);

        $providerNames = [];
        if (is_array($providers)) {
            foreach ($providers as $provider) {
                if (! is_string($provider)) {
                    throw new RuntimeException('A Laravel provider entry must be a class name.');
                }
                $providerNames[] = $provider;
            }
        }

        return array_filter([
            'providers' => $providerNames,
            'config' => count($configs) === 1 ? $configs[0] : $configs,
            'commands' => $commands,
            'plugin' => count($plugins) === 1 ? $plugins[0] : $plugins,
            'tables' => $package->nouns,
            'kinds' => array_values(array_unique($kinds)),
        ], static fn (mixed $value): bool => $value !== [] && $value !== '');
    }

    /**
     * Where a method declares `self` in its source: its return type, and its parameters by name. PHP 8.5 reflection
     * resolves `self` to the class name, so the manifest reads the declaration to stay the same on every supported PHP.
     *
     * @return array{return: bool, parameters: array<string, bool>}
     */
    public function declaredSelf(ReflectionMethod $method): array
    {
        $file = $method->getFileName();
        $class = $method->getDeclaringClass()->getName();
        $this->indexSelfTypes($file);

        $methods = $file !== false ? $this->selfTypes[$file] : [];

        return $methods[$class.'::'.strtolower($method->getName())] ?? ['return' => false, 'parameters' => []];
    }

    public function propertyDeclaresSelf(ReflectionProperty $property): bool
    {
        $class = $property->getDeclaringClass();
        $this->indexSelfTypes($class->getFileName());

        return isset($this->selfProperties[$class->getName()][$property->getName()]);
    }

    private function indexSelfTypes(string|false $file): void
    {
        if ($file === false || isset($this->selfTypes[$file])) {
            return;
        }

        $this->selfTypes[$file] = [];
        $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        $parsed = (new NodeTraverser(new NameResolver))->traverse($parsed);

        foreach ((new NodeFinder)->findInstanceOf($parsed, ClassLike::class) as $type) {
            if ($type->namespacedName === null) {
                continue;
            }

            $class = $type->namespacedName->toString();
            foreach ($type->getProperties() as $property) {
                if (self::mentionsSelf($property->type)) {
                    foreach ($property->props as $item) {
                        $this->selfProperties[$class][$item->name->toString()] = true;
                    }
                }
            }

            foreach ($type->getMethods() as $declared) {
                $parameters = [];
                foreach ($declared->params as $parameter) {
                    if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                        $name = $parameter->var->name;
                        $parameters[$name] = self::mentionsSelf($parameter->type);
                        if ($parameter->isPromoted() && $parameters[$name]) {
                            $this->selfProperties[$class][$name] = true;
                        }
                    }
                }

                $this->selfTypes[$file][$class.'::'.strtolower($declared->name->toString())] = [
                    'return' => self::mentionsSelf($declared->returnType),
                    'parameters' => $parameters,
                ];
            }
        }
    }

    private static function mentionsSelf(?Node $type): bool
    {
        if ($type === null) {
            return false;
        }

        foreach ((new NodeFinder)->findInstanceOf([$type], Name::class) as $name) {
            if (strtolower($name->toString()) === 'self') {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $command
     */
    private static function commandName(ReflectionClass $command): string
    {
        foreach ($command->getAttributes(Signature::class) as $attribute) {
            $signature = $attribute->getArguments()[0] ?? $attribute->getArguments()['signature'] ?? null;

            if (is_string($signature)) {
                return self::commandToken($signature);
            }
        }

        $defaults = $command->getDefaultProperties();
        $signature = $defaults['signature'] ?? $defaults['name'] ?? null;

        if (! is_string($signature) || $signature === '') {
            throw new RuntimeException("Cannot read the name of the command {$command->getName()}.");
        }

        return self::commandToken($signature);
    }

    private static function commandToken(string $signature): string
    {
        $token = strtok($signature, " \t\n{");

        return is_string($token) ? $token : $signature;
    }

    private static function stringConstant(mixed $value, string $name): string
    {
        if (! is_string($value)) {
            throw new RuntimeException("{$name} must be a string.");
        }

        return $value;
    }

    /** @return array<string, list<class-string>> */
    private function declared(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $declared = [];

        foreach ($this->scanner->files() as $file) {
            foreach ($file->declarations() as $declaration) {
                if ($declaration->namespacedName === null) {
                    continue;
                }

                /** @var class-string $type */
                $type = $declaration->namespacedName->toString();
                $declared[$file->package->directory][] = $type;
            }
        }

        foreach ($declared as &$types) {
            sort($types);
        }

        return $this->declared = $declared;
    }
}
