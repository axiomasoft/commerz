<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Commerz\Promotions\Definitions\Data\ArchivePromotion;
use Commerz\Promotions\Definitions\Data\AssignPromotionChannels;
use Commerz\Promotions\Definitions\Data\ListPromotions;
use Commerz\Promotions\Definitions\Data\PausePromotion;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Data\PromotionWindow;
use Commerz\Promotions\Definitions\Data\ResumePromotion;
use Commerz\Promotions\Definitions\Data\UnassignPromotionChannels;
use Commerz\Promotions\Definitions\Data\UpdatePromotion;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Definitions\Events\PromotionChanged;
use Commerz\Promotions\Definitions\Exceptions\PromotionArchived;
use Commerz\Promotions\Definitions\Exceptions\PromotionNotFound;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

it('creates a promotion and derives the currency from the budget and the rules', function (): void {
    $web = pdfChannel('web');
    $pos = pdfChannel('pos');

    $promotion = pdfPromotion('autumn-10', [
        'channels' => [$pos, $web, $web],
        'conditions' => [pdfMinimum()],
        'effects' => [pdfPercent(1000)],
        'priority' => 100,
        'stopFurther' => true,
        'stacking' => Stacking::Exclusive,
        'limits' => new UsageLimits(total: 1000, perCustomer: 1),
        'budget' => Money::of('500000.00', 'RUB'),
        'startsAt' => pdfAt('2026-10-01'),
        'endsAt' => pdfAt('2026-11-01'),
    ]);

    $channels = [$web->value, $pos->value];
    sort($channels);

    expect($promotion->code)->toBe('autumn-10')
        ->and($promotion->name)->toBe('Autumn-10')
        ->and($promotion->stage)->toBe(PromotionStage::Cart)
        ->and($promotion->activator)->toBe('automatic')
        ->and($promotion->stacking)->toBe(Stacking::Exclusive)
        ->and($promotion->stopFurther)->toBeTrue()
        ->and($promotion->priority)->toBe(100)
        ->and($promotion->currency?->code)->toBe('RUB')
        ->and($promotion->status)->toBe(PromotionStatus::Active)
        ->and($promotion->startsAt)->toEqual(pdfAt('2026-10-01'))
        ->and($promotion->endsAt)->toEqual(pdfAt('2026-11-01'))
        ->and(array_map(static fn (ChannelId $channel): string => $channel->value, $promotion->channels))->toBe($channels)
        ->and($promotion->limits->total)->toBe(1000)
        ->and($promotion->limits->perCustomer)->toBe(1)
        ->and($promotion->budget?->equals(Money::of('500000.00', 'RUB')))->toBeTrue()
        ->and($promotion->revision)->toBe(1)
        ->and($promotion->usage->orders)->toBe(0)
        ->and($promotion->usage->discounts)->toBe([])
        ->and($promotion->conditions)->toHaveCount(1)
        ->and($promotion->conditions[0]->key)->toBe('cart.subtotal_at_least')
        ->and($promotion->conditions[0]->params)->toBe(['amount' => ['amount_minor' => 300000, 'currency' => 'RUB'], 'lines' => 'all'])
        ->and($promotion->effects[0]->params)->toBe(['rateBp' => 1000])
        ->and(Promotions::promotions()->find($promotion->id)->code)->toBe('autumn-10')
        ->and(Promotions::promotions()->findByCode('autumn-10')->id->equals($promotion->id))->toBeTrue();
});

it('takes the currency from the rules alone, from the budget alone, or leaves it empty', function (): void {
    $byRule = pdfPromotion('by-rule', ['conditions' => [pdfMinimum('10.00', 'RUB')]]);
    $byBudget = pdfPromotion('by-budget', ['budget' => Money::of('10.00', 'RUB')]);
    $explicit = pdfPromotion('explicit', ['currency' => Currency::of('RUB')]);
    $none = pdfPromotion('none');

    expect($byRule->currency?->code)->toBe('RUB')
        ->and($byBudget->currency?->code)->toBe('RUB')
        ->and($explicit->currency?->code)->toBe('RUB')
        ->and($none->currency)->toBeNull()
        ->and($none->budget)->toBeNull();
});

