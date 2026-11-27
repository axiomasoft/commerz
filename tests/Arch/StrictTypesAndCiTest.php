<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('keeps strict_types on the first-party trees and includes them in PHPStan', function (): void {
    $root = dirname(__DIR__, 2);
    $neon = requireString(file_get_contents($root.'/phpstan.neon'), 'phpstan.neon');
    expect($neon)->not->toContain('ignoreErrors')
        ->and(is_file($root.'/phpstan-baseline.neon'))->toBeFalse();

    $paths = neonList($neon, 'paths');
    $excluded = neonList($neon, 'excludePaths');
    expect($paths)->toContain('scripts')
        ->and($paths)->toContain('tests')
        ->and($excluded)->toContain('tests/Arch/Fixtures');

    foreach (['b2b', 'catalog', 'core', 'inventory', 'pricing', 'promotions', 'sales', 'shipping', 'stored-value', 'subscriptions', 'testkit'] as $package) {
        expect($paths)->toContain("packages/{$package}/src")
            ->and($paths)->toContain("packages/{$package}/tests");
    }

    foreach ($paths as $path) {
        expect($path)->not->toStartWith('audits/')
            ->and($path)->not->toStartWith('vendor/')
            ->and($path)->not->toBe('tests/Arch/Fixtures');
    }

    $offenders = [];

    foreach (firstPartyPhpFiles($root) as $relative) {
        $source = file_get_contents($root.'/'.$relative);
        if (! is_string($source)) {
            $offenders[] = "{$relative} is unreadable";

            continue;
        }

        $body = ltrim($source);
        if (str_starts_with($body, '<?php')) {
            $body = ltrim(substr($body, 5));
        }

        if (! str_starts_with($body, 'declare(strict_types=1);')) {
            $offenders[] = "{$relative} is missing declare(strict_types=1) as its first statement";
        }

        $fixture = str_starts_with($relative, 'tests/Arch/Fixtures/');
        $analysed = phpstanIncludes($relative, $paths, $excluded);
        if ($fixture && $analysed) {
            $offenders[] = "{$relative} is a negative fixture and must stay out of PHPStan";
        }
        if (! $fixture && ! $analysed) {
            $offenders[] = "{$relative} is not covered by phpstan.neon paths";
        }
    }

    expect($offenders)->toBe([]);
});

it('requires the corex CI controls that apply to this repository', function (): void {
    $root = dirname(__DIR__, 2);
    $workflowPath = $root.'/.github/workflows/ci.yml';
    $raw = requireString(file_get_contents($workflowPath), $workflowPath);

    $workflow = workflowMap(Yaml::parse($raw));
    expect($workflow['permissions'] ?? null)->toBe([])
        ->and($workflow['concurrency'] ?? null)->toBeArray();

    $concurrency = workflowMap($workflow['concurrency']);
    expect($concurrency['cancel-in-progress'] ?? null)->toBe("\${{ github.ref != 'refs/heads/main' }}");

    $jobs = workflowMap($workflow['jobs'] ?? null);
    $gate = workflowMap($jobs['ci-gate'] ?? null);
    expect($gate['if'] ?? null)->toBe('always()')
        ->and($gate['needs'] ?? null)->toBeArray();

    /** @var list<mixed> $gateNeeds */
    $gateNeeds = is_array($gate['needs']) ? array_values($gate['needs']) : [];
    foreach (array_keys($jobs) as $name) {
        $job = workflowMap($jobs[$name]);
        expect($job['timeout-minutes'] ?? null)->toBeInt()->toBeGreaterThan(0)
            ->and($job['permissions'] ?? null)->toBeArray();
        $permissions = workflowMap($job['permissions']);
        expect($permissions['contents'] ?? null)->toBe('read')
            ->and($permissions)->not->toHaveKey('contents', 'write');

        if ($name === 'ci-gate') {
            continue;
        }

        expect($gateNeeds)->toContain($name);
    }

    $tests = workflowMap($jobs['tests'] ?? null);
    $strategy = workflowMap($tests['strategy'] ?? null);
    $matrix = workflowMap($strategy['matrix'] ?? null);
    expect($matrix['php'] ?? null)->toBe(['8.4', '8.5', '8.6']);

    $postgres = workflowMap($jobs['tests-pg'] ?? null);
    $postgresMatrix = workflowMap(workflowMap($postgres['strategy'] ?? null)['matrix'] ?? null);
    expect($postgresMatrix['postgres'] ?? null)->toBe(['16', '19']);

    expect($raw)->toContain('composer audit --locked')
        ->and($raw)->toContain('composer validate --strict --no-check-lock')
        ->and($raw)->toContain('dorny/paths-filter@ceb8a2b8f2d89434be7ff52d3de7ec3738c5cc9d')
        ->and($raw)->toContain('predicate-quantifier: some')
        ->and($raw)->toContain('!**/*.md')
        ->and($raw)->toContain('!docs/**');

    $packman = file_get_contents($root.'/.github/actions/setup-packman/action.yml');
    expect($packman)->toBeString()->toContain('ref: v0.1.1');

    foreach (['.github/workflows/ci.yml', '.github/actions/setup-packman/action.yml', '.github/actions/setup-php/action.yml'] as $relative) {
        assertThirdPartyActionsArePinned($root.'/'.$relative);
    }

    $compatibility = file_get_contents($root.'/docs/reference/compatibility.md');
    $rootManifest = file_get_contents($root.'/composer.json');
    $compose = file_get_contents($root.'/docker-compose.yml');
    expect($compatibility)->toBeString()
        ->and($compatibility)->toContain('8.4')
        ->and($compatibility)->toContain('8.5')
        ->and($compatibility)->toContain('8.6')
        ->and($compatibility)->toContain('16')
        ->and($compatibility)->toContain('19')
        ->and($rootManifest)->toBeString()->toContain('>=8.4 <8.7')->not->toContain('<8.6')
        ->and($compose)->toBeString()->toContain('image: postgres:19');
});

