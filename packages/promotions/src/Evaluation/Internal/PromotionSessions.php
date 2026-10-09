<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Quotes\Data\QuoteInput;
use Illuminate\Contracts\Container\Container;
use WeakMap;

/**
 * One session per quote input object, so the three promotion stages of a run read one set of promotions, activations
 * and counters. The catalog stage, the first of them, always starts a new session: running the same input again sees
 * fresh data. The map is weak: a session lives as long as its input and never keeps it alive (long-running workers).
 * Bound as a singleton; the loader is resolved per load, so a configuration or binding changed later is seen.
 */
final class PromotionSessions
{
    /** @var WeakMap<QuoteInput, PromotionSession> */
    private WeakMap $sessions;

    public function __construct(private readonly Container $container)
    {
        $this->sessions = new WeakMap;
    }

    public function start(QuoteInput $input): PromotionSession
    {
        return $this->sessions[$input] = $this->container->make(ActivePromotions::class)->load($input->context, $input->codes);
    }

    public function for(QuoteInput $input): PromotionSession
    {
        return $this->sessions[$input] ?? $this->start($input);
    }

    /** The session of the input's last run, if there is one; never starts one. */
    public function find(QuoteInput $input): ?PromotionSession
    {
        return $this->sessions[$input] ?? null;
    }

    public function forget(QuoteInput $input): void
    {
        unset($this->sessions[$input]);
    }
}
