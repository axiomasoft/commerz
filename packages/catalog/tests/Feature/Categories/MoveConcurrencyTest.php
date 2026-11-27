<?php

declare(strict_types=1);

use Commerz\Catalog\Categories\Data\CreateCategory;
use Commerz\Catalog\Categories\Data\MoveCategory;
use Commerz\Catalog\Categories\Exceptions\CategoryCycle;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Measures\LocalizedText;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Assert;

// Committed data across processes: no transactional isolation; the category tables are emptied after each test.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    $pending = $this->artisan('migrate');
    if (! $pending instanceof PendingCommand) {
        Assert::fail('Expected a pending Artisan command.');
    }

    $pending->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_category_products, cmz_categories cascade');
});

it('never closes a cycle when two processes move categories under each other', function (): void {
    $label = LocalizedText::of('en', 'X');
    $a = Catalog::categories()->create(new CreateCategory('a', $label));
    $b = Catalog::categories()->create(new CreateCategory('b', $label));
    $moves = [new MoveCategory($a->id, $b->id), new MoveCategory($b->id, $a->id)];

    $outcomes = Concurrency::run(2, function (int $worker) use ($moves): string {
        try {
            Catalog::categories()->move($moves[$worker]);

            return 'moved';
        } catch (CategoryCycle) {
            return 'cycle';
        }
    });

    sort($outcomes);

    // Every walk up from any category ends at a root within as many steps as there are categories.
    /** @var object{depth: int|null, endless: bool}|null $deepest */
    $deepest = DB::selectOne(
        'with recursive up as (select id, parent_id, 0 as depth from cmz_categories'
        .' union all select up.id, c.parent_id, up.depth + 1 from up join cmz_categories c on c.id = up.parent_id where up.depth < 10)'
        .' select max(depth) as depth, bool_or(parent_id is not null and depth >= 10) as endless from up',
    );
    Assert::assertNotNull($deepest);

    expect($outcomes)->toBe(['cycle', 'moved'])
        ->and($deepest->endless)->toBeFalse()
        ->and(DB::table('cmz_categories')->whereNull('parent_id')->count())->toBe(1);
})->group('concurrency');
