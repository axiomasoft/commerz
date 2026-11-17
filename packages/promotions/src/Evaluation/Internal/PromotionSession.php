<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Promotions\Definitions\Data\PromotionVerdict;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Quotes\Data\Rejection;
use Packman\Attributes\Internal;

/**
 * What the three promotion stages of one quote run share: the candidates loaded once, the rejections of presented
 * codes, and the verdicts recorded so far (for explain). It holds no reference to the quote input, so the session map
 * never keeps an input alive.
 */
#[Internal]
final class PromotionSession
{
    /** @var list<PromotionVerdict> */
    private array $verdicts = [];

    /**
     * @param  array<string, list<CandidatePromotion>>  $candidates  by stage value, each in priority DESC, code ASC order
     * @param  list<Rejection>  $codeRejections
     */
    public function __construct(
        private readonly array $candidates,
        public readonly array $codeRejections,
    ) {}

    /** @return list<CandidatePromotion> the promotions of the stage that get a verdict, in the order they are tried */
    public function candidates(PromotionStage $stage): array
    {
        return array_values(array_filter(
            $this->candidates[$stage->value] ?? [],
            static fn (CandidatePromotion $candidate): bool => $candidate->isTried(),
        ));
    }

    public function record(PromotionVerdict $verdict): void
    {
        $this->verdicts[] = $verdict;
    }

    /** @return list<PromotionVerdict> */
    public function verdicts(): array
    {
        return $this->verdicts;
    }
}
