<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Effects;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Promotions\Rules\Contracts\GiftPromotionEffect;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\GiftEffectResult;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\SelectableGiftOffer;
use Packman\Attributes\Internal;

/**
 * Built-in `gift.auto_add_line` effect: either automatically appends free gift line(s) (`mode = 'auto'`) to the
 * quote, or emits a {@see SelectableGiftOffer} (`mode = 'selectable'`) into `QuoteResult::$giftOffers`.
 */
#[Internal]
final readonly class AutoAddGiftLine implements GiftPromotionEffect
{
    private const string SELLABLE_PATTERN = '/\A[a-z][a-z0-9_.-]{0,31}:[^\s]{1,158}\z/';

    public function key(): string
    {
        return 'gift.auto_add_line';
    }

    public function version(): int
    {
        return 1;
    }

    public function stages(): array
    {
        return [PromotionStage::Cart];
    }

    public function normalize(RuleParams $params, RuleScope $scope): RuleParams
    {
        $params->only(['sellables', 'quantity', 'mode', 'maxSelections', 'title', 'sku']);
        $raw = $params->toArray();

        $sellables = array_values(array_unique($params->stringList('sellables', 1, 20, self::SELLABLE_PATTERN)));
        $quantity = array_key_exists('quantity', $raw) ? $params->int('quantity', 1, 100) : 1;
        $mode = $params->choice('mode', ['auto', 'selectable'], count($sellables) === 1 ? 'auto' : 'selectable');
        $maxSelections = array_key_exists('maxSelections', $raw) ? $params->int('maxSelections', 1, 20) : 1;

        if ($mode === 'selectable' && $maxSelections > count($sellables)) {
            throw new InvalidRuleConfig('maxSelections', 'must not exceed the number of gift sellables');
        }

        $title = null;

        if (array_key_exists('title', $raw) && $raw['title'] !== null) {
            $title = trim($params->string('title'));

            if ($title === '' || strlen($title) > 255) {
                throw new InvalidRuleConfig('title', 'must be 1 to 255 bytes');
            }
        }

        $sku = null;

        if (array_key_exists('sku', $raw) && $raw['sku'] !== null) {
            $sku = trim($params->string('sku'));

            if ($sku === '' || strlen($sku) > 64) {
                throw new InvalidRuleConfig('sku', 'must be 1 to 64 bytes');
            }
        }

        return RuleParams::fromInput([
            'maxSelections' => $maxSelections,
            'mode' => $mode,
            'quantity' => $quantity,
            'sellables' => $sellables,
            'sku' => $sku,
            'title' => $title,
        ]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function apply(EffectInput $input): array
    {
        return [];
    }

    public function applyGifts(EffectInput $input, ?string $couponCode = null): GiftEffectResult
    {
        $sellableStrings = $input->params->stringList('sellables', 1, 20, self::SELLABLE_PATTERN);
        $quantity = $input->params->int('quantity', 1, 100);
        $mode = $input->params->choice('mode', ['auto', 'selectable'], 'auto');
        $maxSelections = $input->params->int('maxSelections', 1, 20);
        $raw = $input->params->toArray();
        $title = is_string($raw['title'] ?? null) && trim($raw['title']) !== '' ? $raw['title'] : $input->promotion->name;
        $skuOverride = is_string($raw['sku'] ?? null) && trim($raw['sku']) !== '' ? $raw['sku'] : null;

        $refs = array_map(
            static function (string $ref): SellableRef {
                try {
                    return SellableRef::fromString($ref);
                } catch (InvalidCommand $e) {
                    throw new InvalidRuleConfig('sellables', $e->getMessage());
                }
            },
            $sellableStrings,
        );

        if ($mode === 'selectable') {
            return new GiftEffectResult(
                giftOffers: [
                    new SelectableGiftOffer(
                        promotionId: $input->promotion->id->value,
                        promotionCode: $input->promotion->code,
                        title: $title,
                        sellableRefs: $refs,
                        maxSelections: min($maxSelections, count($refs)),
                        quantityPerSelection: $quantity,
                        couponCode: $couponCode,
                    ),
                ],
            );
        }

        $currency = $input->state->currency();
        $zero = Money::zero($currency);
        $lines = [];

        foreach ($refs as $index => $ref) {
            $sku = $skuOverride !== null && count($refs) === 1
                ? $skuOverride
                : 'GIFT-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9_-]/', '', $ref->id) ?? $ref->id, 0, 48));

            $snapshot = new SellableSnapshot(
                ref: $ref,
                sku: $sku === 'GIFT-' ? 'GIFT-'.($index + 1) : $sku,
                name: $title,
                kind: SellableKind::Physical,
                requiresShipping: true,
                purchasable: true,
                basePrice: $zero,
            );

            $lines[] = new QuoteLine(
                lineId: "gift:{$input->promotion->code}:{$index}",
                sellable: $snapshot,
                quantity: $quantity,
                unitPrice: $zero,
                compareAt: null,
                taxIncluded: false,
                priceSource: 'promotions.gift',
                isGift: true,
                giftPromotionId: $input->promotion->id->value,
            );
        }

        return new GiftEffectResult(giftLines: $lines);
    }
}
