<?php

declare(strict_types=1);

namespace Commerz\Links\Internal;

use Closure;
use Commerz\Errors\InvalidCommand;
use Commerz\Links\Contracts\CrossModuleQuery;
use Commerz\Links\Contracts\LinkRegistry;
use Commerz\Schema\TableNames;
use Illuminate\Database\Query\Builder;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseCrossModuleQuery implements CrossModuleQuery
{
    public function __construct(
        private LinkRegistry $registry,
        private TableNames $tables,
    ) {}

    /**
     * @template TTarget
     *
     * @param  list<string>  $leftIds
     * @param  Closure(list<string>): array<string, TTarget>  $loader
     * @return array<string, list<TTarget>>
     */
    #[Override]
    public function getLinkedForMany(string $linkCode, array $leftIds, Closure $loader): array
    {
        $linksByLeft = $this->registry->linksForMany($linkCode, $leftIds);

        if ($linksByLeft === []) {
            return [];
        }

        $rightIds = [];

        foreach ($linksByLeft as $links) {
            foreach ($links as $link) {
                $rightIds[$link->rightId] = true;
            }
        }

        $targets = $rightIds === [] ? [] : $loader(array_map(strval(...), array_keys($rightIds)));
        $result = [];

        foreach ($linksByLeft as $leftId => $links) {
            $loaded = [];

            foreach ($links as $link) {
                if (array_key_exists($link->rightId, $targets)) {
                    $loaded[] = $targets[$link->rightId];
                }
            }

            $result[$leftId] = $loaded;
        }

        return $result;
    }

    /**
     * @template TSource
     *
     * @param  list<string>  $rightIds
     * @param  Closure(list<string>): array<string, TSource>  $loader
     * @return array<string, list<TSource>>
     */
    #[Override]
    public function getInverseLinkedForMany(string $linkCode, array $rightIds, Closure $loader): array
    {
        $linksByRight = $this->registry->inverseLinksForMany($linkCode, $rightIds);

        if ($linksByRight === []) {
            return [];
        }

        $leftIds = [];

        foreach ($linksByRight as $links) {
            foreach ($links as $link) {
                $leftIds[$link->leftId] = true;
            }
        }

        $sources = $leftIds === [] ? [] : $loader(array_map(strval(...), array_keys($leftIds)));
        $result = [];

        foreach ($linksByRight as $rightId => $links) {
            $loaded = [];

            foreach ($links as $link) {
                if (array_key_exists($link->leftId, $sources)) {
                    $loaded[] = $sources[$link->leftId];
                }
            }

            $result[$rightId] = $loaded;
        }

        return $result;
    }

    /**
     * @param  literal-string  $idColumn
     */
    #[Override]
    public function whereLinked(
        Builder $query,
        string $linkCode,
        string $idColumn,
        ?string $targetId = null,
        bool $inverse = false,
    ): Builder {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?\z/', $idColumn) !== 1) {
            throw new InvalidCommand('idColumn', "'{$idColumn}' is not a valid column identifier", 'links.invalid_column');
        }

        $definition = $this->registry->definition($linkCode);
        $linksTable = $this->tables->table('entity_links');
        $matchSql = ($inverse ? 'cmz_el.right_id' : 'cmz_el.left_id').' = cast('.$idColumn.' as text)';

        return $query->whereExists(static function (Builder $sub) use (
            $linksTable,
            $definition,
            $matchSql,
            $targetId,
            $inverse,
        ): void {
            $sub->selectRaw('1')
                ->from("{$linksTable} as cmz_el")
                ->where('cmz_el.link_code', $definition->code)
                ->where('cmz_el.left_type', $definition->leftType)
                ->where('cmz_el.right_type', $definition->rightType)
                ->whereRaw($matchSql);

            if ($targetId !== null) {
                $sub->where($inverse ? 'cmz_el.left_id' : 'cmz_el.right_id', $targetId);
            }
        });
    }
}
