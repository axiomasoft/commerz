<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Internal;

use Commerz\Catalog\Search\Enums\SearchSort;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use JsonException;
use Packman\Attributes\Internal;

/**
 * Encodes and validates total-order keyset cursors for `PgCatalogSearch` so every sort order pages without gaps or
 * duplicates.
 */
#[Internal]
final readonly class SearchCursor
{
    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(
        public SearchSort $sort,
        public string $id,
        public string $handle,
        public ?string $createdAt = null,
        public ?int $relevance = null,
    ) {}

    public function toCursor(): Cursor
    {
        $payload = [
            's' => $this->sort->value,
            'id' => $this->id,
            'h' => $this->handle,
        ];

        if ($this->createdAt !== null) {
            $payload['c'] = $this->createdAt;
        }

        if ($this->relevance !== null) {
            $payload['r'] = $this->relevance;
        }

        return Cursor::after(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function decode(Cursor $cursor, SearchSort $expectedSort): self
    {
        try {
            $decoded = json_decode($cursor->position(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidCommand('after', 'is not a valid search cursor', 'common.invalid_cursor');
        }

        if (! is_array($decoded)) {
            throw new InvalidCommand('after', 'is not a valid search cursor', 'common.invalid_cursor');
        }

        $sortRaw = $decoded['s'] ?? null;
        $id = $decoded['id'] ?? null;
        $handle = $decoded['h'] ?? null;

        if (! is_string($sortRaw) || $sortRaw !== $expectedSort->value) {
            throw new InvalidCommand('after', 'cursor does not match the requested sort order', 'common.invalid_cursor');
        }

        if (! is_string($id) || preg_match(self::UUID_PATTERN, $id) !== 1 || ! is_string($handle) || $handle === '') {
            throw new InvalidCommand('after', 'is not a valid search cursor', 'common.invalid_cursor');
        }

        $createdAt = null;

        if ($expectedSort === SearchSort::Newest || $expectedSort === SearchSort::Oldest) {
            $rawCreated = $decoded['c'] ?? null;

            if (! is_string($rawCreated) || $rawCreated === '') {
                throw new InvalidCommand('after', 'is not a valid search cursor', 'common.invalid_cursor');
            }

            $createdAt = $rawCreated;
        }

        $relevance = null;

        if ($expectedSort === SearchSort::Relevance) {
            $rawRelevance = $decoded['r'] ?? null;

            if (! is_int($rawRelevance)) {
                throw new InvalidCommand('after', 'is not a valid search cursor', 'common.invalid_cursor');
            }

            $relevance = $rawRelevance;
        }

        return new self($expectedSort, $id, $handle, $createdAt, $relevance);
    }
}
