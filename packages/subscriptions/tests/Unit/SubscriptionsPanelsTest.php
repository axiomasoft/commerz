<?php

declare(strict_types=1);

use Commerz\Subscriptions\Panels\SubscriptionsPanel;

it('describes the subscriptions panel descriptor', function (): void {
    $descriptor = (new SubscriptionsPanel)->descriptor();

    expect($descriptor->key)->toBe('commerz.subscriptions.contracts')
        ->and($descriptor->owningModule)->toBe('axiomasoft/commerz-subscriptions')
        ->and($descriptor->supportedActions)->toContain('pause', 'resume', 'cancel', 'retry_billing_cycle');
});
