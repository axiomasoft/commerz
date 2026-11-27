<?php

declare(strict_types=1);

/**
 * Regenerates packages/<pkg>/resources/contracts/php-api.json, the SemVer snapshot of the public PHP surface, twice
 * and requires byte-identical output, then checks it.
 *
 * Usage: php scripts/generate-api-manifest.php [--check]   (--check writes nothing and fails on a stale manifest)
 */
$root = dirname(__DIR__);
$cliArguments = $_SERVER['argv'] ?? [];
$check = is_array($cliArguments) && in_array('--check', $cliArguments, true);

// Distinct $attempt values keep the repeated generations from being folded into one exit code.
$run = static function (bool $write, int $attempt) use ($root): int {
    if ($attempt < 1) {
        return 1;
    }

    $command = sprintf(
        'cd %s && %s%s vendor/bin/pest --colors=never tests/Arch/ApiManifestTest.php',
        escapeshellarg($root),
        $write ? 'COMMERZ_UPDATE_API_MANIFEST=1 ' : '',
        escapeshellarg(PHP_BINARY),
    );
    $code = 0;
    passthru($command, $code);

    return $code;
};

$hash = static function () use ($root): string {
    $files = glob($root.'/packages/*/resources/contracts/php-api.json');
    if ($files === false) {
        $files = [];
    }

    return hash('sha256', implode('', array_map(
        static function (string $file): string {
            $digest = hash_file('sha256', $file);
            if ($digest === false) {
                throw new RuntimeException("Cannot hash {$file}.");
            }

            return $file.$digest;
        },
        $files,
    )));
};

if ($check) {
    exit($run(false, 1));
}

if ($run(true, 1) !== 0) {
    exit(1);
}

$first = $hash();

if ($run(true, 2) !== 0) {
    exit(1);
}

if ($first !== $hash()) {
    fwrite(STDERR, "The php-api manifests are not deterministic: two generations differ.\n");

    exit(1);
}

exit($run(false, 3));
