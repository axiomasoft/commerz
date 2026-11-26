<?php

declare(strict_types=1);

use Commerz\StoredValue\Panels\StoredValuePanel;

it('describes the stored-value panel descriptor', function (): void {
    $panel = new StoredValuePanel;
    $descriptor = $panel->descriptor();

    expect($descriptor->key)->toBe('commerz.stored_value.accounts')
        ->and($descriptor->owningModule)->toBe('axiomasoft/commerz-stored-value')
        ->and($descriptor->supportedActions)->toContain('issue_gift_card', 'credit_wallet', 'view_ledger');
});
