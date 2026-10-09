<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;

it('round trips a Unicode sort key through its canonical cursor token', function (): void {
    $cursor = Cursor::after('товар-1');
    expect(Cursor::fromToken((string) $cursor)->position())->toBe('товар-1');
});

it('refuses empty or invalid text sort keys', function (string $position): void {
    expect(fn () => Cursor::after($position))->toThrow(InvalidCommand::class);
})->with(['empty' => [''], 'invalid UTF-8' => ["\xff"], 'NUL' => ["\0"]]);

it('refuses invalid payloads and noncanonical cursor tokens', function (string $token): void {
    expect(fn () => Cursor::fromToken($token))->toThrow(InvalidCommand::class);
})->with(['invalid UTF-8' => ['_w'], 'NUL' => ['AA'], 'noncanonical base64' => ['Zh']]);
