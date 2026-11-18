<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Errors\CommerzProblemDefinitions;
use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Errors\NotFound;
use Commerz\Money\CurrencyMismatch;
use Commerz\Money\InvalidCurrency;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Commerz\Stores\Exceptions\DuplicateCode;
use Commerz\Stores\Exceptions\StoreNotFound;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

it('exposes stable codes, categories, descriptors and scalar context', function (CommerzException $e, string $code, ErrorCategory $category, CommerzErrorCode $packmanCode, ErrorCategory $packmanCategory, array $context): void {
    $descriptor = $e->errorDescriptor();

    expect($e)->toBeInstanceOf(CodedException::class)
        ->and($e->code())->toBe($code)
        ->and($e->category())->toBe($category)
        ->and($e->context())->toBe($context)
        ->and($e->getMessage())->not->toBe('')
        ->and($descriptor->errorCode)->toBe($packmanCode)
        ->and($descriptor->category)->toBe($packmanCategory);
})->with([
    'invalid command' => [new InvalidCommand('amount', 'too big'), 'common.invalid_command', ErrorCategory::Validation, CommerzErrorCode::InvalidCommand, ErrorCategory::Validation, ['field' => 'amount', 'reason' => 'too big']],
    'not found' => [new NotFound('Order', '7'), 'common.not_found', ErrorCategory::NotFound, CommerzErrorCode::NotFound, ErrorCategory::NotFound, ['entity' => 'Order', 'id' => '7']],
    'idempotency' => [new IdempotencyConflict('place_order', 'k1'), 'common.idempotency_conflict', ErrorCategory::Conflict, CommerzErrorCode::IdempotencyConflict, ErrorCategory::Conflict, ['operation' => 'place_order', 'key' => 'k1']],
    'currency mismatch' => [new CurrencyMismatch('RUB', 'USD'), 'money.currency_mismatch', ErrorCategory::Validation, CommerzErrorCode::CurrencyMismatch, ErrorCategory::Validation, ['field' => 'currency', 'reason' => 'RUB and USD cannot be combined']],
    'invalid currency' => [new InvalidCurrency('XXX'), 'money.invalid_currency', ErrorCategory::Validation, CommerzErrorCode::InvalidCurrency, ErrorCategory::Validation, ['field' => 'currency', 'reason' => "'XXX' is not a known ISO 4217 code"]],
    'quote failed' => [new QuoteFailed('tax', 'stage_error', 'boom'), 'quotes.failed', ErrorCategory::Conflict, CommerzErrorCode::QuoteFailed, ErrorCategory::Conflict, ['stage' => 'tax', 'reason' => 'stage_error', 'cause' => null]],
    'channel archived' => [new ChannelArchived('c1'), 'stores.channel_archived', ErrorCategory::Conflict, CommerzErrorCode::ChannelArchived, ErrorCategory::Conflict, ['channel' => 'c1']],
    'channel not found' => [new ChannelNotFound('c1'), 'stores.channel_not_found', ErrorCategory::NotFound, CommerzErrorCode::ChannelNotFound, ErrorCategory::NotFound, ['entity' => 'Channel', 'id' => 'c1']],
    'currency not allowed' => [new CurrencyNotAllowed('c1', 'USD'), 'stores.currency_not_allowed', ErrorCategory::Validation, CommerzErrorCode::CurrencyNotAllowed, ErrorCategory::Validation, ['channel' => 'c1', 'currency' => 'USD']],
    'duplicate code' => [new DuplicateCode('Store', 'main'), 'stores.duplicate_code', ErrorCategory::Conflict, CommerzErrorCode::DuplicateCode, ErrorCategory::Conflict, ['entity' => 'Store', 'code' => 'main']],
    'store not found' => [new StoreNotFound('s1'), 'stores.store_not_found', ErrorCategory::NotFound, CommerzErrorCode::StoreNotFound, ErrorCategory::NotFound, ['entity' => 'Store', 'id' => 's1']],
    'price not found' => [new PriceNotFound('variant:1', 'RUB'), 'prices.price_not_found', ErrorCategory::NotFound, CommerzErrorCode::PriceNotFound, ErrorCategory::NotFound, ['entity' => 'Price', 'id' => 'variant:1 in RUB']],
    'usage limit' => [new UsageLimitReached('p1', null, 'total'), 'promotions.usage_limit_reached', ErrorCategory::RateLimit, CommerzErrorCode::UsageLimitReached, ErrorCategory::RateLimit, ['promotion' => 'p1', 'coupon' => null, 'limit' => 'total']],
    'sellable unavailable' => [new SellableUnavailable('variant:1'), 'sellables.unavailable', ErrorCategory::NotFound, CommerzErrorCode::SellableUnavailable, ErrorCategory::NotFound, ['sellable' => 'variant:1', 'reason' => 'unknown']],
    'insufficient stock' => [new InsufficientStock('variant:1', 5, 2), 'stock.insufficient_stock', ErrorCategory::Conflict, CommerzErrorCode::InsufficientStock, ErrorCategory::Conflict, ['sellable' => 'variant:1', 'requested' => 5, 'available' => 2]],
    'reservation not found' => [new ReservationNotFound('order:1', 'variant:1'), 'stock.reservation_not_found', ErrorCategory::NotFound, CommerzErrorCode::ReservationNotFound, ErrorCategory::NotFound, ['entity' => 'Reservation', 'id' => 'order:1/variant:1']],
]);

it('covers every CommerzErrorCode case with a matching ProblemDefinition', function (): void {
    $definitions = (new CommerzProblemDefinitions)->definitions();
    $codes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $expected = array_column(CommerzErrorCode::cases(), 'value');

    sort($codes);
    sort($expected);

    expect($codes)->toBe($expected)
        ->and(new UnsupportedContribution('promo_rule', 'acme.x', 'unknown kind')->errorDescriptor()->category)->toBe(ErrorCategory::Unsupported);
});

it('lets domain packages narrow the code', function (): void {
    $e = new NotFound('Product', '1', 'catalog.product_not_found');

    expect($e->code())->toBe('catalog.product_not_found')
        ->and($e)->toBeInstanceOf(CommerzException::class)
        ->and($e->errorDescriptor()->errorCode)->toBe(CommerzErrorCode::NotFound);
});

it('uses the shared Packman categories', function (): void {
    expect(array_column(ErrorCategory::cases(), 'value'))
        ->toBe(['authentication', 'authorization', 'validation', 'conflict', 'not_found', 'rate_limit', 'unsupported', 'unavailable', 'internal']);
});
