<?php

declare(strict_types=1);

namespace Commerz\Schema\Internal;

use UnexpectedValueException;

/**
 * Строгое чтение строк Query Builder (stdClass с mixed-полями) без кастов «на авось».
 * Неожиданный тип колонки — исключение, а не тихое приведение.
 */
final readonly class Row
{
    private function __construct(private object $row) {}

    public static function of(mixed $row): self
    {
        return is_object($row) ? new self($row) : throw new UnexpectedValueException('Expected a database row object.');
    }

    public function string(string $column): string
    {
        $value = $this->row->{$column} ?? null;

        return is_string($value) ? $value : throw $this->unexpected($column, 'string');
    }

    public function nullableString(string $column): ?string
    {
        $value = $this->row->{$column} ?? null;

        return $value === null || is_string($value) ? $value : throw $this->unexpected($column, '?string');
    }

    /** PDO pgsql возвращает bigint как int (64-бит) или как строку в зависимости от настроек — принимаем оба строго. */
    public function int(string $column): int
    {
        $value = $this->row->{$column} ?? null;

        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/\A-?\d{1,19}\z/D', $value) === 1 => (int) $value,
            default => throw $this->unexpected($column, 'int'),
        };
    }

    public function nullableInt(string $column): ?int
    {
        return ($this->row->{$column} ?? null) === null ? null : $this->int($column);
    }

    private function unexpected(string $column, string $type): UnexpectedValueException
    {
        return new UnexpectedValueException("Column [{$column}] is not {$type}.");
    }
}