it('refuses two different currencies among the currency, the budget and the rules', function (): void {
    expect(pdfFailure(fn () => pdfPromotion('a', ['conditions' => [pdfMinimum('10.00', 'USD')], 'budget' => Money::of('10.00', 'RUB')])))
        ->toBe('promotions.currency_mismatch @ currency')
        ->and(pdfFailure(fn () => pdfPromotion('b', ['currency' => Currency::of('RUB'), 'conditions' => [pdfMinimum('10.00', 'USD')]])))
        ->toBe('promotions.currency_mismatch @ currency')
        ->and(pdfFailure(fn () => pdfCommand('c', ['currency' => Currency::of('RUB'), 'budget' => Money::of('10.00', 'USD')])))
        ->toBe('promotions.currency_mismatch @ budget')
        ->and(DB::table('cmz_promotions')->count())->toBe(0);
});

it('refuses an unknown activator, an unknown rule, a rule outside its stage and wrong parameters', function (): void {
    expect(pdfFailure(fn () => pdfPromotion('a', ['activator' => 'acme.missing'])))->toBe('promotions.unknown_activator @ activator')
        ->and(pdfFailure(fn () => pdfPromotion('b', ['effects' => [new RuleSpec('acme.missing')]])))->toBe('promotions.unknown_rule @ effects.0.key')
        ->and(pdfFailure(fn () => pdfPromotion('c', ['stage' => PromotionStage::Delivery])))->toBe('promotions.rule_not_allowed_in_stage @ effects.0.key')
        ->and(pdfFailure(fn () => pdfPromotion('d', ['effects' => [pdfPercent(0)]])))->toBe('promotions.invalid_rule_config @ effects.0.params.rateBp')
        ->and(pdfFailure(fn () => pdfPromotion('e', ['effects' => [new RuleSpec('order.percent_off', ['rateBp' => 1.5])]])))->toBe('promotions.invalid_rule_config @ effects.0.params.rateBp')
        ->and(pdfFailure(fn () => pdfPromotion('f', ['effects' => []])))->toBe('promotions.invalid_rules @ effects')
        ->and(DB::table('cmz_promotions')->count())->toBe(0);
});

it('refuses a channel that does not sell in the currency of the promotion', function (): void {
    $usd = pdfChannel('usd', 'USD');

    expect(fn () => pdfPromotion('a', ['channels' => [$usd], 'budget' => Money::of('10.00', 'RUB')]))->toThrow(CurrencyNotAllowed::class)
        ->and(DB::table('cmz_promotions')->count())->toBe(0);

    $anywhere = pdfPromotion('b', ['channels' => [$usd]]);

    expect($anywhere->currency)->toBeNull()
        ->and(fn () => Promotions::promotions()->assignChannels(new AssignPromotionChannels(pdfPromotion('c', ['budget' => Money::of('10.00', 'RUB')])->id, [$usd])))
        ->toThrow(CurrencyNotAllowed::class);
});

it('rejects a duplicate code and keeps the caller transaction usable', function (): void {
    pdfPromotion('autumn');

    DB::transaction(function (): void {
        expect(fn () => pdfPromotion('autumn'))->toThrow(DuplicateCode::class);
        expect(Promotions::promotions()->list(new ListPromotions)->items)->toHaveCount(1);
    });
});

