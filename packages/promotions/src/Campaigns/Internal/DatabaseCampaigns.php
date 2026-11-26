<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Promotions\Campaigns\Contracts\Campaigns;
use Commerz\Promotions\Campaigns\Data\ArchiveCampaign;
use Commerz\Promotions\Campaigns\Data\Campaign;
use Commerz\Promotions\Campaigns\Data\CampaignBudget;
use Commerz\Promotions\Campaigns\Data\CampaignId;
use Commerz\Promotions\Campaigns\Data\ConsumeCampaignBudget;
use Commerz\Promotions\Campaigns\Data\CreateCampaign;
use Commerz\Promotions\Campaigns\Data\ListCampaigns;
use Commerz\Promotions\Campaigns\Data\PauseCampaign;
use Commerz\Promotions\Campaigns\Data\RestoreCampaignBudget;
use Commerz\Promotions\Campaigns\Data\ResumeCampaign;
use Commerz\Promotions\Campaigns\Data\UpdateCampaign;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use Commerz\Promotions\Campaigns\Events\CampaignChanged;
use Commerz\Promotions\Campaigns\Exceptions\CampaignArchived;
use Commerz\Promotions\Campaigns\Exceptions\CampaignNotFound;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;

/**
 * Campaign persistence and lifecycle. Mutations lock the campaign row `FOR UPDATE` (waiting for any active
 * `FOR KEY SHARE` budget consumers and blocking new ones) before updating caps and recomputing counters.
 */
