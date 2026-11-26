<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Internal;

use Commerz\B2b\Internal\B2bCodec;
use Commerz\B2b\PaymentTerms\Data\DepositMilestone;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\PaymentTerms\Enums\PaymentTermsType;
use Commerz\Money\Rate;
use Packman\Attributes\Internal;

#[Internal]
final class PaymentTermsCodec
{
    public static function encode(?PaymentTerms $terms): ?string
    {
        if ($terms === null) {
            return null;
        }

        return B2bCodec::encodeJson([
            'code' => $terms->code,
            'type' => $terms->type->value,
            'net_days' => $terms->netDays,
            'milestones' => self::encodeMilestonesArray($terms->milestones),
        ]);
    }

    public static function decode(?string $json): ?PaymentTerms
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = B2bCodec::decodeJson($json);
        $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : null;
        $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : null;
        $netDays = isset($data['net_days']) && is_int($data['net_days']) ? $data['net_days'] : null;

        if ($code === null || $type === null || $netDays === null) {
            return null;
        }

        $milestonesRaw = isset($data['milestones']) && is_array($data['milestones']) ? $data['milestones'] : [];

        return new PaymentTerms(
            code: $code,
            type: PaymentTermsType::from($type),
            netDays: $netDays,
            milestones: self::decodeMilestonesArray($milestonesRaw),
        );
    }

    /**
     * @param  list<DepositMilestone>  $milestones
     * @return list<array{share_bp: int, due_in_days: int, label: string}>
     */
    public static function encodeMilestonesArray(array $milestones): array
    {
        return array_map(
            static fn (DepositMilestone $m): array => [
                'share_bp' => $m->share->basisPoints,
                'due_in_days' => $m->dueInDays,
                'label' => $m->label,
            ],
            $milestones,
        );
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<DepositMilestone>
     */
    public static function decodeMilestonesArray(array $items): array
    {
        $milestones = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $shareBp = isset($item['share_bp']) && is_int($item['share_bp']) ? $item['share_bp'] : null;
            $dueInDays = isset($item['due_in_days']) && is_int($item['due_in_days']) ? $item['due_in_days'] : null;
            $label = isset($item['label']) && is_string($item['label']) ? $item['label'] : null;

            if ($shareBp !== null && $dueInDays !== null && $label !== null) {
                $milestones[] = new DepositMilestone(Rate::bp($shareBp), $dueInDays, $label);
            }
        }

        return $milestones;
    }
}
