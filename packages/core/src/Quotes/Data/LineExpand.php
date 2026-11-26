<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Quotes\Contracts\CartTransformOperation;
use Packman\Attributes\Api;

/**
 * Expands a single bundle/kit line into its constituent component lines (`quantity × quantityPerUnit`).
 */
#[Api(since: '0.1')]
final readonly class LineExpand implements CartTransformOperation
{
    /** @var list<ExpandedComponent> */
    public array $components;

    /**
     * @param  list<ExpandedComponent>  $components
     */
    public function __construct(
        public string $lineId,
        array $components,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'quotes.invalid_transform');
        }

        if ($components === []) {
            throw new InvalidCommand('components', 'must contain at least one component', 'quotes.invalid_transform');
        }

        $explicitIds = [];

        foreach ($components as $component) {
            if ($component->lineId !== null) {
                if (isset($explicitIds[$component->lineId])) {
                    throw new InvalidCommand('components', "duplicate component lineId '{$component->lineId}'", 'quotes.invalid_transform');
                }

                $explicitIds[$component->lineId] = true;
            }
        }

        $this->components = $components;
    }
}
