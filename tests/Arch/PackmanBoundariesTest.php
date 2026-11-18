<?php

declare(strict_types=1);

use Commerz\Arch\SourceScanner;
use Packman\Attributes\Internal;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\PrettyPrinter\Standard;

it('marks every internal declaration and consumes only public Packman types', function (): void {
    $root = dirname(__DIR__, 2);
    $scanner = new SourceScanner($root, require __DIR__.'/ownership.php');
    $violations = [];

    foreach ($scanner->files() as $file) {
        foreach ($file->referencedNames() as $name) {
            if (str_starts_with($name, 'Packman\\') && SourceScanner::isInternal($name)) {
                $violations[] = $file->relativePath.': '.$name;
            }
        }

        foreach ($file->declarations() as $declaration) {
            if ($file->isInternal()) {
                $markers = [];
                foreach ($declaration->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        $markers[] = $attribute->name->toString();
                    }
                }
                if (! in_array(Internal::class, $markers, true)) {
                    $violations[] = $file->relativePath.': missing internal marker';
                }

                continue;
            }

            foreach ($declaration->stmts as $statement) {
                $types = [];
                if ($statement instanceof ClassMethod && ! $statement->isPrivate()) {
                    $types[] = $statement->returnType;
                    foreach ($statement->params as $param) {
                        $types[] = $param->type;
                    }
                } elseif ($statement instanceof Property && ! $statement->isPrivate()) {
                    $types[] = $statement->type;
                }

                foreach ($types as $type) {
                    if ($type !== null && str_contains((new Standard)->prettyPrint([$type]), '\\Internal\\')) {
                        $violations[] = $file->relativePath.': internal type in public/SPI signature';
                    }
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps internal types out of the exported PHP and PHPDoc contracts', function (): void {
    foreach (glob(dirname(__DIR__, 2).'/packages/*/resources/contracts/php-api.json') as $manifest) {
        expect(file_get_contents($manifest))->not->toContain('\\\\Internal\\\\');
    }
});
