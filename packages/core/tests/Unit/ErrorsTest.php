<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Errors\InvalidConfiguration;
use Commerz\Errors\NotFound;
use Commerz\Errors\UnsupportedContribution;

it('exposes stable codes, categories and scalar context', function (CommerzException $e, string $code, ErrorCategory $category, array $context): void {
    expect($e->code())->toBe($code)
        ->and($e->category())->toBe($category)
        ->and($e->context())->toBe($context)
        ->and($e->getMessage())->not->toBe('');
})->with([
    'invalid command' => [new InvalidCommand('amount', 'too big'), 'common.invalid_command', ErrorCategory::Validation, ['field' => 'amount', 'reason' => 'too big']],
    'not found' => [new NotFound('Order', '7'), 'common.not_found', ErrorCategory::NotFound, ['entity' => 'Order', 'id' => '7']],
    'idempotency' => [new IdempotencyConflict('place_order', 'k1'), 'common.idempotency_conflict', ErrorCategory::Conflict, ['operation' => 'place_order', 'key' => 'k1']],
    'configuration' => [new InvalidConfiguration('commerz.stock', 'bad'), 'config.invalid', ErrorCategory::Configuration, ['path' => 'commerz.stock', 'reason' => 'bad']],
    'unsupported' => [new UnsupportedContribution('promo_rule', 'acme.x', 'unknown kind'), 'plugins.unsupported_contribution', ErrorCategory::Unsupported, ['kind' => 'promo_rule', 'key' => 'acme.x', 'reason' => 'unknown kind']],
]);

it('lets domain packages narrow the code', function (): void {
    $e = new NotFound('Product', '1', 'catalog.product_not_found');

    expect($e->code())->toBe('catalog.product_not_found')->and($e)->toBeInstanceOf(CommerzException::class);
});

it('has the documented categories', function (): void {
    expect(array_column(ErrorCategory::cases(), 'value'))
        ->toBe(['validation', 'not_found', 'conflict', 'limit', 'unsupported', 'configuration', 'integration']);
});
