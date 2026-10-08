<?php

declare(strict_types=1);

namespace Commerz\Ports\Promotions\Internal;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;

/**
 * Default without the promotions package: no promotion can be applied, so there is nothing to count. Usage of an
 * applied promotion fails closed rather than silently skipping its limits.
 */
final readonly class NullPromotionUsage implements PromotionUsage
{
    public function consume(ConsumePromotionUsage $command): void
    {
        if ($command->applied !== []) {
            throw new InvalidConfiguration('commerz', "order '{$command->orderRef}' applied promotions, but no package implements PromotionUsage");
        }
    }

    public function restore(RestorePromotionUsage $command): void {}
}
