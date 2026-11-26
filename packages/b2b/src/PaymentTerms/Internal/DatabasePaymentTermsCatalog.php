<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Internal;

use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\Internal\B2bCodec;
use Commerz\B2b\PaymentTerms\Contracts\PaymentTermsCatalog;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\PaymentTerms\Enums\PaymentTermsType;
use Commerz\B2b\PaymentTerms\Exceptions\PaymentTermsNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabasePaymentTermsCatalog implements PaymentTermsCatalog
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerz,
        private TableNames $tables,
        private Transactions $transactions,
        private BusinessUnits $businessUnits,
        private Clock $clock,
    ) {}

    #[Override]
    public function save(PaymentTerms $terms, ?BusinessUnitId $businessUnitId = null): PaymentTerms
    {
        return $this->transactions->run(function () use ($terms, $businessUnitId): PaymentTerms {
            if ($businessUnitId !== null) {
                $this->businessUnits->get($businessUnitId);
            }

            $table = $this->tables->table('b2b_payment_terms');
            $now = PgTimestamp::format($this->clock->now());
            $milestonesJson = B2bCodec::encodeJson(PaymentTermsCodec::encodeMilestonesArray($terms->milestones));

            $existing = $this->db()
                ->table($table)
                ->where('code', $terms->code)
                ->first();

            if ($existing !== null) {
                $this->db()
                    ->table($table)
                    ->where('id', Rows::string($existing, 'id'))
                    ->update([
                        'business_unit_id' => $businessUnitId?->value,
                        'type' => $terms->type->value,
                        'net_days' => $terms->netDays,
                        'milestones' => $milestonesJson,
                        'updated_at' => $now,
                    ]);
            } else {
                $this->db()->table($table)->insert([
                    'id' => Uuid7::generate(),
                    'code' => $terms->code,
                    'business_unit_id' => $businessUnitId?->value,
                    'type' => $terms->type->value,
                    'net_days' => $terms->netDays,
                    'milestones' => $milestonesJson,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $this->get($terms->code);
        });
    }

    #[Override]
    public function find(string $code): ?PaymentTerms
    {
        $normalized = strtolower(trim($code));

        $row = $this->db()
            ->table($this->tables->table('b2b_payment_terms'))
            ->where('code', $normalized)
            ->first();

        if ($row !== null) {
            return new PaymentTerms(
                code: Rows::string($row, 'code'),
                type: PaymentTermsType::from(Rows::string($row, 'type')),
                netDays: Rows::int($row, 'net_days'),
                milestones: PaymentTermsCodec::decodeMilestonesArray(B2bCodec::decodeJson(Rows::string($row, 'milestones'))),
            );
        }

        return match ($normalized) {
            'net_15' => PaymentTerms::net15(),
            'net_30' => PaymentTerms::net30(),
            'net_60' => PaymentTerms::net60(),
            default => null,
        };
    }

    #[Override]
    public function get(string $code): PaymentTerms
    {
        return $this->find($code) ?? throw new PaymentTermsNotFound($code);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerz->connection);
    }
}