it('rejects malformed commands before touching the database', function (): void {
    $id = PromotionId::generate();

    expect(pdfFailure(fn () => pdfCommand('Bad Code')))->toBe('promotions.invalid_code @ code')
        ->and(pdfFailure(fn () => pdfCommand('a', ['name' => ' '])))->toBe('promotions.invalid_name @ name')
        ->and(pdfFailure(fn () => pdfCommand('a', ['channels' => []])))->toBe('promotions.invalid_channels @ channels')
        ->and(pdfFailure(fn () => pdfCommand('a', ['status' => PromotionStatus::Archived])))->toBe('promotions.invalid_status @ status')
        ->and(pdfFailure(fn () => pdfCommand('a', ['startsAt' => pdfAt('2026-02-01'), 'endsAt' => pdfAt('2026-01-01')])))->toBe('promotions.invalid_window @ window')
        ->and(pdfFailure(fn () => pdfCommand('a', ['budget' => Money::of('0.00', 'RUB')])))->toBe('promotions.invalid_budget @ budget')
        ->and(pdfFailure(fn () => new UsageLimits(total: 0)))->toBe('promotions.invalid_limits @ limits.total')
        ->and(pdfFailure(fn () => new UsageLimits(perCustomer: -1)))->toBe('promotions.invalid_limits @ limits.perCustomer')
        ->and(pdfFailure(fn () => new UpdatePromotion($id, budget: Money::of('1.00', 'RUB'), clearBudget: true)))->toBe('promotions.invalid_update @ budget')
        ->and(pdfFailure(fn () => new AssignPromotionChannels($id, [])))->toBe('promotions.invalid_channels @ channels')
        ->and(pdfFailure(fn () => new UnassignPromotionChannels($id, [])))->toBe('promotions.invalid_channels @ channels')
        ->and(pdfFailure(fn () => new ListPromotions(first: 201)))->toBe('common.invalid_page_size @ first');
});

it('creates a paused promotion', function (): void {
    expect(pdfPromotion('later', ['status' => PromotionStatus::Paused])->status)->toBe(PromotionStatus::Paused);
});

it('updates the fields given; null leaves a field as it is', function (): void {
    $promotion = pdfPromotion('autumn', ['priority' => 1, 'conditions' => [pdfMinimum()]]);

    $renamed = Promotions::promotions()->update(new UpdatePromotion($promotion->id, name: 'Fall', priority: 9, stacking: Stacking::Exclusive, stopFurther: true));

    expect($renamed->name)->toBe('Fall')
        ->and($renamed->priority)->toBe(9)
        ->and($renamed->stacking)->toBe(Stacking::Exclusive)
        ->and($renamed->stopFurther)->toBeTrue()
        ->and($renamed->conditions)->toEqual($promotion->conditions)
        ->and($renamed->effects)->toEqual($promotion->effects)
        ->and($renamed->revision)->toBe(1);
});

it('replaces the rules as a whole and bumps the revision only when they differ', function (): void {
    $promotion = pdfPromotion('autumn', ['conditions' => [pdfMinimum('3000.00')]]);
    Event::fake([PromotionChanged::class]);

    $same = Promotions::promotions()->update(new UpdatePromotion($promotion->id, conditions: [pdfMinimum('3000.00')], effects: [pdfPercent(1000)]));
    $changed = Promotions::promotions()->update(new UpdatePromotion($promotion->id, conditions: [pdfMinimum('5000.00'), new RuleSpec('cart.quantity_at_least', ['quantity' => 2])], effects: [pdfPercent(1500)]));
    $cleared = Promotions::promotions()->update(new UpdatePromotion($promotion->id, conditions: []));

    expect($same->revision)->toBe(1)
        ->and($same->updatedAt)->toEqual($promotion->updatedAt)
        ->and($changed->revision)->toBe(2)
        ->and(array_map(static fn (RuleSpec $rule): string => $rule->key, $changed->conditions))->toBe(['cart.subtotal_at_least', 'cart.quantity_at_least'])
        ->and($changed->conditions[0]->params['amount'])->toBe(['amount_minor' => 500000, 'currency' => 'RUB'])
        ->and($changed->effects[0]->params)->toBe(['rateBp' => 1500])
        ->and($cleared->revision)->toBe(3)
        ->and($cleared->conditions)->toBe([])
        ->and($cleared->effects[0]->params)->toBe(['rateBp' => 1500])
        ->and(DB::table('cmz_promotion_rules')->count())->toBe(1);
    Event::assertDispatchedTimes(PromotionChanged::class, 2);
});

