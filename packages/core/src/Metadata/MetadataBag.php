<?php

declare(strict_types=1);

namespace Commerz\Metadata;

use Commerz\CustomFields\Enums\FieldVisibility;
use Commerz\Metadata\Exceptions\InvalidMetadata;
use JsonException;
use Packman\Attributes\Api;
use stdClass;

/**
 * Unified immutable container for an aggregate's `publicMetadata` (storefront-safe) and `privateMetadata` (admin-only).
 */
#[Api(since: '0.1')]
final readonly class MetadataBag
{
    /** @var array<string, mixed> */
    public array $public;

    /** @var array<string, mixed> */
    public array $private;

    /**
     * @param  array<array-key, mixed>  $public
     * @param  array<array-key, mixed>  $private
     */
    public function __construct(
        array $public = [],
        array $private = [],
    ) {
        $this->public = MetadataValidator::validateMap($public, 'publicMetadata');
        $this->private = MetadataValidator::validateMap($private, 'privateMetadata');
    }

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  array<array-key, mixed>  $public
     */
    public static function fromPublic(array $public): self
    {
        return new self(public: $public);
    }

    /**
     * @param  array<array-key, mixed>  $private
     */
    public static function fromPrivate(array $private): self
    {
        return new self(private: $private);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $allowedKeys = ['public', 'publicMetadata', 'public_metadata', 'private', 'privateMetadata', 'private_metadata'];
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                throw new InvalidMetadata('metadata', "unexpected metadata envelope key '{$key}'");
            }
        }

        $public = $data['public'] ?? $data['publicMetadata'] ?? $data['public_metadata'] ?? [];
        $private = $data['private'] ?? $data['privateMetadata'] ?? $data['private_metadata'] ?? [];

        if (! is_array($public)) {
            throw new InvalidMetadata('publicMetadata', 'expected an associative array');
        }

        if (! is_array($private)) {
            throw new InvalidMetadata('privateMetadata', 'expected an associative array');
        }

        return new self(public: $public, private: $private);
    }

    public static function fromJson(?string $publicJson, ?string $privateJson = null): self
    {
        return new self(
            public: MetadataSerializer::decodeMap($publicJson, 'publicMetadata'),
            private: MetadataSerializer::decodeMap($privateJson, 'privateMetadata'),
        );
    }

    public static function fromCombinedJson(string $json): self
    {
        try {
            $objectForm = json_decode($json, false, MetadataValidator::MAX_DEPTH + 5, JSON_THROW_ON_ERROR);
            $decoded = json_decode($json, true, MetadataValidator::MAX_DEPTH + 5, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidMetadata('metadata', 'malformed combined metadata JSON: '.$e->getMessage());
        }

        if (! $objectForm instanceof stdClass || ! is_array($decoded)) {
            throw new InvalidMetadata('metadata', 'combined metadata JSON must be an object');
        }

        return self::fromArray($decoded);
    }

    public function isEmpty(): bool
    {
        return $this->public === [] && $this->private === [];
    }

    public function hasPublic(string $key): bool
    {
        return array_key_exists($key, $this->public);
    }

    public function hasPrivate(string $key): bool
    {
        return array_key_exists($key, $this->private);
    }

    public function getPublic(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->public) ? $this->public[$key] : $default;
    }

    public function getPrivate(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->private) ? $this->private[$key] : $default;
    }

    /**
     * Storefront projection: returns only public metadata and never exposes private keys.
     *
     * @return array<string, mixed>
     */
    public function forStorefront(): array
    {
        return $this->public;
    }

    /**
     * Admin projection: returns both public and private metadata maps.
     *
     * @return array{public: array<string, mixed>, private: array<string, mixed>}
     */
    public function forAdmin(): array
    {
        return [
            'public' => $this->public,
            'private' => $this->private,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function visibleTo(FieldVisibility $audience): array
    {
        return $audience === FieldVisibility::PublicStorefront
            ? ['public' => $this->public]
            : ['public' => $this->public, 'private' => $this->private];
    }

    /**
     * Returns a copy with all private metadata stripped out for safe boundary crossing.
     */
    public function redactPrivate(): self
    {
        return new self(public: $this->public, private: []);
    }

    public function withPublic(string $key, mixed $value): self
    {
        return new self(
            public: [...$this->public, $key => $value],
            private: $this->private,
        );
    }

    public function withoutPublic(string ...$keys): self
    {
        return new self(
            public: array_diff_key($this->public, array_flip($keys)),
            private: $this->private,
        );
    }

    /**
     * @param  array<array-key, mixed>  $set
     * @param  list<string>  $remove
     */
    public function mergePublic(array $set, array $remove = []): self
    {
        $setKeys = array_map(strval(...), array_keys($set));
        $overlap = array_values(array_intersect($setKeys, $remove));

        if ($overlap !== []) {
            throw new InvalidMetadata('publicMetadata', "key '{$overlap[0]}' cannot be both set and removed");
        }

        $next = array_diff_key($this->public, array_flip($remove));
        foreach ($set as $key => $value) {
            $next[$key] = $value;
        }

        return new self(public: $next, private: $this->private);
    }

    public function withPrivate(string $key, mixed $value): self
    {
        return new self(
            public: $this->public,
            private: [...$this->private, $key => $value],
        );
    }

    public function withoutPrivate(string ...$keys): self
    {
        return new self(
            public: $this->public,
            private: array_diff_key($this->private, array_flip($keys)),
        );
    }

    /**
     * @param  array<array-key, mixed>  $set
     * @param  list<string>  $remove
     */
    public function mergePrivate(array $set, array $remove = []): self
    {
        $setKeys = array_map(strval(...), array_keys($set));
        $overlap = array_values(array_intersect($setKeys, $remove));

        if ($overlap !== []) {
            throw new InvalidMetadata('privateMetadata', "key '{$overlap[0]}' cannot be both set and removed");
        }

        $next = array_diff_key($this->private, array_flip($remove));
        foreach ($set as $key => $value) {
            $next[$key] = $value;
        }

        return new self(public: $this->public, private: $next);
    }

    public function merge(self $other): self
    {
        return new self(
            public: [...$this->public, ...$other->public],
            private: [...$this->private, ...$other->private],
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(bool $includePrivate = true): array
    {
        return $includePrivate
            ? ['public' => $this->public, 'private' => $this->private]
            : ['public' => $this->public];
    }

    public function toPublicJson(): string
    {
        return MetadataSerializer::encodeMap($this->public, 'publicMetadata');
    }

    public function toPrivateJson(): string
    {
        return MetadataSerializer::encodeMap($this->private, 'privateMetadata');
    }

    public function toJson(bool $includePrivate = true): string
    {
        $payload = new stdClass;
        $payload->public = json_decode($this->toPublicJson(), false, flags: JSON_THROW_ON_ERROR);

        if ($includePrivate) {
            $payload->private = json_decode($this->toPrivateJson(), false, flags: JSON_THROW_ON_ERROR);
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function equals(self $other): bool
    {
        return $this->toPublicJson() === $other->toPublicJson()
            && $this->toPrivateJson() === $other->toPrivateJson();
    }
}
