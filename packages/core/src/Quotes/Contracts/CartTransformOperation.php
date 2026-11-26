<?php

declare(strict_types=1);

namespace Commerz\Quotes\Contracts;

use Packman\Attributes\Api;

/**
 * Marker interface for structural cart line transformations produced by a {@see CartTransformRule}
 * during Stage 01 (`cart_transform`) before base pricing runs.
 */
#[Api(since: '0.1')]
interface CartTransformOperation {}
