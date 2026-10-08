<?php

declare(strict_types=1);

use Commerz\Identity\IdempotencyKey;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Outbox\Internal\OutboxRelay;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

// Committed data across processes: no transactional isolation; tables are emptied after each test.

final readonly class FileLogConsumer implements OutboxConsumer
{
    public function __construct(private string $log) {}

    public function key(): string
    {
        return 'test.file_log';
    }

    public function consume(IntegrationEvent $event): void
    {
        file_put_contents($this->log, $event->eventId."\n", FILE_APPEND | LOCK_EX);
    }
}

final class FileLogPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.file-log', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(OutboxConsumer::KIND, FileLogConsumer::class);
    }
}

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
    $this->log = tempnam(sys_get_temp_dir(), 'commerz-log-');
});

afterEach(function (): void {
    DB::statement('truncate cmz_operation_receipts, cmz_outbox_messages');
    @unlink($this->log);
});

/** @return list<string> */
function logLines(string $log): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($log)), fn (string $line): bool => $line !== ''));
}

it('runs keyed work exactly once when processes race on the same key', function (): void {
    $log = $this->log;

    $results = Concurrency::run(4, fn (int $worker): array => app(OperationReceipts::class)->once(
        'sales.place_order',
        IdempotencyKey::of('race'),
        Fingerprint::of(['cart' => 'c1']),
        function () use ($log, $worker): array {
            file_put_contents($log, "{$worker}\n", FILE_APPEND | LOCK_EX);
            usleep(200_000); // hold the claim so the others queue behind it

            return ['order' => "o-{$worker}"];
        },
        fn (array $result): array => $result,
        fn (array $stored): array => $stored,
    ));

    $winner = logLines($log);

    expect($winner)->toHaveCount(1)
        ->and(array_unique(array_map(fn (array $r): string => $r['order'], $results)))->toBe(['o-'.$winner[0]])
        ->and(DB::table('cmz_operation_receipts')->count())->toBe(1);
})->group('concurrency');

it('releases the claim when the work fails, so a retry runs it', function (): void {
    $receipts = app(OperationReceipts::class);
    $key = IdempotencyKey::of('retry');
    $fingerprint = Fingerprint::of('p');
    $identity = fn (array $value): array => $value;

    expect(fn () => $receipts->once('sales.place_order', $key, $fingerprint, fn () => throw new RuntimeException('gateway'), $identity, $identity))
        ->toThrow(RuntimeException::class);

    expect(DB::table('cmz_operation_receipts')->count())->toBe(0)
        ->and($receipts->once('sales.place_order', $key, $fingerprint, fn () => ['ok'], $identity, $identity))->toBe(['ok']);
})->group('concurrency');

it('lets parallel relays share the backlog without delivering an event twice', function (): void {
    $transactions = app(Transactions::class);
    $outbox = app(Outbox::class);
    $transactions->run(function () use ($outbox): void {
        foreach (range(1, 60) as $n) {
            $outbox->record(IntegrationEvent::of('sales.order_placed', "order:{$n}", ['n' => $n]));
        }
    });

    app()->instance(FileLogConsumer::class, new FileLogConsumer($this->log));
    CommerzPlugins::builtIn(FileLogPlugin::class);
    app(Contributions::class); // compiled before the workers fork

    $delivered = Concurrency::run(3, function (): int {
        $total = 0;

        do {
            $report = app(OutboxRelay::class)->relay(7);
            $total += $report->delivered;
        } while ($report->claimed > 0);

        return $total;
    });

    $lines = logLines($this->log);

    expect(array_sum($delivered))->toBe(60)
        ->and($lines)->toHaveCount(60)
        ->and(array_unique($lines))->toHaveCount(60)
        ->and(DB::table('cmz_outbox_messages')->whereNull('delivered_at')->count())->toBe(0)
        ->and(DB::table('cmz_outbox_messages')->where('attempts', '!=', 1)->count())->toBe(0);
})->group('concurrency');

it('reports a failing worker', function (): void {
    expect(fn () => Concurrency::run(2, fn (int $worker) => $worker === 1 ? throw new DomainException('worker broke') : $worker))
        ->toThrow(RuntimeException::class, 'worker 1: DomainException: worker broke');
})->group('concurrency');
