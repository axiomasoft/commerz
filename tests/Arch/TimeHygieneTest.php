<?php

declare(strict_types=1);

use Commerz\Arch\SourceScanner;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

it('forbids direct now() and Carbon::now() calls in package src/ (INV-CORE-001)', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var array<string, array{kind: string, nouns: list<string>, extra?: list<string>}> $map */
    $map = require __DIR__.'/ownership.php';
    $scanner = new SourceScanner($root, $map);
    $finder = new NodeFinder;
    $violations = [];
    $checked = 0;

    foreach ($scanner->files() as $file) {
        $checked++;

        foreach ($finder->findInstanceOf($file->statements, FuncCall::class) as $call) {
            if ($call->name instanceof Name && strtolower($call->name->toString()) === 'now') {
                $violations[] = "{$file->relativePath}: line {$call->getStartLine()} calls now() directly (use Commerz\\Time\\Contracts\\Clock or PgTimestamp).";
            }
        }

        foreach ($finder->findInstanceOf($file->statements, StaticCall::class) as $call) {
            if ($call->class instanceof Name
                && in_array($call->class->getLast(), ['Carbon', 'CarbonImmutable'], true)
                && $call->name instanceof Identifier
                && strtolower($call->name->toString()) === 'now') {
                $violations[] = "{$file->relativePath}: line {$call->getStartLine()} calls {$call->class->getLast()}::now() directly (use Commerz\\Time\\Contracts\\Clock or PgTimestamp).";
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($violations)->toBe([]);
});

it('forbids duplicate Common\\Internal\\Clock and Common\\Internal\\Rows classes', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var array<string, array{kind: string, nouns: list<string>, extra?: list<string>}> $map */
    $map = require __DIR__.'/ownership.php';
    $scanner = new SourceScanner($root, $map);
    $violations = [];
    $checked = 0;

    foreach ($scanner->files() as $file) {
        $checked++;

        if (preg_match('/\\\\Common\\\\Internal$/', $file->namespace) === 1) {
            foreach ($file->declarations() as $declaration) {
                $name = $declaration->name?->toString();

                if (in_array($name, ['Clock', 'Rows'], true)) {
                    $violations[] = "{$file->relativePath}: duplicate {$file->namespace}\\{$name} (use Commerz\\Time or Commerz\\Schema\\Rows).";
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($violations)->toBe([]);
});
