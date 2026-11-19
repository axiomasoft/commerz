<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Data\AddAttributeChoices;
use Commerz\Catalog\Attributes\Data\AttributeChoiceId;
use Commerz\Catalog\Attributes\Data\AttributeChoiceView;
use Commerz\Catalog\Attributes\Data\AttributeId;
use Commerz\Catalog\Attributes\Data\AttributeView;
use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\ListAttributes;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidStatusTransition;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\Clock;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

#[Internal]
final readonly class DatabaseAttributes implements Attributes
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Contributions $contributions,
        private AttributeIndexer $indexer,
    ) {}

    public function define(DefineAttribute $command): AttributeView
    {
        $type = $this->contributions->get(AttributeType::KIND, $command->type, AttributeType::class);
        $config = $type->config($command->config);

        if ($command->filterable && $type->operators() === []) {
            throw new InvalidAttributeConfig('filterable', "type '{$command->type}' cannot be filtered");
        }

        if ($type->usesChoices() && $command->choices === []) {
            throw new InvalidAttributeConfig('choices', "type '{$command->type}' requires at least one choice");
        }

        if (! $type->usesChoices() && $command->choices !== []) {
            throw new InvalidAttributeConfig('choices', "type '{$command->type}' does not take choices");
        }

        return $this->transactions->run(function () use ($command, $type, $config): AttributeView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('attributes'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'type' => $command->type,
                    'name' => Documents::encode($command->name->texts),
                    'config' => Documents::encode($config),
                    'config_version' => $type->configVersion(),
                    'filterable' => $command->filterable,
                    'status' => AttributeStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Attribute', $command->code);
            }

            $this->insertChoices($db, $id, $command->choices, 0, $now);

            return $this->find(new AttributeId($id));
        });
    }

    public function update(UpdateAttribute $command): AttributeView
    {
        return $this->transactions->run(function () use ($command): AttributeView {
            $db = $this->db();
            $row = $this->lock($command->attribute);
            $current = AttributeStatus::from(Rows::string($row, 'status'));
            $wasFilterable = Rows::bool($row, 'filterable');
            $changes = [];

            if ($command->name instanceof LocalizedText) {
                $changes['name'] = Documents::encode($command->name->texts);
            }

            if ($command->status !== null && $command->status !== $current) {
                if (! $current->canBecome($command->status)) {
                    throw new InvalidStatusTransition('Attribute', $current->value, $command->status->value);
                }

                $changes['status'] = $command->status->value;
            }

            if ($command->filterable !== null && $command->filterable !== $wasFilterable) {
                if ($command->filterable) {
                    $type = $this->contributions->get(AttributeType::KIND, Rows::string($row, 'type'), AttributeType::class);

                    if ($type->operators() === []) {
                        throw new InvalidAttributeConfig('filterable', "type '{$type->key()}' cannot be filtered");
                    }
                }

                $changes['filterable'] = $command->filterable;
            }

            if ($command->choiceLabels !== []) {
                $this->relabel($db, $command->attribute, $command->choiceLabels);
            }

            if ($changes !== [] || $command->choiceLabels !== []) {
                $db->table($this->tables->table('attributes'))
                    ->where('id', $command->attribute->value)
                    ->update($changes + ['updated_at' => Clock::now()]);
            }

            // Only filterable and retirement change the projection; deprecation keeps the facts of existing values.
            if (isset($changes['filterable']) || ($changes['status'] ?? null) === AttributeStatus::Retired->value) {
                $this->indexer->reindexAttribute($command->attribute);
            }

            return $this->find($command->attribute);
        });
    }

    public function addChoices(AddAttributeChoices $command): AttributeView
    {
        return $this->transactions->run(function () use ($command): AttributeView {
            $db = $this->db();
            $row = $this->lock($command->attribute);
            $typeKey = Rows::string($row, 'type');
            $type = $this->contributions->get(AttributeType::KIND, $typeKey, AttributeType::class);

            if (! $type->usesChoices()) {
                throw new InvalidAttributeConfig('choices', "type '{$typeKey}' does not take choices");
            }

            $table = $this->tables->table('attribute_choices');
            $existing = $db->table($table)->where('attribute_id', $command->attribute->value)->pluck('code')->all();

            foreach (array_keys($command->choices) as $code) {
                if (in_array((string) $code, $existing, true)) {
                    throw new DuplicateCode('Attribute choice', (string) $code);
                }
            }

            $last = $db->table($table)->where('attribute_id', $command->attribute->value)->max('position');
            $now = Clock::now();

            $this->insertChoices($db, $command->attribute->value, $command->choices, is_numeric($last) ? (int) $last + 1 : 0, $now);

            $db->table($this->tables->table('attributes'))->where('id', $command->attribute->value)->update(['updated_at' => $now]);

            return $this->find($command->attribute);
        });
    }

    public function find(AttributeId $id): AttributeView
    {
        $row = $this->db()->table($this->tables->table('attributes'))->where('id', $id->value)->first();

        if ($row === null) {
            throw new AttributeNotFound($id->value);
        }

        return $this->views([$row])[0];
    }

    public function findByCode(string $code): AttributeView
    {
        $row = $this->db()->table($this->tables->table('attributes'))->where('code', $code)->first();

        if ($row === null) {
            throw new AttributeNotFound($code);
        }

        return $this->views([$row])[0];
    }

    public function list(ListAttributes $query): Page
    {
        $builder = $this->db()->table($this->tables->table('attributes'))->orderBy('code')->limit($query->first + 1);

        if ($query->type !== null) {
            $builder->where('type', $query->type);
        }

        if (! $query->includeRetired) {
            $builder->where('status', '!=', AttributeStatus::Retired->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = $this->views(array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    private function lock(AttributeId $id): object
    {
        $row = $this->db()->table($this->tables->table('attributes'))->where('id', $id->value)->lock('for no key update')->first();

        return $row ?? throw new AttributeNotFound($id->value);
    }

    /** @param  array<array-key, LocalizedText>  $choices */
    private function insertChoices(ConnectionInterface $db, string $attribute, array $choices, int $position, string $now): void
    {
        $rows = [];

        foreach ($choices as $code => $label) {
            $rows[] = [
                'id' => Uuid7::generate(),
                'attribute_id' => $attribute,
                'code' => (string) $code,
                'label' => Documents::encode($label->texts),
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            $db->table($this->tables->table('attribute_choices'))->insert($rows);
        }
    }

    /** @param  array<array-key, LocalizedText>  $labels */
    private function relabel(ConnectionInterface $db, AttributeId $attribute, array $labels): void
    {
        $table = $this->tables->table('attribute_choices');
        $known = $db->table($table)->where('attribute_id', $attribute->value)->pluck('code')->all();
        $now = Clock::now();

        foreach ($labels as $code => $label) {
            if (! in_array((string) $code, $known, true)) {
                throw new InvalidAttributeConfig('choiceLabels', "unknown choice '{$code}'");
            }

            $db->table($table)
                ->where('attribute_id', $attribute->value)
                ->where('code', (string) $code)
                ->update(['label' => Documents::encode($label->texts), 'updated_at' => $now]);
        }
    }

    /**
     * @param  list<object>  $rows
     * @return list<AttributeView>
     */
    private function views(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
        $choices = [];

        $choiceRows = $this->db()->table($this->tables->table('attribute_choices'))
            ->whereIn('attribute_id', $ids)
            ->orderBy('position')
            ->orderBy('code')
            ->get();

        foreach ($choiceRows as $choice) {
            $choices[Rows::string($choice, 'attribute_id')][] = new AttributeChoiceView(
                new AttributeChoiceId(Rows::string($choice, 'id')),
                Rows::string($choice, 'code'),
                Rows::localized($choice, 'label'),
                Rows::int($choice, 'position'),
            );
        }

        $views = [];

        foreach ($rows as $row) {
            $id = Rows::string($row, 'id');
            $type = Rows::string($row, 'type');
            /** @var array<string, mixed> $config */
            $config = Rows::json($row, 'config');

            $views[] = new AttributeView(
                new AttributeId($id),
                Rows::string($row, 'code'),
                $type,
                Rows::localized($row, 'name'),
                $config,
                Rows::int($row, 'config_version'),
                Rows::bool($row, 'filterable'),
                AttributeStatus::from(Rows::string($row, 'status')),
                $this->contributions->has(AttributeType::KIND, $type),
                $choices[$id] ?? [],
                Rows::date($row, 'created_at'),
                Rows::date($row, 'updated_at'),
            );
        }

        return $views;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