it('refuses rules that break the promotion on update', function (): void {
    $promotion = pdfPromotion('autumn', ['budget' => Money::of('100.00', 'RUB')]);
    $anywhere = pdfPromotion('anywhere');

    expect(pdfFailure(fn () => Promotions::promotions()->update(new UpdatePromotion($promotion->id, conditions: [new RuleSpec('acme.missing')]))))->toBe('promotions.unknown_rule @ conditions.0.key')
        ->and(pdfFailure(fn () => Promotions::promotions()->update(new UpdatePromotion($promotion->id, effects: [pdfPercent(0)]))))->toBe('promotions.invalid_rule_config @ effects.0.params.rateBp')
        ->and(pdfFailure(fn () => Promotions::promotions()->update(new UpdatePromotion($promotion->id, conditions: [pdfMinimum('10.00', 'USD')]))))->toBe('promotions.currency_mismatch @ conditions')
        ->and(pdfFailure(fn () => Promotions::promotions()->update(new UpdatePromotion($promotion->id, budget: Money::of('10.00', 'USD')))))->toBe('promotions.currency_mismatch @ budget')
        ->and(pdfFailure(fn () => Promotions::promotions()->update(new UpdatePromotion($anywhere->id, budget: Money::of('10.00', 'RUB')))))->toBe('promotions.currency_mismatch @ budget')
        ->and(pdfFailure(fn () => Promotions::promotions()->update(new UpdatePromotion($anywhere->id, conditions: [pdfMinimum()]))))->toBe('promotions.currency_mismatch @ conditions')
        ->and(Promotions::promotions()->find($promotion->id)->revision)->toBe(1);
});

it('changes limits, budget and window and lifts them again', function (): void {
    $promotion = pdfPromotion('autumn', ['budget' => Money::of('100.00', 'RUB'), 'limits' => new UsageLimits(total: 5, perCustomer: 1), 'startsAt' => pdfAt('2026-01-01'), 'endsAt' => pdfAt('2026-02-01')]);

    $limited = Promotions::promotions()->update(new UpdatePromotion($promotion->id, limits: new UsageLimits(total: 2000), budget: Money::of('250.00', 'RUB'), window: new PromotionWindow(pdfAt('2026-03-01'))));

    expect($limited->limits->total)->toBe(2000)
        ->and($limited->limits->perCustomer)->toBeNull()
        ->and($limited->budget?->amountMinor)->toBe(25000)
        ->and($limited->startsAt)->toEqual(pdfAt('2026-03-01'))
        ->and($limited->endsAt)->toBeNull()
        ->and($limited->revision)->toBe(2);

    $lifted = Promotions::promotions()->update(new UpdatePromotion($promotion->id, limits: new UsageLimits, clearBudget: true, window: new PromotionWindow));

    expect($lifted->limits->total)->toBeNull()
        ->and($lifted->budget)->toBeNull()
        ->and($lifted->currency?->code)->toBe('RUB')
        ->and($lifted->startsAt)->toBeNull()
        ->and($lifted->endsAt)->toBeNull()
        ->and($lifted->revision)->toBe(3);

    $same = Promotions::promotions()->update(new UpdatePromotion($promotion->id, limits: new UsageLimits, clearBudget: true, window: new PromotionWindow));

    expect($same->revision)->toBe(3)->and($same->updatedAt)->toEqual($lifted->updatedAt);
});