/**
 * @return list<string>
 */
function neonList(string $neon, string $key): array
{
    $items = [];
    $collecting = false;
    $itemIndent = 0;

    $lines = preg_split("/\r\n|\n|\r/", $neon);
    if ($lines === false) {
        $lines = [];
    }

    foreach ($lines as $line) {
        if (preg_match('/^(\s*)'.preg_quote($key, '/').':\s*$/', $line, $matches) === 1) {
            $collecting = true;
            $itemIndent = strlen($matches[1]) + 4;

            continue;
        }

        if (! $collecting) {
            continue;
        }

        if (preg_match('/^\s{'.$itemIndent.'}- (.+)$/', $line, $matches) === 1) {
            $items[] = trim($matches[1], " \t\"'");

            continue;
        }

        if (trim($line) === '') {
            continue;
        }

        $collecting = false;
    }

    return $items;
}

/**
 * @return list<string>
 */
function firstPartyPhpFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1);
        $parts = explode('/', str_replace('\\', '/', $relative));
        if (($parts[2] ?? '') === 'src' || ($parts[2] ?? '') === 'tests') {
            $files[] = str_replace('\\', '/', $relative);
        }
    }

    foreach (['tests', 'scripts'] as $tree) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$tree, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * @param  list<string>  $paths
 * @param  list<string>  $excluded
 */
function phpstanIncludes(string $relative, array $paths, array $excluded): bool
{
    foreach ($excluded as $exclude) {
        if ($relative === $exclude || str_starts_with($relative, rtrim($exclude, '/').'/')) {
            return false;
        }
    }

    foreach ($paths as $path) {
        if ($relative === $path || str_starts_with($relative, rtrim($path, '/').'/')) {
            return true;
        }
    }

    return false;
}

function requireString(mixed $value, string $label): string
{
    if (! is_string($value)) {
        throw new RuntimeException("Expected a string from {$label}.");
    }

    return $value;
}

/** @return array<string, mixed> */
function workflowMap(mixed $value): array
{
    if (! is_array($value)) {
        throw new RuntimeException('Expected a workflow mapping.');
    }

    $mapped = [];
    foreach ($value as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException('Expected string keys in a workflow mapping.');
        }
        $mapped[$key] = $item;
    }

    return $mapped;
}

function assertThirdPartyActionsArePinned(string $path): void
{
    $contents = file_get_contents($path);
    if (! is_string($contents)) {
        throw new RuntimeException("Unreadable workflow {$path}.");
    }

    $unpinned = [];
    $lines = preg_split("/\r\n|\n|\r/", $contents);
    if ($lines === false) {
        $lines = [];
    }

    foreach ($lines as $line) {
        if (preg_match('/uses:\s+(\S+)/', $line, $matches) !== 1) {
            continue;
        }

        $uses = $matches[1];
        if (str_starts_with($uses, './') || str_starts_with($uses, 'docker://')) {
            continue;
        }

        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*@[0-9a-f]{40}$#', $uses) !== 1) {
            $unpinned[] = $uses;
        }
    }

    expect($unpinned)->toBe([]);
}
