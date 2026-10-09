<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class PromotionNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Promotion', $id, 'promotions.promotion_not_found');
    }
}
