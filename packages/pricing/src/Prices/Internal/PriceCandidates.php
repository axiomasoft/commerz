<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Money\Currency;
use Commerz\Pricing\Common\Internal\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The prices of the given sellables in the active price lists assigned to the channel, in the currency: one query for
 * a whole call, however many sellables it asks about. Applicability (group, quantity, window) is `PriceSelection`'s.
 */
final readonly class PriceCandidates
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
    ) {}

    /**
     * @param  list<string>  $sellables  sellable references
     * @return list<ListedPrice>
     */
    public function load(array $sellables, ChannelId $channel, Currency $currency): array
    {
        if ($sellables === []) {
            return [];
        }

        $rows = $this->db()->table($this->tables->table('prices').' as p')
            ->join($this->tables->table('price_lists').' as l', 'l.id', '=', 'p.price_list_id')
            ->join($this->tables->table('price_list_channels').' as c', 'c.price_list_id', '=', 'l.id')
            ->where('c.channel_id', $channel->value)
            ->where('l.status', 'active')
            ->where('l.currency', $currency->code)
            ->whereIn('p.sellable_ref', array_values(array_unique($sellables)))
            ->orderBy('p.id')
            ->get([
                'p.id', 'p.sellable_ref', 'p.customer_group', 'p.min_quantity', 'p.amount_minor', 'p.compare_at_minor',
                'p.valid_from', 'p.valid_until', 'l.code as list_code', 'l.priority', 'l.tax_included',
            ]);

        return array_values(array_map(static function (object $row): ListedPrice {
            $from = Rows::nullableString($row, 'valid_from');
            $until = Rows::nullableString($row, 'valid_until');

            return new ListedPrice(
                Rows::string($row, 'id'),
                Rows::string($row, 'sellable_ref'),
                Rows::string($row, 'list_code'),
                Rows::int($row, 'priority'),
                Rows::nullableBool($row, 'tax_included'),
                Rows::nullableString($row, 'customer_group'),
                Rows::int($row, 'min_quantity'),
                Rows::int($row, 'amount_minor'),
                Rows::nullableInt($row, 'compare_at_minor'),
                $from === null ? null : new DateTimeImmutable($from),
                $until === null ? null : new DateTimeImmutable($until),
            );
        }, $rows->all()));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
