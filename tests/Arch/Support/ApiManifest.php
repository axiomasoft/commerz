<?php

declare(strict_types=1);

namespace Commerz\Arch;

use BackedEnum;
use Packman\Errors\Contracts\DescribesError;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use stdClass;
use UnitEnum;

/**
 * The deterministic manifest of each package's public PHP surface, `packages/<pkg>/resources/contracts/php-api.json`
 * (schema `commerz.php-api/1`): the SemVer snapshot of 0.1. CI fails on a stale manifest; it changes only through
 * `composer api:manifest`. A package without `src/` (the `full` metapackage) has none.
 */
final readonly class ApiManifest
{
    public const string SCHEMA = 'commerz.php-api/1';

    public function __construct(private ApiSurface $surface) {}

    /** @return array<string, array<string, mixed>> package directory => manifest */
    public function build(): array
    {
        $manifests = [];

        foreach ($this->surface->packages() as $package) {
            $types = [];

            foreach ($this->surface->types($package) as $type) {
                $types[$type] = $this->type(new ReflectionClass($type));
            }

            ksort($types);
            $platform = $this->surface->platform($package);
            ksort($platform);

            $manifests[$package->directory] = [
                'schema' => self::SCHEMA,
                'package' => $package->directory,
                'composer' => $package->name(),
                'types' => $types === [] ? new stdClass : $types,
                'platform' => $platform === [] ? new stdClass : $platform,
            ];
        }

        ksort($manifests);

        return $manifests;
    }

    /**
     * The manifest entry of a public type: its kind, its layer from the marker, then its members.
     *
     * @param  ReflectionClass<object>  $type
     * @return array<string, mixed>
     */
    public function type(ReflectionClass $type): array
    {
        $members = $this->members($type);

        return ['kind' => $members['kind']] + ApiSurface::classify($type) + $members;
    }

    /**
     * What a type declares, without its marker: kind, modifiers, parents, cases, constants, properties, methods.
     *
     * @param  ReflectionClass<object>  $type
     * @return array<string, mixed>
     */
    public function members(ReflectionClass $type): array
    {
        $name = $type->getName();
        $entry = [
            'kind' => match (true) {
                $type->isEnum() => 'enum',
                $type->isInterface() => 'interface',
                $type->isTrait() => 'trait',
                default => 'class',
            },
        ];

        $modifiers = array_values(array_filter([
            $type->isFinal() && ! $type->isEnum() ? 'final' : null,
            $type->isAbstract() && ! $type->isInterface() && ! $type->isTrait() ? 'abstract' : null,
            ! $type->isEnum() && $type->isReadOnly() ? 'readonly' : null,
        ]));
        $parent = $type->getParentClass();
        $interfaces = array_values(array_diff($type->getInterfaceNames(), [UnitEnum::class, BackedEnum::class, DescribesError::class]));
        sort($interfaces);
        $extensible = ! $type->isFinal() && ! $type->isEnum();

        $cases = [];

        if ($type->isEnum()) {
            foreach ((new ReflectionEnum($name))->getCases() as $case) {
                $cases[$case->getName()] = $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : null;
            }
        }

        $constants = [];

        foreach ($type->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $name && ! $constant->isEnumCase()
                && ($constant->isPublic() || ($extensible && $constant->isProtected()))) {
                $constants[$constant->getName()] = self::literal($constant->getValue());
            }
        }

        $properties = [];

        foreach ($type->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $name && ! $type->isEnum() && ($property->isPublic() || ($extensible && $property->isProtected()))) {
                // Asymmetric visibility is part of the contract; `readonly` already implies `protected(set)`.
                $set = match (true) {
                    $property->isPrivateSet() => 'private(set)',
                    $property->isProtectedSet() && ! $property->isReadOnly() && $property->isPublic() => 'protected(set)',
                    default => null,
                };
                $properties[$property->getName()] = implode(' ', array_filter([
                    $property->isProtected() ? 'protected' : 'public',
                    $set,
                    $property->isStatic() ? 'static' : null,
                    $property->isReadOnly() ? 'readonly' : null,
                    self::typeName($property->getType()),
                    '$'.$property->getName(),
                ])).($property->hasDefaultValue() && ! $property->isPromoted() && $property->hasType() ? ' = '.self::literal($property->getDefaultValue()) : '');
            }
        }

        $methods = [];

        foreach ($type->getMethods() as $method) {
            if (($method->isPublic() || ($extensible && $method->isProtected()))
                && $this->declaredHere($method, $type)
                && ! ($type->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true))) {
                $methods[$method->getName()] = $this->signature($method);
            }
        }

        ksort($constants);
        ksort($properties);
        ksort($methods);

        return array_filter([
            ...$entry,
            'modifiers' => $modifiers,
            // An interface extends its parents; a class extends its parent and implements its interfaces.
            'extends' => $type->isInterface() ? $interfaces : ($parent === false ? null : $parent->getName()),
            'implements' => $type->isInterface() ? [] : $interfaces,
            'cases' => $cases,
            'constants' => $constants,
            'properties' => $properties,
            'methods' => $methods,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function signature(ReflectionMethod $method): string
    {
        $declared = $this->surface->declaredSelf($method);
        $class = $method->getDeclaringClass()->getName();
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = self::typeName($parameter->getType());
            $piece = implode(' ', array_filter([
                ($declared['parameters'][$parameter->getName()] ?? false) ? self::asSelf($type, $class) : $type,
                ($parameter->isPassedByReference() ? '&' : '').($parameter->isVariadic() ? '...' : '').'$'.$parameter->getName(),
            ]));

            if ($parameter->isDefaultValueAvailable()) {
                $piece .= ' = '.($parameter->isDefaultValueConstant()
                    ? self::constantName((string) $parameter->getDefaultValueConstantName())
                    : self::literal($parameter->getDefaultValue()));
            }

            $parameters[] = $piece;
        }

        $return = self::typeName($method->getReturnType());
        $return = $declared['return'] ? self::asSelf($return, $class) : $return;
        $prefix = implode(' ', array_filter([
            $method->isFinal() && ! $method->getDeclaringClass()->isFinal() ? 'final' : null,
            $method->isProtected() ? 'protected' : null,
            $method->isStatic() ? 'static' : null,
            $method->isAbstract() && ! $method->getDeclaringClass()->isInterface() ? 'abstract' : null,
        ]));

        return ltrim($prefix.' '.$method->getName().'('.implode(', ', $parameters).')'.($return === '' ? '' : ': '.$return));
    }

    /** A type declared with `self`, which PHP 8.5 reflection reports as the class itself. */
    private static function asSelf(string $type, string $class): string
    {
        return (string) preg_replace('/\\\\'.preg_quote($class, '/').'(?![\\w\\\\])/', 'self', $type);
    }

    /**
     * The constant a default value names. Reflection qualifies an unqualified global constant with the namespace
     * (`Foo\PHP_INT_MAX`); PHP falls back to the global one, and so does the manifest (`\PHP_INT_MAX`).
     */
    private static function constantName(string $name): string
    {
        if (str_contains($name, '::')) {
            return $name;
        }

        if (defined($name)) {
            return '\\'.ltrim($name, '\\');
        }

        $global = substr($name, (int) strrpos($name, '\\') + 1);

        return defined($global) ? '\\'.$global : $name;
    }

    /** @param  array<string, mixed>  $manifest */
    public static function encode(array $manifest): string
    {
        return json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    public static function path(string $root, string $package): string
    {
        return $root.'/packages/'.$package.'/resources/contracts/php-api.json';
    }

    /** A type as declared: classes with a leading backslash, `?T` for a nullable single type, unions in declared order. */
    public static function typeName(?ReflectionType $type): string
    {
        return match (true) {
            $type === null => '',
            $type instanceof ReflectionNamedType => ($type->allowsNull() && ! in_array($type->getName(), ['mixed', 'null'], true) ? '?' : '')
                .($type->isBuiltin() || in_array($type->getName(), ['self', 'static', 'parent'], true) ? '' : '\\').$type->getName(),
            $type instanceof ReflectionUnionType => implode('|', array_map(
                static fn (ReflectionType $member): string => $member instanceof ReflectionIntersectionType ? '('.self::typeName($member).')' : self::typeName($member),
                $type->getTypes(),
            )),
            $type instanceof ReflectionIntersectionType => implode('&', array_map(self::typeName(...), $type->getTypes())),
            default => throw new RuntimeException('Unknown reflection type '.$type::class),
        };
    }

    /** A PHP literal for a constant or default value: short arrays, enum cases and `new` objects by class. */
    public static function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => var_export($value, true),
            is_string($value) => var_export($value, true),
            $value instanceof UnitEnum => '\\'.$value::class.'::'.$value->name,
            is_array($value) => '['.implode(', ', array_map(
                static fn (int|string $key, mixed $item): string => (array_is_list($value) ? '' : self::literal($key).' => ').self::literal($item),
                array_keys($value),
                $value,
            )).']',
            is_object($value) => 'new \\'.$value::class,
            default => throw new RuntimeException('Unsupported literal '.get_debug_type($value)),
        };
    }

    /** A method belongs to the type that declares it, or to a public type that inherits it from a non-public parent. */
    private function declaredHere(ReflectionMethod $method, ReflectionClass $type): bool
    {
        $declaring = $method->getDeclaringClass();

        if ($declaring->getName() === $type->getName()) {
            return true;
        }

        return ! $declaring->isInterface() && SourceScanner::isInternal($declaring->getName()) && $type->isSubclassOf($declaring->getName());
    }
}
