<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\StoredValue\GiftCards\Data\GiftCardCode;

it('normalizes gift card codes and hashes them with SHA-256', function (): void {
    $hashA = GiftCardCode::hash('gc-ab12-cd34-ef56');
    $hashB = GiftCardCode::hash('  GCAB12CD34EF56 ');

    expect($hashA)->toBe($hashB)
        ->and($hashA)->toHaveLength(64)
        ->and($hashA)->toBe(hash('sha256', 'GCAB12CD34EF56'))
        ->and(GiftCardCode::last4('gc-ab12-cd34-ef56'))->toBe('EF56');

    $generated = GiftCardCode::generate(16, '23456789ABCDEFGHJKMNPQRSTUVWXYZ', 'GC');

    expect($generated)->toStartWith('GC-')
        ->and(strlen(GiftCardCode::normalize($generated)))->toBe(18);

    expect(fn () => GiftCardCode::normalize('short'))->toThrow(InvalidCommand::class, 'code')
        ->and(fn () => GiftCardCode::normalize('INVALID!CODE!'))->toThrow(InvalidCommand::class, 'code');
});
