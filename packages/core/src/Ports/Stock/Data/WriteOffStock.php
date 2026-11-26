<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/**
 * Records damaged returned units in `cmz_stock_movements` as `write_off` without ever increasing sellable `on_hand`
 * (`INV-INV-002`). Idempotent per `$reference`.
 */
#[Api(since: '0.1')]
final readonly class WriteOffStock
{
    /**
     * @param  string  $reference  the document writing off the damaged stock, e.g. `return:01J…:write_off`
     * @param  list<WriteOffLine>  $lines  one line per sellable
     * @param  string|null  $locationCode  inspecting location; null lets the stock implementation choose
     */
    public function __construct(
        public string $reference,
        public ChannelId $channel,
        public array $lines,
        public ?string $locationCode = null,
        public ?string $reason = null,
        public ?ActorRef $actor = null,
    ) {
        StockLines::assertReference($reference);
        StockLines::assertUnique($lines);
        StockLines::assertLocation($locationCode);

        if ($reason !== null && (trim($reason) === '' || mb_strlen($reason) > 191 || str_contains($reason, "\0") || ! mb_check_encoding($reason, 'UTF-8'))) {
            throw new InvalidCommand('reason', 'must be a non-empty UTF-8 text of at most 191 characters without NUL', 'stock.invalid_reason');
        }
    }
}
