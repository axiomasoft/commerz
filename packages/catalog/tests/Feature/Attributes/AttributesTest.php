<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Attributes\Data\AddAttributeChoices;
use Commerz\Catalog\Attributes\Data\AttributeId;
use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\ListAttributes;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidStatusTransition;
use Commerz\Catalog\Attributes\Internal\AttributeIndexer;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Errors\InvalidCommand;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Measures\LocalizedText;
use Commerz\Pagination\Cursor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function attrLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

it('exposes the root accessors as the bound singletons', function (): void {
    expect(Catalog::attributes())->toBe(app(Attributes::class))
        ->and(Catalog::options())->toBe(app(Options::class));
});

it('defines each built-in type with a minimal config', function (string $type, array $config, array $choices): void {
    $view = Catalog::attributes()->define(new DefineAttribute('a_'.$type, $type, attrLabel($type), $config, $choices));

    expect($view->type)->toBe($type)
        ->and($view->supported)->toBeTrue()
        ->and($view->status)->toBe(AttributeStatus::Active)
        ->and($view->filterable)->toBeFalse()
        ->and($view->configVersion)->toBe(1)
        ->and(array_map(static fn ($choice) => $choice->code, $view->choices))->toBe(array_keys($choices));
    expect(Catalog::attributes()->find($view->id)->code)->toBe('a_'.$type);
})->with([
    'text' => ['text', [], []],
    'localized_text' => ['localized_text', [], []],
    'integer' => ['integer', [], []],
    'decimal' => ['decimal', [], []],
    'boolean' => ['boolean', [], []],
    'date' => ['date', [], []],
    'enum' => ['enum', [], ['red' => LocalizedText::of('en', 'Red'), 'blue' => LocalizedText::of('en', 'Blue')]],
    'multi_enum' => ['multi_enum', [], ['a' => LocalizedText::of('en', 'A')]],
    'measure' => ['measure', ['unit' => 'kg'], []],
    'reference' => ['reference', ['refType' => 'brand'], []],
]);

it('stores the canonical config and keeps choice order', function (): void {
    $decimal = Catalog::attributes()->define(new DefineAttribute('price_step', 'decimal', attrLabel('Step'), ['scale' => 3]));
    $enum = Catalog::attributes()->define(new DefineAttribute('size', 'enum', attrLabel('Size'), choices: [
        'xl' => attrLabel('XL'), 's' => attrLabel('S'), '42' => attrLabel('42'),
    ], filterable: true));

    expect($decimal->config)->toBe(['scale' => 3])
        ->and($enum->filterable)->toBeTrue()
        ->and(array_map(static fn ($c) => [$c->code, $c->position], $enum->choices))->toBe([['xl', 0], ['s', 1], ['42', 2]]);
});

it('rejects invalid combinations of type, config, choices and filterable', function (DefineAttribute $command): void {
    expect(fn () => Catalog::attributes()->define($command))->toThrow(InvalidAttributeConfig::class);
})->with([
    'filterable localized_text' => [fn () => new DefineAttribute('t', 'localized_text', attrLabel('T'), filterable: true)],
    'enum without choices' => [fn () => new DefineAttribute('t', 'enum', attrLabel('T'))],
    'text with choices' => [fn () => new DefineAttribute('t', 'text', attrLabel('T'), choices: ['a' => attrLabel('A')])],
    'bad config' => [fn () => new DefineAttribute('t', 'text', attrLabel('T'), ['maxLength' => 0])],
]);

it('rejects an unknown type and a duplicate code', function (): void {
    expect(fn () => Catalog::attributes()->define(new DefineAttribute('x', 'acme.x', attrLabel('X'))))
        ->toThrow(UnsupportedContribution::class);

    Catalog::attributes()->define(new DefineAttribute('dup', 'text', attrLabel('A')));

    expect(fn () => Catalog::attributes()->define(new DefineAttribute('dup', 'integer', attrLabel('B'))))
        ->toThrow(DuplicateCode::class);
    expect(Catalog::attributes()->list(new ListAttributes)->items)->toHaveCount(1);
});

it('validates command fields', function (): void {
    expect(fn () => new DefineAttribute('Bad Code', 'text', attrLabel('X')))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListAttributes(first: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new AddAttributeChoices(new AttributeId(Str::uuid()->toString()), []))->toThrow(InvalidCommand::class);
});

it('finds by id and code and reports a missing attribute', function (): void {
    $view = Catalog::attributes()->define(new DefineAttribute('brand', 'text', attrLabel('Brand')));

    expect(Catalog::attributes()->findByCode('brand')->id->equals($view->id))->toBeTrue()
        ->and(fn () => Catalog::attributes()->findByCode('nope'))->toThrow(AttributeNotFound::class)
        ->and(fn () => Catalog::attributes()->find(AttributeId::generate()))->toThrow(AttributeNotFound::class);
});

it('updates name and filterable but never the type', function (): void {
    $view = Catalog::attributes()->define(new DefineAttribute('weight', 'integer', attrLabel('Weight')));
    $updated = Catalog::attributes()->update(new UpdateAttribute($view->id, name: attrLabel('Mass'), filterable: true));

    expect($updated->name->texts)->toBe(['en' => 'Mass'])
        ->and($updated->filterable)->toBeTrue()
        ->and($updated->type)->toBe('integer')
        ->and($updated->updatedAt >= $view->updatedAt)->toBeTrue();

    $text = Catalog::attributes()->define(new DefineAttribute('story', 'localized_text', attrLabel('Story')));

    expect(fn () => Catalog::attributes()->update(new UpdateAttribute($text->id, filterable: true)))
        ->toThrow(InvalidAttributeConfig::class);
});

