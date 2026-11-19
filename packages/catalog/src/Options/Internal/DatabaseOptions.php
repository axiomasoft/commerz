<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Internal;

use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\Clock;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Catalog\Options\Data\AddOptionValues;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Options\Data\ListOptions;
use Commerz\Catalog\Options\Data\OptionId;
use Commerz\Catalog\Options\Data\OptionValueId;
use Commerz\Catalog\Options\Data\OptionValueView;
use Commerz\Catalog\Options\Data\OptionView;
use Commerz\Catalog\Options\Data\UpdateOption;
use Commerz\Catalog\Options\Exceptions\OptionNotFound;
use Commerz\Catalog\Options\Exceptions\UnknownOptionValue;
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

#[Internal]
final readonly class DatabaseOptions implements Options
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
    ) {}

    public function define(DefineOption $command): OptionView
    {
        return $this->transactions->run(function () use ($command): OptionView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('options'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => Documents::encode($command->name->texts),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Option', $command->code);
            }

            $this->insertValues($db, $id, $command->values, 0, $now);

            return $this->find(new OptionId($id));
        });
    }

    public function addValues(AddOptionValues $command): OptionView
    {
        return $this->transactions->run(function () use ($command): OptionView {
            $db = $this->db();
            $this->lock($command->option);

            $table = $this->tables->table('option_values');
            $existing = $db->table($table)->where('option_id', $command->option->value)->pluck('code')->all();

            foreach (array_keys($command->values) as $code) {
                if (in_array((string) $code, $existing, true)) {
                    throw new DuplicateCode('Option value', (string) $code);
                }
            }

            $last = $db->table($table)->where('option_id', $command->option->value)->max('position');
            $now = Clock::now();

            $this->insertValues($db, $command->option->value, $command->values, is_numeric($last) ? (int) $last + 1 : 0, $now);

            $db->table($this->tables->table('options'))->where('id', $command->option->value)->update(['updated_at' => $now]);

            return $this->find($command->option);
        });
    }

    public function update(UpdateOption $command): OptionView
    {
        return $this->transactions->run(function () use ($command): OptionView {
            $db = $this->db();
            $row = $this->lock($command->option);

            if (! $command->name instanceof LocalizedText && $command->valueLabels === []) {
                return $this->find($command->option);
            }

            $table = $this->tables->table('option_values');
            $known = $db->table($table)->where('option_id', $command->option->value)->pluck('code')->all();
            $now = Clock::now();

            foreach ($command->valueLabels as $code => $label) {
                if (! in_array((string) $code, $known, true)) {
                    throw new UnknownOptionValue(Rows::string($row, 'code'), (string) $code);
                }

                $db->table($table)
                    ->where('option_id', $command->option->value)
                    ->where('code', (string) $code)
                    ->update(['label' => Documents::encode($label->texts), 'updated_at' => $now]);
            }

            $changes = ['updated_at' => $now];

            if ($command->name instanceof LocalizedText) {
                $changes['name'] = Documents::encode($command->name->texts);
            }

            $db->table($this->tables->table('options'))->where('id', $command->option->value)->update($changes);

            return $this->find($command->option);
        });
    }

    public function find(OptionId $id): OptionView
    {
        $row = $this->db()->table($this->tables->table('options'))->where('id', $id->value)->first();

        if ($row === null) {
            throw new OptionNotFound($id->value);
        }

        return $this->views([$row])[0];
    }

    public function findByCode(string $code): OptionView
    {
        $row = $this->db()->table($this->tables->table('options'))->where('code', $code)->first();

        if ($row === null) {
            throw new OptionNotFound($code);
        }

        return $this->views([$row])[0];
    }

    public function list(ListOptions $query): Page
    {
        $builder = $this->db()->table($this->tables->table('options'))->orderBy('code')->limit($query->first + 1);

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = $this->views(array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    private function lock(OptionId $id): object
    {
        $row = $this->db()->table($this->tables->table('options'))->where('id', $id->value)->lock('for no key update')->first();

        return $row ?? throw new OptionNotFound($id->value);
    }

    /** @param  array<array-key, LocalizedText>  $values */
    private function insertValues(ConnectionInterface $db, string $option, array $values, int $position, string $now): void
    {
        $rows = [];

        foreach ($values as $code => $label) {
            $rows[] = [
                'id' => Uuid7::generate(),
                'option_id' => $option,
                'code' => (string) $code,
                'label' => Documents::encode($label->texts),
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $db->table($this->tables->table('option_values'))->insert($rows);
    }

    /**
     * @param  list<object>  $rows
     * @return list<OptionView>
     */
    private function views(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $values = [];

        $valueRows = $this->db()->table($this->tables->table('option_values'))
            ->whereIn('option_id', array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows))
            ->orderBy('position')
            ->orderBy('code')
            ->get();

        foreach ($valueRows as $value) {
            $values[Rows::string($value, 'option_id')][] = new OptionValueView(
                new OptionValueId(Rows::string($value, 'id')),
                Rows::string($value, 'code'),
                Rows::localized($value, 'label'),
                Rows::int($value, 'position'),
            );
        }

        return array_map(static fn (object $row): OptionView => new OptionView(
            new OptionId(Rows::string($row, 'id')),
            Rows::string($row, 'code'),
            Rows::localized($row, 'name'),
            $values[Rows::string($row, 'id')] ?? [],
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        ), $rows);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
