<?php

declare(strict_types=1);

use Commerz\Catalog\Common\Internal\Documents;

it('encodes an empty document as an object', function (): void {
    expect(Documents::encode([]))->toBe('{}');
});

it('encodes a document keyed like a list as an object', function (): void {
    expect(Documents::encode(['0' => 'x', '1' => ['a', 'b']]))->toBe('{"0":"x","1":["a","b"]}');
});

it('encodes unicode unescaped and keeps zero fractions', function (): void {
    expect(Documents::encode(['name' => 'Футболка', 'weight' => 1.0]))->toBe('{"name":"Футболка","weight":1.0}');
});

it('round-trips an object', function (): void {
    $document = ['a' => 1, 'nested' => ['b' => [1, 2], 'c' => null]];

    expect(Documents::decode(Documents::encode($document)))->toBe($document)
        ->and(Documents::decode('{}'))->toBe([]);
});

it('refuses lists, scalars and broken JSON', function (string $json): void {
    expect(fn () => Documents::decode($json))->toThrow(RuntimeException::class);
})->with(['[1,2]', '"text"', '12', '{broken']);
