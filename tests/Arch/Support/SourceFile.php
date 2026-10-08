<?php

declare(strict_types=1);

namespace Commerz\Arch;

use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;

/** A parsed PHP file whose names are resolved to fully qualified ones. */
final readonly class SourceFile
{
    /** @param  list<Node\Stmt>  $statements */
    public function __construct(
        public SourcePackage $package,
        public string $relativePath,
        public string $namespace,
        public array $statements,
    ) {}

    /** @return list<ClassLike> named class-likes (classes, interfaces, traits, enums) */
    public function declarations(): array
    {
        $found = [];

        foreach ((new NodeFinder)->findInstanceOf($this->statements, ClassLike::class) as $node) {
            if ($node->name !== null) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /** @return list<string> every fully qualified class-like name the file refers to */
    public function referencedNames(): array
    {
        $names = [];

        foreach ((new NodeFinder)->findInstanceOf($this->statements, FullyQualified::class) as $name) {
            $names[$name->toString()] = true;
        }

        return array_map(strval(...), array_keys($names));
    }

    /** Whether the file lives in an `Internal` namespace. */
    public function isInternal(): bool
    {
        return SourceScanner::isInternal($this->namespace.'\\x');
    }

    /** Whether a namespace segment equals one of `$segments` (e.g. `Contracts`, `Data`). */
    public function inNamespaceSegment(string ...$segments): bool
    {
        return array_intersect(explode('\\', $this->namespace), $segments) !== [];
    }
}
