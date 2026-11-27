<?php

declare(strict_types=1);

namespace Commerz\Arch;

use JsonException;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** Walks `packages/*` of a repository root and parses `src/` with nikic/php-parser. */
final class SourceScanner
{
    /** @var list<SourcePackage>|null */
    private ?array $packages = null;

    /** @param  array<string, array{kind: string, nouns: list<string>, extra?: list<string>}>  $map */
    public function __construct(public readonly string $root, private readonly array $map) {}

    /** True when a fully qualified name has an `Internal` segment before its last one. */
    public static function isInternal(string $fqn): bool
    {
        $segments = explode('\\', $fqn);
        array_pop($segments);

        return in_array('Internal', $segments, true);
    }

    /** @return list<SourcePackage> */
    public function packages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $packages = [];
        $directories = glob($this->root.'/packages/*', GLOB_ONLYDIR);
        if ($directories === false) {
            $directories = [];
        }
        sort($directories);

        foreach ($directories as $path) {
            $directory = basename($path);
            $entry = $this->map[$directory] ?? throw new RuntimeException("Package directory '{$directory}' is missing from tests/Arch/ownership.php.");
            $composer = $this->manifest($path.'/composer.json');

            $packages[] = new SourcePackage(
                directory: $directory,
                path: $path,
                kind: $entry['kind'],
                composer: $composer,
                namespace: $this->namespaceOf($composer),
                nouns: $entry['nouns'],
                extraRequires: $entry['extra'] ?? [],
            );
        }

        return $this->packages = $packages;
    }

    /** @return list<SourceFile> */
    public function files(): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $files = [];

        foreach ($this->packages() as $package) {
            foreach ($this->phpFiles($package->path.'/src') as $file) {
                $source = file_get_contents($file);
                $parsed = $source === false ? null : $parser->parse($source);

                if ($parsed === null) {
                    throw new RuntimeException("Cannot parse {$file}.");
                }

                $statements = [];
                foreach ((new NodeTraverser(new NameResolver))->traverse($parsed) as $statement) {
                    if (! $statement instanceof Stmt) {
                        throw new RuntimeException("Cannot parse {$file}.");
                    }

                    $statements[] = $statement;
                }

                $namespace = '';
                foreach ($statements as $statement) {
                    if ($statement instanceof Namespace_ && $statement->name !== null) {
                        $namespace = $statement->name->toString();
                    }
                }

                $files[] = new SourceFile($package, $this->relative($file), $namespace, $statements);
            }
        }

        return $files;
    }

    /** @return list<string> absolute paths of `database/migrations/*.php` */
    public function migrations(SourcePackage $package): array
    {
        return $this->phpFiles($package->path.'/database/migrations');
    }

    /** The package that owns a fully qualified class name (longest PSR-4 prefix wins), or null for foreign code. */
    public function ownerOf(string $fqn): ?SourcePackage
    {
        $owner = null;

        foreach ($this->packages() as $package) {
            if ($package->namespace === '' || ! str_starts_with($fqn, $package->namespace)) {
                continue;
            }

            if ($owner === null || strlen($package->namespace) > strlen($owner->namespace)) {
                $owner = $package;
            }
        }

        return $owner;
    }

    public function relative(string $path): string
    {
        return ltrim(substr($path, strlen($this->root)), '/');
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<string, mixed> */
    private function manifest(string $path): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid {$path}: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($data)) {
            throw new RuntimeException("Invalid {$path}: not an object.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param  array<string, mixed>  $composer */
    private function namespaceOf(array $composer): string
    {
        $autoload = $composer['autoload'] ?? [];
        $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];

        if (is_array($psr4)) {
            foreach ($psr4 as $namespace => $directory) {
                if ($directory === 'src/') {
                    return (string) $namespace;
                }
            }
        }

        return '';
    }
}