it('pauses, resumes and archives; repeating a state changes nothing and archived is final', function (): void {
    $promotion = pdfPromotion('autumn');
    Event::fake([PromotionChanged::class]);

    $resumed = Promotions::promotions()->resume(new ResumePromotion($promotion->id));
    $paused = Promotions::promotions()->pause(new PausePromotion($promotion->id));
    Promotions::promotions()->pause(new PausePromotion($promotion->id));
    $active = Promotions::promotions()->resume(new ResumePromotion($promotion->id));
    $archived = Promotions::promotions()->archive(new ArchivePromotion($promotion->id));
    Promotions::promotions()->archive(new ArchivePromotion($promotion->id));

    expect($resumed->status)->toBe(PromotionStatus::Active)
        ->and($paused->status)->toBe(PromotionStatus::Paused)
        ->and($active->status)->toBe(PromotionStatus::Active)
        ->and($archived->status)->toBe(PromotionStatus::Archived)
        ->and(pdfFailure(fn () => Promotions::promotions()->pause(new PausePromotion($promotion->id))))->toBe('promotions.promotion_archived')
        ->and(fn () => Promotions::promotions()->resume(new ResumePromotion($promotion->id)))->toThrow(PromotionArchived::class)
        ->and(fn () => Promotions::promotions()->update(new UpdatePromotion($promotion->id, name: 'X')))->toThrow(PromotionArchived::class)
        ->and(fn () => Promotions::promotions()->assignChannels(new AssignPromotionChannels($promotion->id, [pdfChannel()])))->toThrow(PromotionArchived::class)
        ->and(fn () => Promotions::promotions()->unassignChannels(new UnassignPromotionChannels($promotion->id, [pdfChannel()])))->toThrow(PromotionArchived::class);
    Event::assertDispatchedTimes(PromotionChanged::class, 3);
});

it('archives a paused promotion', function (): void {
    $promotion = pdfPromotion('autumn', ['status' => PromotionStatus::Paused]);

    expect(Promotions::promotions()->archive(new ArchivePromotion($promotion->id))->status)->toBe(PromotionStatus::Archived);
});

it('assigns and unassigns channels, ignores repeats and keeps at least one', function (): void {
    $web = pdfChannel('web');
    $pos = pdfChannel('pos');
    $promotion = pdfPromotion('autumn', ['channels' => [$web]]);
    Event::fake([PromotionChanged::class]);

    $both = Promotions::promotions()->assignChannels(new AssignPromotionChannels($promotion->id, [$web, $pos]));
    Promotions::promotions()->assignChannels(new AssignPromotionChannels($promotion->id, [$web, $pos]));
    $one = Promotions::promotions()->unassignChannels(new UnassignPromotionChannels($promotion->id, [$web, $web]));
    Promotions::promotions()->unassignChannels(new UnassignPromotionChannels($promotion->id, [$web]));

    expect($both->channels)->toHaveCount(2)
        ->and($one->channels)->toHaveCount(1)
        ->and($one->channels[0]->equals($pos))->toBeTrue()
        ->and(pdfFailure(fn () => Promotions::promotions()->unassignChannels(new UnassignPromotionChannels($promotion->id, [$pos]))))->toBe('promotions.no_channels @ channels')
        ->and(Promotions::promotions()->find($promotion->id)->channels)->toHaveCount(1);
    Event::assertDispatchedTimes(PromotionChanged::class, 2);
});

it('lists by code with a cursor and filters by status, stage and channel', function (): void {
    $pos = pdfChannel('pos');
    pdfPromotion('c-promo', ['channels' => [$pos]]);
    pdfPromotion('a-promo');
    $delivery = pdfPromotion('b-promo', ['stage' => PromotionStage::Delivery, 'effects' => [new RuleSpec('delivery.percent_off', ['rateBp' => 5000])]]);
    Promotions::promotions()->pause(new PausePromotion($delivery->id));

    $first = Promotions::promotions()->list(new ListPromotions(first: 2));
    $second = Promotions::promotions()->list(new ListPromotions(first: 2, after: $first->next));
    $codes = static fn ($page): array => array_map(static fn ($promotion): string => $promotion->code, $page->items);

    expect($codes($first))->toBe(['a-promo', 'b-promo'])
        ->and($first->hasMore())->toBeTrue()
        ->and($codes($second))->toBe(['c-promo'])
        ->and($second->hasMore())->toBeFalse()
        ->and($codes(Promotions::promotions()->list(new ListPromotions(status: PromotionStatus::Paused))))->toBe(['b-promo'])
        ->and($codes(Promotions::promotions()->list(new ListPromotions(stage: PromotionStage::Delivery))))->toBe(['b-promo'])
        ->and($codes(Promotions::promotions()->list(new ListPromotions(channel: $pos))))->toBe(['c-promo'])
        ->and($first->items[0]->effects[0]->key)->toBe('order.percent_off')
        ->and($first->items[1]->effects[0]->key)->toBe('delivery.percent_off');
});

