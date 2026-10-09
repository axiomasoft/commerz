<?php

declare(strict_types=1);

namespace Commerz\Pagination;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Opaque keyset position of a page; callers pass it back unchanged as `after`. */
#[Api(since: '0.1')]
final readonly class Cursor implements \Stringable
{
    private function __construct(public string $token) {}

    /** Cursor after the row whose sort key is `$position`. */
    public static function after(string $position): self
    {
        if ($position === '' || ! mb_check_encoding($position, 'UTF-8') || str_contains($position, "\0")) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        return new self(rtrim(strtr(base64_encode($position), '+/', '-_'), '='));
    }

    /** @throws InvalidCommand when the token is not one this package produced */
    public static function fromToken(string $token): self
    {
        $position = base64_decode(strtr($token, '-_', '+/'), true);

        if ($token === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $token) !== 1 || $position === false) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        $cursor = self::after($position);

        if ($cursor->token !== $token) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        return $cursor;
    }

    public function position(): string
    {
        $decoded = base64_decode(strtr($this->token, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    public function __toString(): string
    {
        return $this->token;
    }
}
