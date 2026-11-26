<?php

declare(strict_types=1);

namespace Commerz\Quotes\Internal;

use BackedEnum;
use Commerz\Operations\Fingerprint;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\Rejection;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;
use DateTimeInterface;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Orders the `quote_stage` contributions once per process (the registry is frozen): by anchor, then
 * `Before → At → After`, then key; two `At` stages on one anchor are a configuration error.
 */
#[Internal]
final class StagedQuotePipeline implements QuotePipeline
{
    /** @var list<QuoteStage>|null */
    private ?array $stages = null;

    /** @var array<string, string> stage key => "plugin@version" */
    private array $recipe = [];

    public function __construct(
        private readonly Contributions $contributions,
        private readonly PluginInspector $plugins,
    ) {}

    public function run(QuoteInput $input): QuoteResult
    {
        $stages = $this->stages();
        $fingerprint = Fingerprint::of(['input' => self::normalize($input), 'stages' => $this->recipe]);
        $state = QuoteState::start($input);

        foreach ($stages as $stage) {
            $policy = $stage instanceof QuoteStagePolicy ? $stage->failurePolicy() : FailurePolicy::BlockOnFailure;
            $timeoutMs = $stage instanceof QuoteStagePolicy ? $stage->timeoutMilliseconds() : null;
            $startedAt = hrtime(true);

            try {
                $nextState = $stage->apply($state);
                $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

                if ($timeoutMs !== null && $elapsedMs > $timeoutMs) {
                    throw QuoteFailed::timedOut($stage->key(), $timeoutMs);
                }

                $state = $nextState;
            } catch (QuoteFailed $failed) {
                if ($policy === FailurePolicy::IgnoreOnFailure) {
                    $state = $state->withRejection(new Rejection($stage->key(), $failed->reason, $failed->getMessage()));

                    continue;
                }

                throw $failed;
            } catch (Throwable $error) {
                if ($policy === FailurePolicy::IgnoreOnFailure) {
                    $state = $state->withRejection(new Rejection($stage->key(), 'stage_error', $error->getMessage()));

                    continue;
                }

                throw QuoteFailed::byStage($stage->key(), $error);
            }
        }

        $totals = $state->totals ?? throw new QuoteFailed('totals', 'totals_missing', 'the quote was not settled; a stage after totals changed amounts');

        return new QuoteResult(
            $input->context,
            $state->settledLines,
            array_values(array_filter($state->adjustments, static fn (Adjustment $a): bool => $a->target === AdjustmentTarget::Order)),
            $state->delivery,
            array_values(array_filter($state->adjustments, static fn (Adjustment $a): bool => $a->target === AdjustmentTarget::Delivery)),
            $state->taxLines,
            $totals,
            $state->rejections,
            $input->codes,
            $fingerprint,
            $state->giftOffers,
            $state->nearMissHints,
        );
    }

    /** @return list<QuoteStage> */
    private function stages(): array
    {
        if ($this->stages !== null) {
            return $this->stages;
        }

        $stages = array_map(
            fn (string $key): QuoteStage => $this->contributions->get(QuoteStage::KIND, $key, QuoteStage::class),
            $this->contributions->keys(QuoteStage::KIND),
        );

        usort($stages, static fn (QuoteStage $a, QuoteStage $b): int => [$a->anchor()->order(), $a->position()->order(), $a->key()]
            <=> [$b->anchor()->order(), $b->position()->order(), $b->key()]);

        $owners = [];

        foreach ($stages as $stage) {
            if ($stage instanceof QuoteStagePolicy) {
                $timeoutMs = $stage->timeoutMilliseconds();
                if ($timeoutMs !== null && $timeoutMs <= 0) {
                    throw InvalidConfiguration::fromProblems([
                        "commerz.plugins: quote stage '{$stage->key()}' timeoutMilliseconds() must be a positive integer or null",
                    ]);
                }
            }

            if ($stage->position() !== StagePosition::At) {
                continue;
            }

            $anchor = $stage->anchor()->value;

            if (isset($owners[$anchor])) {
                throw InvalidConfiguration::fromProblems([
                    "commerz.plugins: quote stages '{$owners[$anchor]}' and '{$stage->key()}' both run At anchor '{$anchor}'; only the anchor's owner may",
                ]);
            }

            $owners[$anchor] = $stage->key();
        }

        $versions = [];

        foreach ($this->plugins->describe() as $plugin) {
            foreach ($plugin->contributions as $contribution) {
                if ($contribution->kind === QuoteStage::KIND) {
                    $versions[$contribution->key] = "{$plugin->id}@{$plugin->version}";
                }
            }
        }

        foreach ($stages as $stage) {
            $this->recipe[$stage->key()] = $versions[$stage->key()] ?? 'unknown';
        }

        return $this->stages = $stages;
    }

    /** Public data of the input as plain values for the fingerprint. */
    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format('Y-m-d\TH:i:s.uP'),
            $value instanceof Stringable => (string) $value,
            is_array($value) => array_map(self::normalize(...), $value),
            is_object($value) => array_map(self::normalize(...), get_object_vars($value)),
            default => get_debug_type($value),
        };
    }
}
