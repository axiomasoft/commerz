<?php

declare(strict_types=1);

namespace Commerz\Links\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Errors\NotFound;
use Commerz\Identity\Uuid7;
use Commerz\Links\Contracts\LinkRegistry;
use Commerz\Links\Data\EntityLink;
use Commerz\Links\Data\LinkDefinition;
use Commerz\Links\Enums\CascadeUnlinkPolicy;
use Commerz\Links\Enums\LinkCardinality;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final class DatabaseLinkRegistry implements LinkRegistry
{
    /** @var array<string, LinkDefinition> */
    private array $definitions = [];

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly TableNames $tables,
    ) {}

    #[Override]
    public function define(LinkDefinition $definition): void
    {
        if (isset($this->definitions[$definition->code])) {
            throw new InvalidCommand('code', "link definition '{$definition->code}' is already registered", 'links.duplicate_definition');
        }

        $this->definitions[$definition->code] = $definition;
    }

    #[Override]
    public function definition(string $linkCode): LinkDefinition
    {
        return $this->definitions[$linkCode]
            ?? throw new NotFound('LinkDefinition', $linkCode);
    }

    #[Override]
    public function hasDefinition(string $linkCode): bool
    {
        return isset($this->definitions[$linkCode]);
    }

    /** @return array<string, LinkDefinition> */
    #[Override]
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @param array<string, mixed> $extra */
    #[Override]
    public function link(
        string $linkCode,
        string $leftId,
        string $rightId,
        int $position = 0,
        array $extra = [],
    ): EntityLink {
        self::assertEntityId($leftId, 'leftId');
        self::assertEntityId($rightId, 'rightId');

        if ($position < 0) {
            throw new InvalidCommand('position', 'link position must be >= 0', 'links.invalid_position');
        }

        $definition = $this->definition($linkCode);
        $table = $this->tables->table('entity_links');
        $db = $this->db();

        $existing = $db->table($table)
            ->where('link_code', $definition->code)
            ->where('left_type', $definition->leftType)
            ->where('left_id', $leftId)
            ->where('right_type', $definition->rightType)
            ->where('right_id', $rightId)
            ->first();

        $now = self::now();
        $encodedExtra = json_encode($extra, JSON_THROW_ON_ERROR);

        if (is_object($existing)) {
            $id = self::string($existing, 'id');
            $db->table($table)
                ->where('id', $id)
                ->update([
                    'position' => $position,
                    'extra' => $encodedExtra,
                    'updated_at' => $now,
                ]);

            return new EntityLink(
                id: $id,
                linkCode: $definition->code,
                leftType: $definition->leftType,
                leftId: $leftId,
                rightType: $definition->rightType,
                rightId: $rightId,
                position: $position,
                extra: $extra,
                createdAt: self::timestamp($existing, 'created_at'),
                updatedAt: new DateTimeImmutable($now),
            );
        }

        $this->assertCardinality($db, $table, $definition, $leftId, $rightId);

        $id = Uuid7::generate();
        $db->table($table)->insert([
            'id' => $id,
            'link_code' => $definition->code,
            'left_type' => $definition->leftType,
            'left_id' => $leftId,
            'right_type' => $definition->rightType,
            'right_id' => $rightId,
            'position' => $position,
            'extra' => $encodedExtra,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $timestamp = new DateTimeImmutable($now);

        return new EntityLink(
            id: $id,
            linkCode: $definition->code,
            leftType: $definition->leftType,
            leftId: $leftId,
            rightType: $definition->rightType,
            rightId: $rightId,
            position: $position,
            extra: $extra,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );
    }

    #[Override]
    public function unlink(string $linkCode, string $leftId, string $rightId): bool
    {
        $definition = $this->definition($linkCode);

        $deleted = $this->db()->table($this->tables->table('entity_links'))
            ->where('link_code', $definition->code)
            ->where('left_type', $definition->leftType)
            ->where('left_id', $leftId)
            ->where('right_type', $definition->rightType)
            ->where('right_id', $rightId)
            ->delete();

        return $deleted > 0;
    }

    #[Override]
    public function unlinkAllFor(string $entityType, string $entityId, ?string $linkCode = null): int
    {
        $matching = $linkCode !== null
            ? [$this->definition($linkCode)]
            : array_values(array_filter(
                $this->definitions,
                static fn (LinkDefinition $def): bool => $def->leftType === $entityType || $def->rightType === $entityType,
            ));

        $table = $this->tables->table('entity_links');
        $db = $this->db();

        foreach ($matching as $definition) {
            if ($definition->cascadeUnlink !== CascadeUnlinkPolicy::Restrict) {
                continue;
            }

            if ($this->hasLinksForEntity($db, $table, $definition, $entityType, $entityId)) {
                throw new InvalidCommand(
                    'entityId',
                    "cannot unlink '{$entityType}:{$entityId}' because link '{$definition->code}' has restrict cascade policy",
                    'links.unlink_restricted',
                );
            }
        }

        $deleted = 0;

        foreach ($matching as $definition) {
            if ($definition->cascadeUnlink !== CascadeUnlinkPolicy::Unlink) {
                continue;
            }

            if ($definition->leftType === $entityType) {
                $deleted += $db->table($table)
                    ->where('link_code', $definition->code)
                    ->where('left_type', $entityType)
                    ->where('left_id', $entityId)
                    ->delete();
            }

            if ($definition->rightType === $entityType) {
                $deleted += $db->table($table)
                    ->where('link_code', $definition->code)
                    ->where('right_type', $entityType)
                    ->where('right_id', $entityId)
                    ->delete();
            }
        }

        return $deleted;
    }

    /**
     * @param  list<string>  $leftIds
     * @return array<string, list<EntityLink>>
     */
    #[Override]
    public function linksForMany(string $linkCode, array $leftIds): array
    {
        $definition = $this->definition($linkCode);

        if ($leftIds === []) {
            return [];
        }

        $uniqueIds = array_values(array_unique($leftIds));
        $grouped = [];

        foreach ($uniqueIds as $id) {
            $grouped[$id] = [];
        }

        $rows = $this->db()->table($this->tables->table('entity_links'))
            ->where('link_code', $definition->code)
            ->where('left_type', $definition->leftType)
            ->whereIn('left_id', $uniqueIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $link = self::hydrate($row);
            $grouped[$link->leftId][] = $link;
        }

        return $grouped;
    }

    /**
     * @param  list<string>  $rightIds
     * @return array<string, list<EntityLink>>
     */
    #[Override]
    public function inverseLinksForMany(string $linkCode, array $rightIds): array
    {
        $definition = $this->definition($linkCode);

        if ($rightIds === []) {
            return [];
        }

        $uniqueIds = array_values(array_unique($rightIds));
        $grouped = [];

        foreach ($uniqueIds as $id) {
            $grouped[$id] = [];
        }

        $rows = $this->db()->table($this->tables->table('entity_links'))
            ->where('link_code', $definition->code)
            ->where('right_type', $definition->rightType)
            ->whereIn('right_id', $uniqueIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $link = self::hydrate($row);
            $grouped[$link->rightId][] = $link;
        }

        return $grouped;
    }

    private function assertCardinality(
        ConnectionInterface $db,
        string $table,
        LinkDefinition $definition,
        string $leftId,
        string $rightId,
    ): void {
        if ($definition->cardinality === LinkCardinality::ManyToMany) {
            return;
        }

        if ($definition->cardinality === LinkCardinality::OneToOne || $definition->cardinality === LinkCardinality::ManyToOne) {
            $leftExists = $db->table($table)
                ->where('link_code', $definition->code)
                ->where('left_type', $definition->leftType)
                ->where('left_id', $leftId)
                ->exists();

            if ($leftExists) {
                throw new InvalidCommand(
                    'leftId',
                    "link '{$definition->code}' ({$definition->cardinality->value}) already has a target for '{$definition->leftType}:{$leftId}'",
                    'links.cardinality_violation',
                );
            }
        }

        if ($definition->cardinality === LinkCardinality::OneToOne || $definition->cardinality === LinkCardinality::OneToMany) {
            $rightExists = $db->table($table)
                ->where('link_code', $definition->code)
                ->where('right_type', $definition->rightType)
                ->where('right_id', $rightId)
                ->exists();

            if ($rightExists) {
                throw new InvalidCommand(
                    'rightId',
                    "link '{$definition->code}' ({$definition->cardinality->value}) already has a source for '{$definition->rightType}:{$rightId}'",
                    'links.cardinality_violation',
                );
            }
        }
    }

    private function hasLinksForEntity(
        ConnectionInterface $db,
        string $table,
        LinkDefinition $definition,
        string $entityType,
        string $entityId,
    ): bool {
        if ($definition->leftType === $entityType) {
            $exists = $db->table($table)
                ->where('link_code', $definition->code)
                ->where('left_type', $entityType)
                ->where('left_id', $entityId)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        if ($definition->rightType === $entityType) {
            return $db->table($table)
                ->where('link_code', $definition->code)
                ->where('right_type', $entityType)
                ->where('right_id', $entityId)
                ->exists();
        }

        return false;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->tables->connection());
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }

    private static function assertEntityId(string $id, string $field): void
    {
        if (strlen($id) < 1 || strlen($id) > 120 || preg_match('/\A[^\p{Cc}]+\z/u', $id) !== 1) {
            throw new InvalidCommand($field, 'entity id must be 1..120 bytes of valid printable UTF-8', 'links.invalid_entity_id');
        }
    }

    private static function hydrate(object $row): EntityLink
    {
        return new EntityLink(
            id: self::string($row, 'id'),
            linkCode: self::string($row, 'link_code'),
            leftType: self::string($row, 'left_type'),
            leftId: self::string($row, 'left_id'),
            rightType: self::string($row, 'right_type'),
            rightId: self::string($row, 'right_id'),
            position: self::int($row, 'position'),
            extra: self::jsonObject($row, 'extra'),
            createdAt: self::timestamp($row, 'created_at'),
            updatedAt: self::timestamp($row, 'updated_at'),
        );
    }

    private static function string(object $row, string $column): string
    {
        $value = get_object_vars($row)[$column] ?? null;

        if (! is_string($value)) {
            throw new LogicException("Expected string column '{$column}' in entity_links row.");
        }

        return $value;
    }

    private static function int(object $row, string $column): int
    {
        $value = get_object_vars($row)[$column] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new LogicException("Expected integer column '{$column}' in entity_links row.");
    }

    /** @return array<string, mixed> */
    private static function jsonObject(object $row, string $column): array
    {
        $value = get_object_vars($row)[$column] ?? null;

        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } elseif (is_array($value)) {
            $decoded = $value;
        } else {
            throw new LogicException("Expected json column '{$column}' in entity_links row.");
        }

        if (! is_array($decoded)) {
            throw new LogicException("Expected JSON object in column '{$column}' of entity_links row.");
        }

        $result = [];

        foreach ($decoded as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    private static function timestamp(object $row, string $column): DateTimeImmutable
    {
        $value = get_object_vars($row)[$column] ?? null;

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value)) {
            return new DateTimeImmutable($value);
        }

        throw new LogicException("Expected timestamp column '{$column}' in entity_links row.");
    }
}
