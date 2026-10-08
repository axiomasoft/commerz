<?php

declare(strict_types=1);

namespace Commerz\Promotions\Internal;

use Commerz\Context\Data\CommerceContext;
use Commerz\Pricing\Data\Rejection;
use Commerz\Promotions\Data\PromotionCandidate;
use Commerz\Promotions\Enums\PromotionStage;

/**
 * Автоматические акции (status=active, окно дат, канал, группа покупателя) + результаты всех активаторов.
 * Строки с неизвестным плагином/невалидным конфигом сюда не попадают — их статус уже unsupported.
 */
interface CandidateSource
{
    /** @param list<string> $enteredCodes */
    public function forStage(PromotionStage $stage, CommerceContext $context, array $enteredCodes): LoadedCandidates;
}

final readonly class LoadedCandidates
{
    /**
     * @param list<PromotionCandidate> $candidates
     * @param list<Rejection> $rejections
     */
    public function __construct(public array $candidates, public array $rejections) {}
}
