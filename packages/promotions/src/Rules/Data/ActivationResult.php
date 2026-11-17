<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Packman\Attributes\Api;

/**
 * The answer of an activator. The shape is checked here; the engine checks the answer against the request
 * (a promotion that was not a candidate, a code that was not presented).
 */
#[Api(since: '0.1')]
final readonly class ActivationResult
{
    /**
     * @param  list<Activation>  $activations
     * @param  list<CodeRejection>  $rejections
     */
    public function __construct(public array $activations = [], public array $rejections = []) {}
}
