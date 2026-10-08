<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Measures\Measure;
use Commerz\Measures\Quantity;

it('adds and subtracts quantities of one unit', function (): void {
    $a = Quantity::of(5, 'g');

    expect($a->plus(Quantity::of(3, 'g'))->value)->toBe(8)
        ->and($a->minus(Quantity::of(5, 'g'))->isZero())->toBeTrue()
        ->and($a->compare(Quantity::of(9, 'g')))->toBe(-1)
        ->and($a->equals(Quantity::of(5, 'g')))->toBeTrue()
        ->and($a->equals(Quantity::of(5, 'ml')))->toBeFalse()
        ->and(Quantity::pieces(2)->unit)->toBe('pcs');
});

it('rejects invalid quantities', function (): void {
    expect(fn () => Quantity::of(-1, 'pcs'))->toThrow(InvalidCommand::class)
        ->and(fn () => Quantity::of(1, 'Pcs'))->toThrow(InvalidCommand::class)
        ->and(fn () => Quantity::of(1, ''))->toThrow(InvalidCommand::class)
        ->and(fn () => Quantity::of(1, 'g')->plus(Quantity::of(1, 'ml')))->toThrow(InvalidCommand::class)
        ->and(fn () => Quantity::of(1, 'g')->minus(Quantity::of(2, 'g')))->toThrow(InvalidCommand::class)
        ->and(fn () => Quantity::of(PHP_INT_MAX, 'g')->plus(Quantity::of(1, 'g')))->toThrow(InvalidCommand::class);
});

it('keeps measures as decimal strings and compares numerically', function (): void {
    $m = Measure::of('1.50', 'kg');

    expect($m->value)->toBe('1.50')
        ->and($m->equals(Measure::of('1.5', 'kg')))->toBeTrue()
        ->and($m->equals(Measure::of('1.5', 'g')))->toBeFalse()
        ->and($m->compare(Measure::of('-2', 'kg')))->toBe(1);
});

it('rejects malformed measures', function (string $value): void {
    expect(fn () => Measure::of($value, 'kg'))->toThrow(InvalidCommand::class);
})->with(['', '1e3', '1,5', 'abc', '.5', '1.1234567890123']);

it('rejects comparing measures of different units', function (): void {
    expect(fn () => Measure::of('1', 'kg')->compare(Measure::of('1', 'g')))->toThrow(InvalidCommand::class);
});

it('resolves localized text through locale, language, fallback and first entry', function (): void {
    $t = new LocalizedText(['en' => 'Shoes', 'ru' => 'Обувь', 'pt' => 'Sapatos']);

    expect($t->resolve('ru'))->toBe('Обувь')
        ->and($t->resolve('pt-BR'))->toBe('Sapatos')
        ->and($t->resolve('de'))->toBe('Shoes')
        ->and($t->resolve('de', 'ru'))->toBe('Обувь')
        ->and(LocalizedText::of('ru', 'Чай')->resolve('de'))->toBe('Чай')
        ->and($t->with('de', 'Schuhe')->resolve('de'))->toBe('Schuhe');
});

it('rejects empty maps, bad locales and blank text', function (): void {
    expect(fn () => new LocalizedText([]))->toThrow(InvalidCommand::class)
        ->and(fn () => LocalizedText::of('EN', 'x'))->toThrow(InvalidCommand::class)
        ->and(fn () => LocalizedText::of('en', '  '))->toThrow(InvalidCommand::class);
});
