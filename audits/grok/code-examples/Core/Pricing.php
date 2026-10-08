<?php

declare(strict_types=1);

// Пакет axiomasoft/commerz, модуль Pricing (контракт конвейера). Реализации стадий живут в pricing/promotions/sales.

namespace Commerz\Pricing\Enums;

use Commerz\Attributes\Api;

/** Фиксированные якоря конвейера. Плагин встаёт before/after якоря, а не «куда-нибудь». */
#[Api(since: '1.0')]
enum PricingAnchor: string
{
    case Base = 'base';
    case CatalogPromotions = 'catalog_promotions';
    case CartPromotions = 'cart_promotions';
    case Shipping = 'shipping';
    case ShippingPromotions = 'shipping_promotions';
    case Tax = 'tax';
    case Totals = 'totals';
}

enum StagePosition: string
{
    case Before = 'before';
    case At = 'at';     // только стадии-владельцы якоря из пакетов commerz
    case After = 'after';
}

namespace Commerz\Pricing\Data;

use Commerz\Attributes\Api;
use Commerz\Context\Data\CommerceContext;
use Commerz\Money\Data\Money;

/** Факты строки для условий/фильтров акций — снимок, собранный каталогом, без ленивых запросов. */
#[Api(since: '1.0')]
final readonly class LineFacts
{
    /**
     * @param list<string> $categoryIds включая предков
     * @param array<string, string> $optionValues option_code => value_code
     */
    public function __construct(
        public string $productId,
        public string $variantId,
        public string $sku,
        public string $productTypeCode,
        public array $categoryIds = [],
        public array $optionValues = [],
    ) {}
}

#[Api(since: '1.0')]
final readonly class PricedLine
{
    public function __construct(
        public string $lineId,
        public LineFacts $facts,
        public int $quantity,
        public Money $unitPrice,      // после каталожных акций
        public Money $discount,       // накопленная скидка корзинных акций на строку
    ) {}

    public function subtotal(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }

    public function current(): Money
    {
        return $this->subtotal()->minus($this->discount);
    }

    public function withExtraDiscount(Money $amount): self
    {
        return new self($this->lineId, $this->facts, $this->quantity, $this->unitPrice, $this->discount->plus($amount));
    }
}

/** Применённая корректировка — то, что потом снимком уходит в cmz_order_adjustments. */
#[Api(since: '1.0')]
final readonly class Adjustment
{
    public function __construct(
        public string $source,        // 'promotion:<uuid>'
        public string $label,
        public ?string $lineId,       // null — уровень заказа/доставки
        public Money $amount,         // положительное = уменьшение цены
        public ?string $code = null,  // введённый код купона, если применимо
    ) {}
}

/** Почему кандидат не применён — для объяснимости (§5 promotions-and-coupons.md). */
#[Api(since: '1.0')]
final readonly class Rejection
{
    public function __construct(public string $source, public string $reason, public ?string $code = null) {}
}

#[Api(since: '1.0')]
final readonly class PricingState
{
    /**
     * @param list<PricedLine> $lines
     * @param list<string> $enteredCodes нормализованные коды из корзины (общие, не только купоны)
     * @param list<Adjustment> $adjustments
     * @param list<Rejection> $rejections
     */
    public function __construct(
        public CommerceContext $context,
        public array $lines,
        public array $enteredCodes = [],
        public array $adjustments = [],
        public array $rejections = [],
    ) {}

    public function itemsTotal(): Money
    {
        return array_reduce(
            $this->lines,
            static fn (Money $sum, PricedLine $line): Money => $sum->plus($line->current()),
            Money::zero($this->context->currency),
        );
    }

    /**

     * @param list<PricedLine> $lines

     * @param list<Adjustment> $adjustments

     * @param list<Rejection> $rejections

     */
    public function with(array $lines, array $adjustments, array $rejections): self
    {
        return new self(
            $this->context,
            $lines,
            $this->enteredCodes,
            [...$this->adjustments, ...$adjustments],
            [...$this->rejections, ...$rejections],
        );
    }
}

namespace Commerz\Pricing\Contracts;

use Commerz\Attributes\Spi;
use Commerz\Attributes\SpiKind;
use Commerz\Pricing\Data\PricingState;
use Commerz\Pricing\Enums\PricingAnchor;
use Commerz\Pricing\Enums\StagePosition;

/** Стадия — чистая функция состояния: без записи в БД, без событий, без времени «сейчас» (берётся из контекста). */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PricingStage
{
    public function key(): string;

    public function anchor(): PricingAnchor;

    public function position(): StagePosition;

    public function apply(PricingState $state): PricingState;
}
