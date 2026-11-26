<?php

declare(strict_types=1);

namespace Commerz\Quotes\Contracts;

use Commerz\Quotes\Enums\FailurePolicy;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Optional resilience policy implemented by a {@see QuoteStage} or custom pipeline interceptor.
 * Stages that do not implement this interface default to {@see FailurePolicy::BlockOnFailure} with no timeout.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface QuoteStagePolicy
{
    public function failurePolicy(): FailurePolicy;

    /**
     * Maximum allowed execution time in milliseconds before the stage is considered timed out.
     * Null disables timeout enforcement.
     */
    public function timeoutMilliseconds(): ?int;
}
