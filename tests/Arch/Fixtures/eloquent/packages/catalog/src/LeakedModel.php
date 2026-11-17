<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Packman\Attributes\Api;
use Illuminate\Database\Eloquent\Model;

#[Api(since: '0.1')]
final class LeakedModel extends Model {}
