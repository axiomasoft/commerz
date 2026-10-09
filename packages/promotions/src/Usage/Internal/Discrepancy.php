<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Internal;

/** One disagreement `commerz:promotions:reconcile` found. */
final readonly class Discrepancy
{
    public const string PROMOTION_USED = 'promotion_used';

    public const string PROMOTION_SPENT = 'promotion_spent';

    public const string COUPON_USED = 'coupon_used';

    public const string CUSTOMER_USED = 'customer_used';

    public const string UNSUPPORTED_ACTIVATOR = 'unsupported_activator';

    public const string UNSUPPORTED_RULE = 'unsupported_rule';

    public function __construct(
        public string $kind,
        public string $ref,
        public string $expected,
        public string $actual,
    ) {}
}
