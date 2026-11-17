<?php

declare(strict_types=1);

namespace Commerz\Arch;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Source-scan architecture gates (D3/D12). Every gate reports how many subjects it inspected, so a gate that sees
 * nothing fails instead of passing vacuously.
 */
final class ArchitectureGates
{
    private const array API_ATTRIBUTES = ['Packman\Attributes\Api'];

    private const array SPI_ATTRIBUTES = ['Packman\Attributes\Spi'];

    private const array MODEL_BASES = [
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Database\Eloquent\Relations\Pivot',
        'Illuminate\Database\Eloquent\Relations\MorphPivot',
    ];

    /** Static facade calls that create, change or drop tables without going through `PackmanMigration`. */
    private const array RAW_SCHEMA_METHODS = ['create', 'table', 'drop', 'dropIfExists', 'rename'];

    /** Calls whose first string argument is a table name. */
    private const array TABLE_METHODS = ['create', 'drop', 'table', 'on', 'constrained'];

    private readonly SourceScanner $scanner;

    /** @param  array<string, array{kind: string, nouns: list<string>, extra?: list<string>}>  $map */
    public function __construct(string $root, array $map)
    {
        $this->scanner = new SourceScanner($root, $map);
    }

    /** Gate 1: feature packages require only the core among `axiomasoft/*`; the core requires none. */
    public function dependencies(): GateResult
    {
        $violations = [];
        $checked = 0;

        foreach ($this->scanner->packages() as $package) {
            if ($package->kind === 'meta') {
                continue;
            }

            $checked++;
            $path = $this->scanner->relative($package->path.'/composer.json');

            foreach ($package->requires() as $required) {
                if (str_starts_with($required, 'axiomasoft/packman')) {
                    if (! $this->allowedExternal($required, $package)) {
                        $violations[] = "{$path}: '{$package->name()}' must not require '{$required}'.";
                    }

                    continue;
                }

                if (str_starts_with($required, 'axiomasoft/')) {
                    if ($package->kind === 'core' || $required !== 'axiomasoft/commerz') {
                        $violations[] = "{$path}: '{$package->name()}' must not require '{$required}'.";
                    }

                    continue;
                }

                if ($this->allowedExternal($required, $package)) {
                    continue;
                }

                $violations[] = "{$path}: '{$package->name()}' requires '{$required}', outside php, ext-*, illuminate/*, brick/math.";
            }
        }

        return new GateResult($checked, $violations);
    }

    /** Gate 2: no `...\Internal\...` import across a package boundary, nor across core modules. */
    public function internals(): GateResult
    {
        $violations = [];
        $files = $this->scanner->files();

        foreach ($files as $file) {
            foreach ($file->referencedNames() as $name) {
                if (! SourceScanner::isInternal($name)) {
                    continue;
                }

                $owner = $this->scanner->ownerOf($name);

                if ($owner === null) {
                    continue;
                }

                if ($owner->directory !== $file->package->directory) {
                    $violations[] = "{$file->relativePath}: imports {$name} of package '{$owner->directory}'.";

                    continue;
                }

                if ($owner->kind === 'core' && $file->relativePath !== 'packages/core/src/Plugins/CorePlugin.php' && $this->module($file->namespace, $owner) !== null && $this->module($file->namespace, $owner) !== $this->module(substr($name, 0, (int) strrpos($name, '\\')), $owner)) {
                    $violations[] = "{$file->relativePath}: imports {$name} of another core module.";
                }
            }
        }

        return new GateResult(count($files), $violations);
    }

    /** Gate 3: every public (non-Internal) type carries `#[Api]` or `#[Spi]`. */
    public function markers(): GateResult
    {
        $violations = [];
        $checked = 0;

        foreach ($this->scanner->files() as $file) {
            if ($file->isInternal()) {
                continue;
            }

            foreach ($file->declarations() as $declaration) {
                $checked++;

                if (! $this->hasAttribute($declaration, ...self::API_ATTRIBUTES, ...self::SPI_ATTRIBUTES)) {
                    $violations[] = "{$file->relativePath}: {$file->namespace}\\{$declaration->name} lacks #[Api] or #[Spi].";
                }
            }
        }

        return new GateResult($checked, $violations);
    }

    /** Gate 4: Eloquent models exist only in `Internal` namespaces. */
    public function eloquent(): GateResult
    {
        $files = $this->scanner->files();
        $parents = [];

        foreach ($files as $file) {
            foreach ($file->declarations() as $declaration) {
                if ($declaration instanceof Class_ && $declaration->extends !== null && $declaration->namespacedName !== null) {
                    $parents[$declaration->namespacedName->toString()] = $declaration->extends->toString();
                }
            }
        }

        $violations = [];

        foreach ($files as $file) {
            if ($file->isInternal()) {
                continue;
            }

            foreach ($file->declarations() as $declaration) {
                if ($declaration instanceof Class_ && $declaration->namespacedName !== null && $this->isModel($declaration->namespacedName->toString(), $parents)) {
                    $violations[] = "{$file->relativePath}: {$declaration->namespacedName} is an Eloquent model outside Internal.";
                }
            }
        }

        return new GateResult(count($files), $violations);
    }

