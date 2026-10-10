# Error codes

Every package error is a subclass of `Commerz\Errors\CommerzException`. It has a stable machine **`code()`** of the form `<module>.<snake_reason>`, a **`category()`** and a scalar **`context()`**. The human message is for logs and may change; match on the code.

| Category | Meaning |
|---|---|
| `validation` | a command or value was built with a malformed field |
| `not_found` | the entity does not exist |
| `conflict` | the request contradicts the current state or an earlier request |
| `limit` | a configured or business limit was reached |
| `unsupported` | a contribution (plugin) the data needs is not installed |
| `configuration` | the installation is misconfigured |
| `integration` | an external system (gateway, carrier) refused or did not answer |

HTTP rendering is the host's job; map categories to your transport once. Codes are part of the SemVer surface: a code is not renamed or reused in a minor release.

The tables list the exception classes. Validation errors raised by constructors of commands carry codes of the form `<module>.invalid_*`; they are listed [below](#validation-codes).


## Core

| Code | Class | Category | Meaning |
|---|---|---|---|
| (set by the caller) | `InvalidCommand` | validation | A command, query or value object was built with a malformed field. Subclasses narrow the code. |
| (set by the caller) | `NotFound` | not_found | A referenced entity does not exist. Domain packages subclass it with their own code. |
| `common.idempotency_conflict` | `IdempotencyConflict` | conflict | The same idempotency key was reused for a different request payload. |
| `config.invalid` | `InvalidConfiguration` | configuration | A configuration value is wrong; `path` is the dotted key, e.g. `commerz.table_prefix`. |
| `plugins.unsupported_contribution` | `UnsupportedContribution` | unsupported | A plugin contributed a kind or key the installed packages do not support. |
| `prices.price_not_found` | `PriceNotFound` | not_found | No price applies to the sellable in this context and currency. |
| `promotions.usage_limit_reached` | `UsageLimitReached` | limit | A usage limit or budget is exhausted; `$limit` names which: `total`, `per_customer`, `coupon` or `budget`. |
| `quotes.failed` | `QuoteFailed` | conflict | The quote cannot be computed. `$stage` is the stage that failed; `$reason` is `stage_error` when the stage threw (the original exception is the previous one, its code in `context()['cause']`), or a totals check such as `negative_total`, `negative_line`, `negative_delivery`, `delivery_without_charge`, `unpriced_line` or `totals_missing`. |
| `sellables.unavailable` | `SellableUnavailable` | not_found | The provider of the ref type does not know the ref (deleted, never existed, or hidden from the channel). |
| `stock.insufficient_stock` | `InsufficientStock` | conflict | A reservation would take more than is free; nothing of the command was reserved. |
| `stock.reservation_not_found` | `ReservationNotFound` | not_found | The demand holds no (or not enough) reserved stock of the sellable. |
| `stores.channel_archived` | `ChannelArchived` | conflict | An archived channel was used where an active one is required (a new context, a channel update). |
| `stores.channel_not_found` | `ChannelNotFound` | not_found |  |
| `stores.currency_not_allowed` | `CurrencyNotAllowed` | validation | A currency is outside the channel's currency list (including a default currency that is not in it). |
| `stores.duplicate_code` | `DuplicateCode` | conflict | A store or channel code is already taken in this installation. |
| `stores.store_not_found` | `StoreNotFound` | not_found |  |

## Catalog

| Code | Class | Category | Meaning |
|---|---|---|---|
| `catalog.attribute_not_allowed` | `AttributeNotAllowed` | validation | The product type has no slot for the attribute on this level (product or variant). |
| `catalog.attribute_not_filterable` | `AttributeNotFilterable` | validation | A filter names an attribute that is not filterable or is retired. |
| `catalog.attribute_not_found` | `AttributeNotFound` | not_found |  |
| `catalog.attribute_not_writable` | `AttributeNotWritable` | conflict | The attribute is deprecated or retired: existing values stay, new ones are refused. |
| `catalog.category_archived` | `CategoryArchived` | conflict | The category is archived: it takes no products, no children and no changes. |
| `catalog.category_cycle` | `CategoryCycle` | conflict | The new parent is the category itself or one of its descendants: the move would close a cycle. |
| `catalog.category_has_children` | `CategoryHasChildren` | conflict | A category with active children cannot be archived; move or archive the children first. |
| `catalog.category_not_found` | `CategoryNotFound` | not_found |  |
| `catalog.duplicate_code` | `DuplicateCode` | conflict | A catalog code, handle or SKU is already taken in this installation. |
| `catalog.duplicate_combination` | `DuplicateCombination` | conflict | The product already has a variant (active or archived) with this combination of axis values (CAT-01). |
| `catalog.invalid_attribute_config` | `InvalidAttributeConfig` | validation | An attribute definition config (or a combination of config, choices and filterable) is not valid for its type. |
| `catalog.invalid_attribute_value` | `InvalidAttributeValue` | validation | A value does not fit the attribute's type or config. |
| `catalog.invalid_axes` | `InvalidAxes` | validation | The axes of a command do not match the product: an axis is missing or extra, or a value code is unknown. |
| `catalog.invalid_product_type` | `InvalidProductType` | validation | The slots or axes of a product type are inconsistent. |
| `catalog.invalid_status_transition` | `InvalidStatusTransition` | conflict | A status change the entity's lifecycle does not allow. |
| `catalog.listing_not_found` | `ListingNotFound` | not_found | The product was never published in the channel. |
| `catalog.missing_required_attributes` | `MissingRequiredAttributes` | validation | Required attributes of the product type are missing at creation, or a command tried to remove them. |
| `catalog.option_not_found` | `OptionNotFound` | not_found |  |
| `catalog.product_archived` | `ProductArchived` | conflict | The product is archived and takes no further changes. |
| `catalog.product_not_found` | `ProductNotFound` | not_found |  |
| `catalog.product_type_archived` | `ProductTypeArchived` | conflict | The product type is archived and takes no new products or slots. |
| `catalog.product_type_not_found` | `ProductTypeNotFound` | not_found |  |
| `catalog.too_many_variants` | `TooManyVariants` | limit | A generation plans more combinations than `commerz-catalog.max_generated_variants` allows; nothing was written. |
| `catalog.unknown_option_value` | `UnknownOptionValue` | validation | A value code that does not belong to the option. |
| `catalog.variant_not_found` | `VariantNotFound` | not_found |  |

## Inventory

| Code | Class | Category | Meaning |
|---|---|---|---|
| `inventory.duplicate_code` | `DuplicateCode` | conflict | A location code or SKU is already taken in this installation. |
| `inventory.invalid_allocation_plan` | `InvalidAllocationPlan` | integration | An allocation strategy proposed a plan the sources cannot honour; the whole command is rolled back. |
| `inventory.invalid_transfer_state` | `InvalidTransferState` | conflict | The command does not apply to the transfer's state: a transfer is dispatched once, received only in transit. |
| `inventory.location_archived` | `LocationArchived` | conflict | The location is archived: restore it before changing it or placing stock there. |
| `inventory.location_not_empty` | `LocationNotEmpty` | conflict | A location that still holds or reserves stock, or has a draft or in-transit transfer, cannot be archived. |
| `inventory.location_not_found` | `LocationNotFound` | not_found |  |
| `inventory.negative_stock` | `NegativeStock` | conflict | On-hand never goes below zero: the change would take more units than the level holds. |
| `inventory.reservation_conflict` | `ReservationConflict` | conflict | The demand already holds stock for another channel; a demand belongs to one channel. |
| `inventory.stale_stock_count` | `StaleStockCount` | conflict | On-hand moved since the count started; recount from the current quantity instead of overwriting it. |
| `inventory.stock_item_archived` | `StockItemArchived` | conflict | The item is archived: restore it before changing it, linking it or placing stock. |
| `inventory.stock_item_not_empty` | `StockItemNotEmpty` | conflict | A stock item that holds, reserves or has unresolved goods in a transfer cannot be archived. |
| `inventory.stock_item_not_found` | `StockItemNotFound` | not_found |  |
| `inventory.stock_link_missing` | `StockLinkMissing` | not_found | The sellable is linked to no stock item, and `commerz-inventory.unlinked_sellables` is `unavailable`. |
| `inventory.too_many_components` | `TooManyComponents` | conflict | A sellable (kit) is made of at most {@see MAX} stock items. |
| `inventory.transfer_not_found` | `TransferNotFound` | not_found |  |
| `inventory.untracked_stock_item` | `UntrackedStockItem` | conflict | An untracked item keeps no levels: stock cannot be placed on it, and the policy cannot change to it while stock is held, reserved or unresolved in a transfer. |

## Pricing

| Code | Class | Category | Meaning |
|---|---|---|---|
| `pricing.duplicate_code` | `DuplicateCode` | conflict | A price list, tax class or tax zone code is already taken in this installation. |
| `pricing.invalid_tax_result` | `InvalidTaxResult` | integration | A tax calculator answered with something the quote cannot use. |
| `pricing.price_list_archived` | `PriceListArchived` | conflict | The price list is archived: restore it before changing it or setting prices in it. |
| `pricing.price_list_not_found` | `PriceListNotFound` | not_found |  |
| `pricing.price_not_found` | `PriceNotFoundById` | not_found | No price row has this id. Not the core's `prices.price_not_found`: that one says no price applies to a sellable. |
| `pricing.price_window_overlap` | `PriceWindowOverlap` | conflict | The window overlaps another window of the same price key (list, sellable, group, quantity step) (PRC-01). |
| `pricing.tax_class_not_found` | `TaxClassNotFound` | not_found |  |
| `pricing.tax_rate_not_found` | `TaxRateNotFound` | not_found | Either no rate of a tax class applies in a zone at the moment of a calculation (fail-closed: a missing rate is a configuration error, never a silent zero; a zero rate is set explicitly), or no rate has an id. |
| `pricing.tax_rate_overlap` | `TaxRateOverlap` | conflict | The window overlaps another window of the same rate (tax class, zone, tax code) (TAX-01). |
| `pricing.tax_zone_member_taken` | `TaxZoneMemberTaken` | conflict | A country, or a region of it, already belongs to another tax zone (TAX-01). |
| `pricing.tax_zone_not_found` | `TaxZoneNotFound` | not_found |  |

## Promotions

| Code | Class | Category | Meaning |
|---|---|---|---|
| `promotions.code_space_exhausted` | `CodeSpaceExhausted` | conflict | Generation kept meeting codes that already exist: the pattern is used up. Nothing was created. |
| `promotions.coupon_not_found` | `CouponNotFound` | not_found |  |
| `promotions.duplicate_code` | `DuplicateCode` | conflict | A promotion code or a coupon code is already taken in this installation. |
| `promotions.invalid_rule_config` | `InvalidRuleConfig` | validation | The parameters of a rule are wrong; `field` is the parameter, e.g. `min` or `amount`. |
| `promotions.invalid_rule_result` | `InvalidRuleResult` | integration | A condition, effect or activator answered with something the engine cannot trust. |
| `promotions.not_a_coupon_promotion` | `NotACouponPromotion` | conflict | Coupons belong to promotions of the `coupon` activator; this promotion is switched on another way. |
| `promotions.promotion_archived` | `PromotionArchived` | conflict | The promotion is archived, which is final: it can no longer be changed, paused or resumed. |
| `promotions.promotion_not_found` | `PromotionNotFound` | not_found |  |
| `promotions.promotion_unavailable` | `PromotionUnavailable` | conflict | A promotion the quote applied can no longer be used when the order is placed: `$reason` is `promotion_not_found`, `promotion_not_active`, `coupon_not_found` or `coupon_disabled`. The placement rolls back; quote again. |

## Shipping

| Code | Class | Category | Meaning |
|---|---|---|---|
| `shipping.booking_in_progress` | `BookingInProgress` | conflict | Another process is booking the same reference right now; repeat later to get its outcome. |
| `shipping.booking_inside_transaction` | `BookingInsideTransaction` | configuration | A shipment was booked inside a database transaction, which could roll back after the carrier created it (SHP-04). |
| `shipping.booking_not_found` | `BookingNotFound` | not_found |  |
| `shipping.booking_outcome_unknown` | `BookingOutcomeUnknown` | integration | The carrier call failed in a way that does not tell whether the shipment was created (a timeout, a broken connection); `getPrevious()` is the original error. Booking the same reference again asks the carrier first. |
| `shipping.carrier_mismatch` | `CarrierMismatch` | validation | The shipment names a carrier other than the one of its delivery method. |
| `shipping.carrier_rejected` | `CarrierRejected` | integration | The carrier refused the shipment for good and certainly created nothing; `reason` is snake_case. |
| `shipping.delivery_unavailable` | `DeliveryUnavailable` | conflict | The selected delivery method cannot deliver this quote; `$verdict` is the explain verdict that says why. The delivery stage fails the quote with it (`quotes.failed`, reason `method_unavailable`) instead of quoting no delivery. |
| `shipping.duplicate_code` | `DuplicateCode` | conflict | A zone code or a method code is already taken in this installation. |
| `shipping.invalid_carrier_result` | `InvalidCarrierResult` | integration | A carrier answered with something the package cannot trust, e.g. a booking by another carrier or without tracking. |
| `shipping.invalid_rate_config` | `InvalidRateConfig` | validation | The parameters of a rate are wrong; `field` is the parameter, e.g. `amount` or `steps.1.up_to_grams`. |
| `shipping.invalid_rate_result` | `InvalidRateResult` | integration | A delivery rate answered with something the package cannot trust, e.g. a price in another currency. |
| `shipping.method_archived` | `MethodArchived` | conflict | The method is archived, which is final: it can no longer be changed, enabled or disabled. |
| `shipping.method_not_found` | `MethodNotFound` | not_found |  |
| `shipping.zone_archived` | `ZoneArchived` | conflict | The zone is archived: restore it before changing it. |
| `shipping.zone_not_found` | `ZoneNotFound` | not_found |  |

## Sales

| Code | Class | Category | Meaning |
|---|---|---|---|
| `sales.cart_already_placed` | `CartAlreadyPlaced` | conflict | The cart was already placed; `$order` is the order it became (ORD-01). |
| `sales.cart_changed` | `CartChanged` | conflict | The cart changed between its quote and the placement; quote it again. |
| `sales.cart_empty` | `CartEmpty` | validation | An order needs at least one line. |
| `sales.cart_line_not_found` | `CartLineNotFound` | not_found |  |
| `sales.cart_not_found` | `CartNotFound` | not_found |  |
| `sales.cart_not_open` | `CartNotOpen` | conflict | The cart was converted into an order and is no longer edited. |
| `sales.delivery_required` | `DeliveryRequired` | validation | The cart has lines that ship and no priced delivery method (`commerz-sales.checkout.require_delivery`). |
| `sales.duplicate_order_number` | `DuplicateOrderNumber` | conflict | The order number generator returned a number the store already has; nothing was placed. |
| `sales.fulfillment_already_shipped` | `FulfillmentAlreadyShipped` | conflict | The fulfillment already left with another carrier or tracking number; a shipment is never overwritten. |
| `sales.fulfillment_not_bookable` | `FulfillmentNotBookable` | conflict | The fulfillment has nothing to book with a carrier. |
| `sales.fulfillment_not_found` | `FulfillmentNotFound` | not_found |  |
| `sales.fulfillment_not_shipped` | `FulfillmentNotShipped` | conflict | Only a shipped fulfillment can be delivered. |
| `sales.invalid_gateway_result` | `InvalidGatewayResult` | integration | A gateway answered with something that breaks its contract; the outcome of the attempt is treated as unknown. |
| `sales.invalid_order_number` | `InvalidOrderNumber` | configuration | A generator produced a number that is not 1..64 characters of `A-Za-z0-9-` with at least one letter or digit: a configuration defect, the order is not placed. |
| `sales.invalid_payment_event` | `InvalidPaymentEvent` | validation | A provider event is malformed or does not fit its payment (another currency, more than the payment); it is not recorded. |
| `sales.line_not_found` | `LineNotFound` | not_found | The order has no line with this id. |
| `sales.operation_inside_transaction` | `OperationInsideTransaction` | configuration | The operation calls a payment gateway or a carrier and must run outside any database transaction (PAY-02): a rollback after the network call would forget a payment or a booking the provider already made. |
| `sales.order_not_cancellable` | `OrderNotCancellable` | conflict | The order cannot be cancelled: it is completed or cancelled, or (for a whole cancellation) partly fulfilled. |
| `sales.order_not_found` | `OrderNotFound` | not_found |  |
| `sales.order_not_fulfillable` | `OrderNotFulfillable` | conflict | A cancelled or completed order takes no new fulfillment. |
| `sales.order_not_payable` | `OrderNotPayable` | conflict | A cancelled or completed order takes no new payment. |
| `sales.payment_declined` | `PaymentDeclined` | conflict | The gateway refused for good and certainly did nothing: the attempt becomes `failed`. Any other exception from a gateway leaves the outcome unknown. |
| `sales.payment_exceeds_balance` | `PaymentExceedsBalance` | conflict | The order owes less than asked: its balance is the amount due less every payment that is not failed or cancelled (captured ones by what they took). Nothing is left to pay when another attempt already covers the order. |
| `sales.payment_in_progress` | `PaymentInProgress` | conflict | Another process is asking the gateway about this payment under a live lease; try again after it. |
| `sales.payment_not_found` | `PaymentNotFound` | not_found |  |
| `sales.payment_outcome_unknown` | `PaymentOutcomeUnknown` | integration | The gateway did not answer for certain (a timeout, a broken connection): the payment may exist at the provider. It is `unknown`, never `failed`; `Payments::reconcile` asks the gateway later (PAY-02). |
| `sales.quantity_exceeds_outstanding` | `QuantityExceedsOutstanding` | conflict | The line has fewer units left for the operation than asked (ORD-03): units neither cancelled nor fulfilled for a cancellation or a fulfillment, fulfilled units not yet returned for a return. |
| `sales.quote_changed` | `QuoteChanged` | conflict | The total of the cart is not the one the buyer accepted; quote it again and show the new total. |
| `sales.refund_exceeds_balance` | `RefundExceedsBalance` | conflict | A payment holds less than asked: what can still be refunded is what it captured less every refund of it that has not failed. Without a named payment, `$refundable` is the most any captured payment of the order still holds. |
| `sales.refund_in_progress` | `RefundInProgress` | conflict | Another process is asking the gateway for this refund under a live lease; try again after it. |
| `sales.refund_not_found` | `RefundNotFound` | not_found |  |
| `sales.refund_outcome_unknown` | `RefundOutcomeUnknown` | integration | The gateway did not answer for certain (a timeout, a broken connection): the money may be on its way back. The refund is `unknown`, never `failed`, and keeps its amount from being refunded again; `Refunds::reconcile` asks the gateway later (PAY-02). |
| `sales.restock_exceeds_received` | `RestockExceedsReceived` | conflict | More units were to go back on hand than the return holds for the line (REF-01). |
| `sales.return_exceeds_fulfilled` | `ReturnExceedsFulfilled` | conflict | The line has fewer fulfilled units left to return (fulfilled − returned − in open returns) than asked; none for a cancelled order. |
| `sales.return_not_found` | `ReturnNotFound` | not_found |  |
| `sales.return_not_open` | `ReturnNotOpen` | conflict | A received return cannot be cancelled, nor a cancelled one received. |
| `sales.sellable_not_purchasable` | `SellableNotPurchasable` | validation | The sellable exists but its owner does not sell it now (CTX-01); `$reason` is the owner's own. |
| `sales.shipping_address_required` | `ShippingAddressRequired` | validation | Delivery is priced or offered for a destination, and the cart has no shipping address yet. |
| `sales.too_many_cart_lines` | `TooManyCartLines` | validation | The cart would hold more lines than `commerz-sales.carts.max_lines`. |

## Validation codes

Raised as `InvalidCommand` (category `validation`); `context()` holds `field` and `reason`. Constructors of commands, queries and value objects throw them before any work starts.

**catalog** — `catalog.invalid_attributes`, `catalog.invalid_barcode`, `catalog.invalid_choices`, `catalog.invalid_code`, `catalog.invalid_dimensions`, `catalog.invalid_filter`, `catalog.invalid_handle`, `catalog.invalid_option_values`, `catalog.invalid_sku`, `catalog.invalid_sku_pattern`, `catalog.invalid_window`, `catalog.too_many_axes`, `catalog.unsupported_filter`

**common** — `common.invalid_command`, `common.invalid_cursor`, `common.invalid_page_size`, `common.not_found`

**inventory** — `inventory.invalid_allocation`, `inventory.invalid_code`, `inventory.invalid_filter`, `inventory.invalid_limit`, `inventory.invalid_priority`, `inventory.invalid_quantity`, `inventory.invalid_reason`, `inventory.invalid_reference`, `inventory.invalid_sku`, `inventory.invalid_transfer`, `inventory.no_stock_source`

**outbox** — `outbox.invalid_event`

**prices** — `prices.invalid_price`, `prices.invalid_quantity`

**pricing** — `pricing.currency_mismatch`, `pricing.duplicate_member`, `pricing.duplicate_price`, `pricing.invalid_amount`, `pricing.invalid_batch`, `pricing.invalid_channels`, `pricing.invalid_code`, `pricing.invalid_compare_at`, `pricing.invalid_group`, `pricing.invalid_member`, `pricing.invalid_name`, `pricing.invalid_priority`, `pricing.invalid_quantity`, `pricing.invalid_rate`, `pricing.invalid_tax_amount`, `pricing.invalid_tax_code`, `pricing.invalid_tax_item`, `pricing.invalid_tax_request`, `pricing.invalid_window`

**promotions** — `promotions.currency_mismatch`, `promotions.invalid_activation`, `promotions.invalid_budget`, `promotions.invalid_channels`, `promotions.invalid_code`, `promotions.invalid_condition_outcome`, `promotions.invalid_count`, `promotions.invalid_coupon_code`, `promotions.invalid_discount`, `promotions.invalid_group`, `promotions.invalid_limits`, `promotions.invalid_name`, `promotions.invalid_pattern`, `promotions.invalid_priority`, `promotions.invalid_rule`, `promotions.invalid_rules`, `promotions.invalid_status`, `promotions.invalid_unit_pool`, `promotions.invalid_update`, `promotions.invalid_usage`, `promotions.invalid_verdict`, `promotions.invalid_window`, `promotions.no_channels`, `promotions.pattern_too_small`, `promotions.rule_not_allowed_in_stage`, `promotions.unknown_activator`, `promotions.unknown_rule`

**quotes** — `quotes.currency_mismatch`, `quotes.inconsistent_promotion`, `quotes.invalid_adjustment`, `quotes.invalid_code`, `quotes.invalid_delivery`, `quotes.invalid_line`, `quotes.invalid_rejection`, `quotes.invalid_tax`, `quotes.negative_total`, `quotes.unknown_line`

**sales** — `sales.currency_mismatch`, `sales.invalid_code`, `sales.invalid_delivery_method`, `sales.invalid_fulfillment`, `sales.invalid_gateway_payment`, `sales.invalid_gateway_refund`, `sales.invalid_group`, `sales.invalid_limit`, `sales.invalid_lines`, `sales.invalid_payment`, `sales.invalid_quantity`, `sales.invalid_refund`, `sales.invalid_return`

**sellables** — `sellables.invalid_snapshot`

**shipping** — `shipping.currency_mismatch`, `shipping.duplicate_rate`, `shipping.duplicate_zone_member`, `shipping.invalid_booking`, `shipping.invalid_channels`, `shipping.invalid_code`, `shipping.invalid_eta`, `shipping.invalid_free_above`, `shipping.invalid_name`, `shipping.invalid_position`, `shipping.invalid_rate`, `shipping.invalid_rate_price`, `shipping.invalid_status`, `shipping.invalid_zone_member`, `shipping.too_many_rates`, `shipping.too_many_zone_members`, `shipping.unknown_carrier`, `shipping.unknown_rate`

**stock** — `stock.invalid_demand`, `stock.invalid_lines`, `stock.invalid_location`, `stock.invalid_quantity`, `stock.invalid_reference`

**stores** — `stores.invalid_code`, `stores.invalid_currencies`, `stores.invalid_locale`, `stores.invalid_name`, `stores.invalid_prefix`