it('reports a missing promotion', function (): void {
    $missing = PromotionId::generate();

    expect(fn () => Promotions::promotions()->find($missing))->toThrow(PromotionNotFound::class)
        ->and(fn () => Promotions::promotions()->findByCode('nope'))->toThrow(PromotionNotFound::class)
        ->and(fn () => Promotions::promotions()->pause(new PausePromotion($missing)))->toThrow(PromotionNotFound::class)
        ->and(fn () => Promotions::promotions()->archive(new ArchivePromotion($missing)))->toThrow(PromotionNotFound::class)
        ->and(fn () => Promotions::promotions()->update(new UpdatePromotion($missing, name: 'X')))->toThrow(PromotionNotFound::class)
        ->and(fn () => Promotions::promotions()->assignChannels(new AssignPromotionChannels($missing, [pdfChannel()])))->toThrow(PromotionNotFound::class);
});

it('raises PromotionChanged once after the commit and drops it on rollback', function (): void {
    Event::fake([PromotionChanged::class]);

    $promotion = pdfPromotion('autumn');

    Event::assertDispatchedTimes(PromotionChanged::class, 1);
    Event::assertDispatched(PromotionChanged::class, static fn (PromotionChanged $event): bool => $event->promotion->equals($promotion->id));

    DB::transaction(function () use ($promotion): void {
        Promotions::promotions()->update(new UpdatePromotion($promotion->id, name: 'Fall', priority: 7));
        Promotions::promotions()->pause(new PausePromotion($promotion->id));
        Event::assertDispatchedTimes(PromotionChanged::class, 1);
    });

    Event::assertDispatchedTimes(PromotionChanged::class, 3);

    try {
        DB::transaction(function () use ($promotion): void {
            Promotions::promotions()->update(new UpdatePromotion($promotion->id, priority: 99));

            throw new RuntimeException('host rolls back');
        });
    } catch (RuntimeException) {
    }

    expect(Promotions::promotions()->find($promotion->id)->priority)->toBe(7);
    Event::assertDispatchedTimes(PromotionChanged::class, 3);
});

it('raises no event and writes nothing for a change that changes nothing', function (): void {
    $promotion = pdfPromotion('autumn', ['priority' => 3]);
    Event::fake([PromotionChanged::class]);

    $same = Promotions::promotions()->update(new UpdatePromotion($promotion->id, name: 'Autumn', priority: 3, stacking: Stacking::Stackable, stopFurther: false, effects: [pdfPercent(1000)]));

    expect($same->updatedAt)->toEqual($promotion->updatedAt);
    Event::assertNotDispatched(PromotionChanged::class);
});

it('locks the promotion row for update in every change', function (): void {
    $web = pdfChannel('web');
    $pos = pdfChannel('pos');
    $promotion = pdfPromotion('autumn', ['channels' => [$web]]);
    $id = $promotion->id;
    $commands = [
        'update' => fn () => Promotions::promotions()->update(new UpdatePromotion($id, priority: 5)),
        'update with no change' => fn () => Promotions::promotions()->update(new UpdatePromotion($id)),
        'pause' => fn () => Promotions::promotions()->pause(new PausePromotion($id)),
        'resume' => fn () => Promotions::promotions()->resume(new ResumePromotion($id)),
        'assign' => fn () => Promotions::promotions()->assignChannels(new AssignPromotionChannels($id, [$pos])),
        'unassign' => fn () => Promotions::promotions()->unassignChannels(new UnassignPromotionChannels($id, [$pos])),
        'archive' => fn () => Promotions::promotions()->archive(new ArchivePromotion($id)),
    ];

    foreach ($commands as $name => $command) {
        $queries = pdfQueries($command);
        $locking = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "cmz_promotions"') && str_ends_with($sql, ' for update'));

        expect($locking)->not->toBeEmpty("{$name} must select the promotion for update");
    }
});
