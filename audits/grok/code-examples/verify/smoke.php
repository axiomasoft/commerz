<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';

use Commerz\Money\Data\{Currency, Money};
use Commerz\Context\Data\CommerceContext;
use Commerz\Pricing\Data\{LineFacts, PricedLine, PricingState};
use Commerz\Promotions\Data\{PromotionCandidate, ConfiguredCondition, ConfiguredEffect};
use Commerz\Promotions\Enums\{PromotionStage, StackingMode};
use Commerz\Promotions\Internal\{PromotionEngine, PluginLookup};
use Commerz\Promotions\Contracts\{PromotionCondition, PromotionEffect};
use Commerz\Promotions\Conditions\{CartSubtotalAtLeast, CartSubtotalAtLeastConfig};
use Commerz\Promotions\Effects\{OrderPercentOff, OrderPercentOffConfig};
use Commerz\Coupons\Data\CouponCode;
use Commerz\Catalog\Attributes\Internal\Types\{MeasureAttributeType, MeasureConfig};
use Commerz\Sales\Enums\{PaymentState, OrderState};

function check(bool $ok, string $what): void { echo ($ok ? 'PASS ' : 'FAIL ').$what.PHP_EOL; if (!$ok) exit(1); }

$rub = new Currency('RUB', 2);
// Money::allocate — сумма частей == целое, детерминизм
$parts = (new Money(1000, $rub))->allocate([1, 1, 1]);
check(array_map(fn($m) => $m->amountMinor, $parts) === [334, 333, 333], 'allocate 1000 / [1,1,1] = 334,333,333');
$big = (new Money(PHP_INT_MAX >> 2, $rub))->allocate([999_999_999_999, 1]);
check($big[0]->amountMinor + $big[1]->amountMinor === PHP_INT_MAX >> 2, 'allocate big amounts without int overflow');
check((new Money(999, $rub))->percentage('12.5', \Commerz\Money\Enums\RoundingMode::Down)->amountMinor === 124, '12.5% of 9.99 rounds down to 1.24');

// Engine
$lookup = new class implements PluginLookup {
    public function condition(string $key): PromotionCondition { return new CartSubtotalAtLeast(); }
    public function effect(string $key): PromotionEffect { return new OrderPercentOff(); }
};
$ctx = new CommerceContext('store', 'web', $rub, 'ru', new DateTimeImmutable('2026-10-08'));
$line = fn(string $id, int $unit, int $qty) => new PricedLine($id, new LineFacts("p$id", "v$id", "SKU$id", 'apparel'), $qty, new Money($unit, $rub), Money::zero($rub));
$state = new PricingState($ctx, [$line('a', 3000, 1), $line('b', 7000, 1)]);
$promo = fn(string $code, int $prio, StackingMode $s, string $pct, int $min = 0) => new PromotionCandidate(
    "id-$code", 1, $code, $code, PromotionStage::Cart, $s, $prio,
    [new ConfiguredCondition('cart.subtotal_at_least', CartSubtotalAtLeastConfig::fromArray(['minimum_minor' => ['RUB' => $min]]))],
    [new ConfiguredEffect('order.percent_off', OrderPercentOffConfig::fromArray(['percent' => $pct]))],
);
$engine = new PromotionEngine($lookup);

$r = $engine->run(PromotionStage::Cart, $state, [$promo('B10', 10, StackingMode::Stackable, '10'), $promo('A5', 10, StackingMode::Stackable, '5')]);
// A5 first (same priority, code ASC): 10000 -> 500 off -> 9500; B10: 950 off -> 8550
check($r->itemsTotal()->amountMinor === 8550, 'sequential stacking 5% then 10% = 8550 (14.5% total)');
check(count($r->adjustments) === 4 && $r->adjustments[0]->source === 'promotion:id-A5@1', 'deterministic order: priority DESC, code ASC; per-line adjustments');

$r = $engine->run(PromotionStage::Cart, $state, [$promo('X', 5, StackingMode::Exclusive, '50'), $promo('S', 9, StackingMode::Stackable, '10')]);
check($r->itemsTotal()->amountMinor === 9000 && str_starts_with($r->rejections[0]->reason, 'exclusive_conflict'), 'exclusive rejected after a stackable one, with reason');

$r = $engine->run(PromotionStage::Cart, $state, [$promo('STOP', 9, StackingMode::StopFurther, '10'), $promo('LATE', 1, StackingMode::Stackable, '10')]);
check($r->itemsTotal()->amountMinor === 9000 && $r->rejections[0]->reason === 'stopped_by:STOP', 'stop_further blocks later promotions');

$r = $engine->run(PromotionStage::Cart, $state, [$promo('MIN', 9, StackingMode::Stackable, '10', 20000)]);
check($r->itemsTotal()->amountMinor === 10000 && $r->rejections[0]->reason === 'condition_not_met:subtotal_below_minimum', 'condition explains why not applied');

$r = $engine->run(PromotionStage::Cart, $state, [$promo('ALL', 9, StackingMode::Stackable, '100'), $promo('MORE', 1, StackingMode::Stackable, '10')]);
check($r->itemsTotal()->amountMinor === 0 && $r->rejections[0]->reason === 'nothing_to_discount', 'line never goes below zero');

check(CartSubtotalAtLeastConfig::upgrade(1, ['currency' => 'RUB', 'amount_minor' => 500]) === ['minimum_minor' => ['RUB' => 500]], 'config upgrade v1 -> v2');

// Coupon codes
check(CouponCode::normalize(' summer-2026 ')->value === 'SUMMER2026', 'coupon normalization');
check(CouponCode::tryNormalize('ко') === null, 'cyrillic/short code rejected');
$g = CouponCode::generate('BF', 10)->value;
check(strlen($g) === 12 && strpbrk(substr($g, 2), '01OIL') === false, "generated code $g uses safe alphabet");

// Measure attribute
$t = new MeasureAttributeType();
$cfg = MeasureConfig::fromArray(['unit' => 'W', 'scale' => 0, 'max' => '5000']);
$v = $t->normalize('1.5 kW', $cfg);
check($t->toDocument($v) === ['value' => '1500', 'unit' => 'kW'] && $t->project($v)[0]->num === '1500', 'measure 1.5 kW -> canonical 1500 W');
try { $t->normalize('3 kg', $cfg); check(false, 'dimension'); } catch (\Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue) { check(true, 'wrong dimension rejected'); }

check(PaymentState::derive(1000, 1000, 0, 0) === PaymentState::Authorized && PaymentState::derive(1000, 1000, 1000, 200) === PaymentState::PartiallyRefunded, 'payment state derived from amounts');
check(!OrderState::Cancelled->canTransitionTo(OrderState::Placed), 'terminal order state');
echo "ALL PASS\n";
