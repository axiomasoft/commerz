<?php

declare(strict_types=1);

/**
 * Regenerates packages/<pkg>/resources/contracts/php-api.json, the SemVer snapshot of the public PHP surface, twice
 * and requires byte-identical output, then checks it.
 *
 * Usage: php scripts/generate-api-manifest.php [--check]   (--check writes nothing and fails on a stale manifest)
 */
$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

$run = static function (bool $write) use ($root): int {
    $command = sprintf(
        'cd %s && %s%s vendor/bin/pest --colors=never tests/Arch/ApiManifestTest.php',
        escapeshellarg($root),
        $write ? 'COMMERZ_UPDATE_API_MANIFEST=1 ' : '',
        escapeshellarg(PHP_BINARY),
    );
    passthru($command, $code);

    return $code;
};

$hash = static fn (): string => hash('sha256', implode('', array_map(
    static fn (string $file): string => $file.hash_file('sha256', $file),
    glob($root.'/packages/*/resources/contracts/php-api.json') ?: [],
)));

if ($check) {
    exit($run(false));
}

if ($run(true) !== 0) {
    exit(1);
}

$first = $hash();

if ($run(true) !== 0) {
    exit(1);
}

if ($first !== $hash()) {
    fwrite(STDERR, "The php-api manifests are not deterministic: two generations differ.\n");

    exit(1);
}

exit($run(false));
