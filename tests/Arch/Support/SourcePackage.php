<?php

declare(strict_types=1);

namespace Commerz\Arch;

/** One `packages/<dir>` entry: its Composer manifest, kind and PSR-4 root namespace. */
final readonly class SourcePackage
{
    /**
     * @param  string  $kind  core|feature|testkit|meta
     * @param  array<string, mixed>  $composer
     * @param  list<string>  $nouns
     * @param  list<string>  $extraRequires
     */
    public function __construct(
        public string $directory,
        public string $path,
        public string $kind,
        public array $composer,
        public string $namespace,
        public array $nouns,
        public array $extraRequires,
    ) {}

    public function name(): string
    {
        $name = $this->composer['name'] ?? null;

        return is_string($name) ? $name : $this->directory;
    }

    /** @return list<string> */
    public function requires(): array
    {
        $require = $this->composer['require'] ?? [];

        return is_array($require) ? array_map(strval(...), array_keys($require)) : [];
    }
}
