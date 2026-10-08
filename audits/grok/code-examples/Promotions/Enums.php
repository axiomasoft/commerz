<?php

declare(strict_types=1);

namespace Commerz\Promotions\Enums;

use Commerz\Attributes\Api;

#[Api(since: '1.0')]
enum PromotionStage: string
{
    case Catalog = 'catalog';
    case Cart = 'cart';
    case Shipping = 'shipping';
}

#[Api(since: '1.0')]
enum StackingMode: string
{
    case Stackable = 'stackable';         // складывается с остальными
    case Exclusive = 'exclusive';         // только если до неё в стадии ничего не применено; после — стоп
    case StopFurther = 'stop_further';    // складывается с предыдущими, но после неё — стоп

    public function stopsFurther(): bool
    {
        return $this !== self::Stackable;
    }
}

#[Api(since: '1.0')]
enum PromotionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
    case Unsupported = 'unsupported';     // неизвестный плагин или невалидный конфиг — fail-closed
}

#[Api(since: '1.0')]
enum RejectionReason: string
{
    case StoppedBy = 'stopped_by';
    case ExclusiveConflict = 'exclusive_conflict';
    case ConditionNotMet = 'condition_not_met';
    case NothingToDiscount = 'nothing_to_discount';
    // от активаторов
    case CodeNotFound = 'code_not_found';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
    case CustomerLimit = 'customer_limit';
    case NotForCustomer = 'not_for_customer';
}

#[Api(since: '1.0')]
enum UsageReleasePolicy: string
{
    case OnCancel = 'on_cancel';
    case Never = 'never';
}