#[Internal]
final readonly class DatabaseCampaigns implements Campaigns
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private CampaignBudgetLedger $ledger,
        private Clock $clock,
    ) {}

    public function create(CreateCampaign $command): Campaign
    {
        return $this->transactions->run(function () use ($command): Campaign {
            $currency = $command->currency ?? $command->spendCap?->currency;
            $db = $this->db();
            $id = Uuid7::generate();
            $now = PgTimestamp::now($this->clock);

            try {
                $db->transaction(fn () => $db->table($this->tables->table('campaigns'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'status' => $command->status->value,
                    'starts_at' => self::timestamp($command->startsAt),
                    'ends_at' => self::timestamp($command->endsAt),
                    'currency' => $currency?->code,
                    'spend_cap_minor' => $command->spendCap?->amountMinor,
                    'usage_cap' => $command->usageCap,
                    'spent_minor' => 0,
                    'used' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Campaign', $command->code);
            }

            $campaignId = new CampaignId($id);
            $this->events->dispatch(new CampaignChanged($campaignId));

            return $this->find($campaignId);
        });
    }

    public function update(UpdateCampaign $command): Campaign
    {
        return $this->transactions->run(function () use ($command): Campaign {
            $id = $command->campaign->value;
            $row = $this->lockActive($command->campaign);
            $currencyCode = Rows::nullableString($row, 'currency');
            $currency = $currencyCode === null ? null : Currency::of($currencyCode);

            $changes = [];
            $recompute = false;

            if ($command->name !== null && $command->name !== Rows::string($row, 'name')) {
                $changes['name'] = $command->name;
            }

            if ($command->window !== null
                && (! self::sameInstant($command->window->startsAt, self::date($row, 'starts_at'))
                    || ! self::sameInstant($command->window->endsAt, self::date($row, 'ends_at')))) {
                $changes['starts_at'] = self::timestamp($command->window->startsAt);
                $changes['ends_at'] = self::timestamp($command->window->endsAt);
            }

            $currentSpendCap = Rows::nullableInt($row, 'spend_cap_minor');

            if ($command->clearSpendCap && $currentSpendCap !== null) {
                $changes['spend_cap_minor'] = null;
                $recompute = true;
            }

            if ($command->spendCap instanceof Money) {
                if (! $currency instanceof Currency || ! $command->spendCap->currency->equals($currency)) {
                    throw new InvalidCommand(
                        'spendCap',
                        $currency instanceof Currency ? "must be in {$currency->code}" : 'a campaign without a currency has no spend cap',
                        'promotions.currency_mismatch',
                    );
                }

                if ($command->spendCap->amountMinor !== $currentSpendCap) {
                    $changes['spend_cap_minor'] = $command->spendCap->amountMinor;
                    $recompute = true;
                }
            }

            $currentUsageCap = Rows::nullableInt($row, 'usage_cap');

            if ($command->clearUsageCap && $currentUsageCap !== null) {
                $changes['usage_cap'] = null;
                $recompute = true;
            }

            if ($command->usageCap !== null && $command->usageCap !== $currentUsageCap) {
                $changes['usage_cap'] = $command->usageCap;
                $recompute = true;
            }

            if ($changes !== []) {
                $changes['updated_at'] = PgTimestamp::now($this->clock);
                $this->db()->table($this->tables->table('campaigns'))->where('id', $id)->update($changes);

                if ($recompute) {
                    $this->ledger->recompute($id);
                }

                $this->events->dispatch(new CampaignChanged($command->campaign));
            }

            return $this->find($command->campaign);
        });
    }

    public function pause(PauseCampaign $command): Campaign
    {
        return $this->transition($command->campaign, CampaignStatus::Paused);
    }

    public function resume(ResumeCampaign $command): Campaign
    {
        return $this->transition($command->campaign, CampaignStatus::Active);
    }

    public function archive(ArchiveCampaign $command): Campaign
    {
        return $this->transition($command->campaign, CampaignStatus::Archived);
    }

    public function find(CampaignId $id): Campaign
    {
        $row = $this->db()->table($this->tables->table('campaigns'))->where('id', $id->value)->first();

        return $this->toView($row ?? throw new CampaignNotFound($id->value));
    }

    public function findByCode(string $code): Campaign
    {
        $row = $this->db()->table($this->tables->table('campaigns'))->where('code', $code)->first();

        return $this->toView($row ?? throw new CampaignNotFound($code));
    }

    public function list(ListCampaigns $query): Page
    {
        $builder = $this->db()->table($this->tables->table('campaigns'))
            ->orderBy('code')
            ->limit($query->first + 1);

        if ($query->status instanceof CampaignStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $items = array_map($this->toView(...), array_slice($rows, 0, $query->first));

        return new Page($items, $more && $items !== [] ? Cursor::after($items[array_key_last($items)]->code) : null);
    }

    public function budget(CampaignId $id): CampaignBudget
    {
        $row = $this->db()->table($this->tables->table('campaigns'))->where('id', $id->value)->first();

        return CampaignBudgetLedger::budgetFromRow($row ?? throw new CampaignNotFound($id->value));
    }

    public function consumeBudget(ConsumeCampaignBudget $command): CampaignBudget
    {
        return $this->transactions->run(function () use ($command): CampaignBudget {
            $this->ledger->consumeMany(
                [$command->campaign->value => ['discountMinor' => $command->discount->amountMinor, 'uses' => $command->uses]],
                $command->discount->currency,
            );

            return $this->budget($command->campaign);
        });
    }

    public function restoreBudget(RestoreCampaignBudget $command): CampaignBudget
    {
        return $this->transactions->run(function () use ($command): CampaignBudget {
            $this->find($command->campaign);
            $this->ledger->restoreMany([
                $command->campaign->value => ['discountMinor' => $command->discount->amountMinor, 'uses' => $command->uses],
            ]);

            return $this->budget($command->campaign);
        });
    }

    private function transition(CampaignId $id, CampaignStatus $target): Campaign
    {
        return $this->transactions->run(function () use ($id, $target): Campaign {
            $row = $this->lockRow($id);
            $current = CampaignStatus::from(Rows::string($row, 'status'));

            if ($current === $target) {
                return $this->find($id);
            }

            if ($current === CampaignStatus::Archived) {
                throw new CampaignArchived(Rows::string($row, 'code'));
            }

            $this->db()->table($this->tables->table('campaigns'))
                ->where('id', $id->value)
                ->update([
                    'status' => $target->value,
                    'updated_at' => PgTimestamp::now($this->clock),
                ]);

            $this->events->dispatch(new CampaignChanged($id));

            return $this->find($id);
        });
    }

    private function lockRow(CampaignId $id): object
    {
        $query = $this->db()->table($this->tables->table('campaigns'))->where('id', $id->value);
        $this->lock($query, 'for update');
        $row = $query->first();

        return $row ?? throw new CampaignNotFound($id->value);
    }

    private function lockActive(CampaignId $id): object
    {
        $row = $this->lockRow($id);

        if (Rows::string($row, 'status') === CampaignStatus::Archived->value) {
            throw new CampaignArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    private function toView(object $row): Campaign
    {
        $currency = Rows::nullableString($row, 'currency');

        return new Campaign(
            id: new CampaignId(Rows::string($row, 'id')),
            code: Rows::string($row, 'code'),
            name: Rows::string($row, 'name'),
            status: CampaignStatus::from(Rows::string($row, 'status')),
            currency: $currency === null ? null : Currency::of($currency),
            budget: CampaignBudgetLedger::budgetFromRow($row),
            startsAt: self::date($row, 'starts_at'),
            endsAt: self::date($row, 'ends_at'),
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private function lock(Builder $query, string $mode): void
    {
        $db = $this->db();

        if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
            $query->lock($mode);
        }
    }

    private static function date(object $row, string $column): ?DateTimeImmutable
    {
        $value = Rows::nullableString($row, $column);

        return $value === null ? null : new DateTimeImmutable($value);
    }

    private static function sameInstant(?DateTimeImmutable $a, ?DateTimeImmutable $b): bool
    {
        return $a instanceof DateTimeImmutable && $b instanceof DateTimeImmutable
            ? $a->format('U.u') === $b->format('U.u')
            : $a === $b;
    }

    private static function timestamp(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