it('walks the status lifecycle and refuses illegal transitions', function (): void {
    $view = Catalog::attributes()->define(new DefineAttribute('life', 'text', attrLabel('Life')));
    $set = static fn (AttributeStatus $status) => Catalog::attributes()->update(new UpdateAttribute($view->id, status: $status))->status;

    expect($set(AttributeStatus::Deprecated))->toBe(AttributeStatus::Deprecated)
        ->and($set(AttributeStatus::Active))->toBe(AttributeStatus::Active)
        ->and($set(AttributeStatus::Active))->toBe(AttributeStatus::Active)
        ->and($set(AttributeStatus::Retired))->toBe(AttributeStatus::Retired)
        ->and(fn () => $set(AttributeStatus::Active))->toThrow(InvalidStatusTransition::class)
        ->and(fn () => $set(AttributeStatus::Deprecated))->toThrow(InvalidStatusTransition::class);
});

it('reindexes only when filterable or status change', function (): void {
    $calls = [];
    app()->instance(AttributeIndexer::class, new class($calls) implements AttributeIndexer
    {
        public function __construct(private array &$calls) {}

        public function reindexAttribute(AttributeId $attribute): void
        {
            $this->calls[] = $attribute->value;
        }
    });
    app()->forgetInstance(Attributes::class);
    app()->forgetInstance(Commerz\Catalog\Catalog::class);

    $view = Catalog::attributes()->define(new DefineAttribute('idx', 'text', attrLabel('Idx')));
    Catalog::attributes()->update(new UpdateAttribute($view->id, name: attrLabel('Idx2')));

    expect($calls)->toBe([]);

    app(Attributes::class)->update(new UpdateAttribute($view->id, filterable: true));
    app(Attributes::class)->update(new UpdateAttribute($view->id, status: AttributeStatus::Deprecated));
    app(Attributes::class)->update(new UpdateAttribute($view->id, filterable: true));

    expect($calls)->toBe([$view->id->value, $view->id->value]);
});

it('adds and relabels choices', function (): void {
    $view = Catalog::attributes()->define(new DefineAttribute('color', 'enum', attrLabel('Color'), choices: ['red' => attrLabel('Red')]));
    $added = Catalog::attributes()->addChoices(new AddAttributeChoices($view->id, ['green' => attrLabel('Green'), 'blue' => attrLabel('Blue')]));

    expect(array_map(static fn ($c) => [$c->code, $c->position], $added->choices))->toBe([['red', 0], ['green', 1], ['blue', 2]]);

    $relabelled = Catalog::attributes()->update(new UpdateAttribute($view->id, choiceLabels: ['green' => attrLabel('Lime')]));

    expect($relabelled->choices[1]->label->texts)->toBe(['en' => 'Lime'])
        ->and($relabelled->choices[1]->id->equals($added->choices[1]->id))->toBeTrue()
        ->and(fn () => Catalog::attributes()->addChoices(new AddAttributeChoices($view->id, ['red' => attrLabel('Again')])))
        ->toThrow(DuplicateCode::class)
        ->and(fn () => Catalog::attributes()->update(new UpdateAttribute($view->id, choiceLabels: ['pink' => attrLabel('Pink')])))
        ->toThrow(InvalidAttributeConfig::class);
});

it('refuses choices for a type without them', function (): void {
    $view = Catalog::attributes()->define(new DefineAttribute('note', 'text', attrLabel('Note')));

    expect(fn () => Catalog::attributes()->addChoices(new AddAttributeChoices($view->id, ['a' => attrLabel('A')])))
        ->toThrow(InvalidAttributeConfig::class);
});

it('lists by code with a keyset cursor, a type filter and retired hidden', function (): void {
    foreach (['d', 'b', 'a', 'c'] as $code) {
        Catalog::attributes()->define(new DefineAttribute($code, $code === 'c' ? 'integer' : 'text', attrLabel($code)));
    }

    $c = Catalog::attributes()->findByCode('c');
    Catalog::attributes()->update(new UpdateAttribute($c->id, status: AttributeStatus::Retired));

    $first = Catalog::attributes()->list(new ListAttributes(first: 2));
    $second = Catalog::attributes()->list(new ListAttributes(first: 2, after: $first->next));

    expect(array_map(static fn ($v) => $v->code, $first->items))->toBe(['a', 'b'])
        ->and($first->hasMore())->toBeTrue()
        ->and($first->next)->toBeInstanceOf(Cursor::class)
        ->and(array_map(static fn ($v) => $v->code, $second->items))->toBe(['d'])
        ->and($second->hasMore())->toBeFalse()
        ->and(Catalog::attributes()->list(new ListAttributes(includeRetired: true))->items)->toHaveCount(4)
        ->and(Catalog::attributes()->list(new ListAttributes(type: 'integer'))->items)->toBe([])
        ->and(Catalog::attributes()->list(new ListAttributes(type: 'integer', includeRetired: true))->items)->toHaveCount(1);
});