    /** Gate 5: migrations create, drop and reference only tables whose noun the package owns. */
    public function tables(): GateResult
    {
        $violations = [];
        $checked = 0;
        $finder = new NodeFinder;

        foreach ($this->scanner->packages() as $package) {
            foreach ($this->scanner->migrations($package) as $migration) {
                $checked++;
                $path = $this->scanner->relative($migration);
                $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($migration)) ?? [];

                foreach ($finder->findInstanceOf($statements, StaticCall::class) as $call) {
                    if ($this->staticCallName($call) === 'Schema' && $call->name instanceof Identifier && in_array($call->name->toString(), self::RAW_SCHEMA_METHODS, true)) {
                        $violations[] = "{$path}: Schema::{$call->name}() bypasses PackmanMigration table helpers.";
                    }
                }

                foreach ($finder->findInstanceOf($statements, MethodCall::class) as $call) {
                    $logical = $this->logicalTable($call);

                    if ($logical !== null && ! $this->owns($package, $logical)) {
                        $violations[] = "{$path}: table '{$logical}' is not covered by the nouns of '{$package->directory}' (".implode(', ', $package->nouns).').';
                    }
                }
            }
        }

        return new GateResult($checked, $violations);
    }

    /** Gate 6: no `float` in parameters, properties or return types of `Contracts/` and `Data/`. */
    public function floats(): GateResult
    {
        $violations = [];
        $checked = 0;
        $finder = new NodeFinder;

        foreach ($this->scanner->files() as $file) {
            if (! $file->inNamespaceSegment('Contracts', 'Data')) {
                continue;
            }

            $checked++;

            foreach ($finder->findInstanceOf($file->statements, ClassMethod::class) as $method) {
                foreach ($method->params as $param) {
                    if ($this->hasFloat($param->type)) {
                        $violations[] = "{$file->relativePath}: parameter \${$this->variableName($param->var)} of {$method->name}() is float.";
                    }
                }

                if ($this->hasFloat($method->returnType)) {
                    $violations[] = "{$file->relativePath}: {$method->name}() returns float.";
                }
            }

            foreach ($finder->findInstanceOf($file->statements, Property::class) as $property) {
                if ($this->hasFloat($property->type)) {
                    $violations[] = "{$file->relativePath}: property \${$property->props[0]->name} is float.";
                }
            }
        }

        return new GateResult($checked, $violations);
    }

    private function allowedExternal(string $required, SourcePackage $package): bool
    {
        if ($required === 'php' || str_starts_with($required, 'ext-') || str_starts_with($required, 'illuminate/') || $required === 'brick/math') {
            return true;
        }

        foreach ($package->extraRequires as $pattern) {
            if (fnmatch($pattern, $required)) {
                return true;
            }
        }

        return false;
    }

    /** First namespace segment below the package root, or null for the package root namespace itself. */
    private function module(string $namespace, SourcePackage $owner): ?string
    {
        $relative = trim(substr($namespace, strlen($owner->namespace)), '\\');

        $module = $relative === '' ? '' : explode('\\', $relative)[0];

        // The package root, Laravel registrars, and `Internal` are composition roots allowed to wire core modules.
        return in_array($module, ['', 'Internal', 'Laravel'], true) ? null : $module;
    }

    private function hasAttribute(Node\Stmt\ClassLike $declaration, string ...$names): bool
    {
        foreach ($declaration->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (in_array($attribute->name->toString(), $names, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  array<string, string>  $parents */
    private function isModel(string $class, array $parents): bool
    {
        for ($depth = 0; $depth < 20 && isset($parents[$class]); $depth++) {
            $class = $parents[$class];

            if (in_array($class, self::MODEL_BASES, true)) {
                return true;
            }
        }

        return false;
    }

    private function staticCallName(StaticCall $call): ?string
    {
        return $call->class instanceof Node\Name ? $call->class->getLast() : null;
    }

    private function logicalTable(MethodCall $call): ?string
    {
        if (! $call->name instanceof Identifier || ! in_array($call->name->toString(), self::TABLE_METHODS, true)) {
            return null;
        }

        $first = $call->args[0] ?? null;

        if (! $first instanceof Node\Arg || ! $first->value instanceof String_) {
            return null;
        }

        $isThis = $call->var instanceof Node\Expr\Variable && $call->var->name === 'this';
        $name = $call->name->toString();

        // `->create()`/`->drop()` only count on `$this` (CommerzMigration); `->table()`/`->on()`/`->constrained()` anywhere.
        if (in_array($name, ['create', 'drop'], true) && ! $isThis) {
            return null;
        }

        return $first->value->value;
    }

    private function owns(SourcePackage $package, string $logical): bool
    {
        foreach ($package->nouns as $noun) {
            if ($logical === $noun || $logical === Str::plural($noun) || str_starts_with($logical, "{$noun}_")) {
                return true;
            }
        }

        return false;
    }

    private function hasFloat(?Node $type): bool
    {
        if ($type instanceof Identifier) {
            return strtolower($type->toString()) === 'float';
        }

        if ($type instanceof NullableType) {
            return $this->hasFloat($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            return array_any($type->types, fn (Node $inner): bool => $this->hasFloat($inner));
        }

        return false;
    }

    private function variableName(Node $variable): string
    {
        return $variable instanceof Node\Expr\Variable && is_string($variable->name) ? $variable->name : '?';
    }
}
