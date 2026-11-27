<?php

declare(strict_types=1);

use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Options\Data\AddOptionValues;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Options\Data\ListOptions;
use Commerz\Catalog\Options\Data\OptionId;
use Commerz\Catalog\Options\Data\OptionValueView;
use Commerz\Catalog\Options\Data\UpdateOption;
use Commerz\Catalog\Options\Exceptions\OptionNotFound;
use Commerz\Catalog\Options\Exceptions\UnknownOptionValue;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function optLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

/**
 * @return array<string, LocalizedText>
 */
function optValues(string ...$codes): array
{
    return array_combine($codes, array_map(optLabel(...), $codes));
}

it('defines an option with ordered values', function (): void {
    $view = Catalog::options()->define(new DefineOption('size', optLabel('Size'), optValues('s', 'm', 'l')));

    expect($view->code)->toBe('size')
        ->and(array_map(static fn (OptionValueView $v) => [$v->code, $v->position], $view->values))->toBe([['s', 0], ['m', 1], ['l', 2]])
        ->and(Catalog::options()->find($view->id)->values)->toHaveCount(3)
        ->and(Catalog::options()->findByCode('size')->id->equals($view->id))->toBeTrue();
});

it('rejects invalid commands, duplicate codes and missing options', function (): void {
    expect(fn () => new DefineOption('size', optLabel('Size'), optValues()))->toThrow(InvalidCommand::class)
        ->and(fn () => new DefineOption('Big Size', optLabel('Size'), optValues('s')))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListOptions(first: 201))->toThrow(InvalidCommand::class);

    Catalog::options()->define(new DefineOption('size', optLabel('Size'), optValues('s')));

    expect(fn () => Catalog::options()->define(new DefineOption('size', optLabel('Other'), optValues('x'))))
        ->toThrow(DuplicateCode::class)
        ->and(fn () => Catalog::options()->findByCode('nope'))->toThrow(OptionNotFound::class)
        ->and(fn () => Catalog::options()->find(OptionId::generate()))->toThrow(OptionNotFound::class);
});

it('appends values and refuses a duplicate value code', function (): void {
    $view = Catalog::options()->define(new DefineOption('color', optLabel('Color'), optValues('red')));
    $added = Catalog::options()->addValues(new AddOptionValues($view->id, optValues('blue', 'green')));

    expect(array_map(static fn (OptionValueView $v) => [$v->code, $v->position], $added->values))->toBe([['red', 0], ['blue', 1], ['green', 2]])
        ->and(fn () => Catalog::options()->addValues(new AddOptionValues($view->id, optValues('red'))))->toThrow(DuplicateCode::class)
        ->and(fn () => Catalog::options()->addValues(new AddOptionValues(OptionId::generate(), optValues('x'))))->toThrow(OptionNotFound::class);
});

it('renames an option and relabels values keeping ids', function (): void {
    $view = Catalog::options()->define(new DefineOption('color', optLabel('Color'), optValues('red', 'blue')));
    $updated = Catalog::options()->update(new UpdateOption($view->id, optLabel('Colour'), ['blue' => optLabel('Navy')]));

    expect($updated->name->texts)->toBe(['en' => 'Colour'])
        ->and($updated->values[1]->label->texts)->toBe(['en' => 'Navy'])
        ->and($updated->values[1]->id->equals($view->values[1]->id))->toBeTrue()
        ->and($updated->values[0]->label->texts)->toBe(['en' => 'red'])
        ->and(fn () => Catalog::options()->update(new UpdateOption($view->id, valueLabels: ['pink' => optLabel('Pink')])))
        ->toThrow(UnknownOptionValue::class);
});

it('lists by code with a keyset cursor', function (): void {
    foreach (['c', 'a', 'b'] as $code) {
        Catalog::options()->define(new DefineOption($code, optLabel($code), optValues('v')));
    }

    $first = Catalog::options()->list(new ListOptions(first: 2));
    $second = Catalog::options()->list(new ListOptions(first: 2, after: $first->next));

    expect(array_map(static fn (object $v) => $v->code, $first->items))->toBe(['a', 'b'])
        ->and($first->hasMore())->toBeTrue()
        ->and(array_map(static fn (object $v) => $v->code, $second->items))->toBe(['c'])
        ->and($second->hasMore())->toBeFalse();
});

it('keeps every label unchanged when the host catches an invalid update', function (): void {
    $option = Catalog::options()->define(new DefineOption('color', optLabel('Color'), optValues('red', 'blue')));
    expect(fn () => Catalog::options()->update(new UpdateOption($option->id, valueLabels: [
        'red' => optLabel('Changed'), 'missing' => optLabel('Invalid'),
    ])))->toThrow(UnknownOptionValue::class);

    expect(Catalog::options()->find($option->id)->values[0]->label->texts)->toBe(['en' => 'red']);
});
