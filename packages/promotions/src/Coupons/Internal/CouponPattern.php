<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Internal;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Internal;

/**
 * A generation pattern: `#` is a character of the alphabet, every other character stays as written. Parsed once;
 * characters come from `random_int`, so a code cannot be guessed from the ones before it.
 */
#[Internal]
final readonly class CouponPattern
{
    public const int MIN_SLOTS = 4;

    /** Free codes wanted per code generated: a batch finds room in the first round or two. */
    public const int HEADROOM = 100;

    /** @param  list<string>  $characters  the pattern, one entry per position */
    private function __construct(
        public string $pattern,
        private array $characters,
        private string $alphabet,
        public int $slots,
    ) {}

    /**
     * @throws InvalidCommand promotions.invalid_pattern for a pattern outside 1..64 characters of [A-Z0-9_#-] that does
     *                        not start with a letter or digit; promotions.pattern_too_small for fewer than 4 slots
     */
    public static function parse(string $pattern, string $alphabet): self
    {
        $pattern = strtoupper($pattern);

        if (preg_match('/\A[A-Z0-9#][A-Z0-9_#\-]{0,63}\z/', $pattern) !== 1) {
            throw new InvalidCommand('pattern', "'{$pattern}' must be 1 to 64 characters of letters, digits, '_', '-' and '#', starting with a letter, a digit or '#'", 'promotions.invalid_pattern');
        }

        $slots = substr_count($pattern, '#');

        if ($slots < self::MIN_SLOTS) {
            throw new InvalidCommand('pattern', 'needs at least '.self::MIN_SLOTS." '#' to leave room for different codes", 'promotions.pattern_too_small');
        }

        return new self($pattern, str_split($pattern), $alphabet, $slots);
    }

    /** Whether the pattern holds at least `HEADROOM` times as many codes as `$count`; the product never overflows. */
    public function holds(int $count): bool
    {
        $needed = self::HEADROOM * $count;
        $capacity = 1;

        for ($slot = 0; $slot < $this->slots; $slot++) {
            $capacity *= strlen($this->alphabet);

            if ($capacity >= $needed) {
                return true;
            }
        }

        return false;
    }

    public function generate(): string
    {
        $last = strlen($this->alphabet) - 1;
        $code = '';

        foreach ($this->characters as $character) {
            $code .= $character === '#' ? $this->alphabet[random_int(0, $last)] : $character;
        }

        return $code;
    }

    /** @return bool whether `$code` could have been generated from the pattern */
    public function matches(string $code): bool
    {
        if (strlen($code) !== count($this->characters)) {
            return false;
        }

        return array_all($this->characters, fn (string $character, int $position): bool => $character === '#'
            ? str_contains($this->alphabet, $code[$position])
            : $code[$position] === $character);
    }
}
