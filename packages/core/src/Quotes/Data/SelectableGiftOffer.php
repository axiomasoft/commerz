<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Packman\Attributes\Api;

/**
 * A selectable gift offer unlocked by a promotion: the storefront lets the buyer pick up to `$maxSelections`
 * sellables from `$sellableRefs`, each granted at `$quantityPerSelection` units free of charge.
 */
#[Api(since: '0.1')]
final readonly class SelectableGiftOffer
{
    /**
     * @param  list<SellableRef>  $sellableRefs
     */
    public function __construct(
        public string $promotionId,
        public string $promotionCode,
        public string $title,
        public array $sellableRefs,
        public int $maxSelections = 1,
        public int $quantityPerSelection = 1,
        public ?string $couponCode = null,
    ) {
        if (trim($promotionId) === '') {
            throw new InvalidCommand('promotionId', 'must not be empty', 'quotes.invalid_gift_offer');
        }

        if (trim($promotionCode) === '') {
            throw new InvalidCommand('promotionCode', 'must not be empty', 'quotes.invalid_gift_offer');
        }

        if (trim($title) === '') {
            throw new InvalidCommand('title', 'must not be empty', 'quotes.invalid_gift_offer');
        }

        if ($sellableRefs === []) {
            throw new InvalidCommand('sellableRefs', 'must contain at least one sellable', 'quotes.invalid_gift_offer');
        }

        if ($maxSelections < 1) {
            throw new InvalidCommand('maxSelections', 'must be at least 1', 'quotes.invalid_gift_offer');
        }

        if ($quantityPerSelection < 1) {
            throw new InvalidCommand('quantityPerSelection', 'must be at least 1', 'quotes.invalid_gift_offer');
        }
    }
}
