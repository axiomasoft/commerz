<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Promotions\Rules\Data\ActivationRequest;
use Commerz\Promotions\Rules\Data\ActivationResult;

/**
 * Decides which promotions of the activator are switched on for a quote: `automatic` always, `coupon` by a presented
 * code. The engine knows neither word; a plugin can bring its own way (a loyalty tier, a referral link).
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface PromotionActivator
{
    public const string KIND = 'promotion_activator';

    /** Contribution key, e.g. `automatic`, `coupon` or `acme.referral`. */
    public function key(): string;

    /** Called once per quote for all the candidates of this activator. May read the database, never writes. */
    public function activate(ActivationRequest $request): ActivationResult;
}
