<?php

declare(strict_types=1);

namespace Commerz\Measures;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Text by locale tag. Never empty, so `resolve()` always has an answer. */
#[Api(since: '0.1')]
final readonly class LocalizedText
{
    /** @param array<array-key, string> $texts locale => text, never empty */
    public function __construct(public array $texts)
    {
        if ($texts === []) {
            throw new InvalidCommand('texts', 'at least one locale is required', 'measures.invalid_text');
        }

        foreach ($texts as $locale => $text) {
            if (preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/', (string) $locale) !== 1) {
                throw new InvalidCommand('texts', "'{$locale}' is not a locale tag", 'measures.invalid_text');
            }

            if (trim($text) === '') {
                throw new InvalidCommand('texts', "text for '{$locale}' must not be blank", 'measures.invalid_text');
            }
        }
    }

    public static function of(string $locale, string $text): self
    {
        return new self([$locale => $text]);
    }

    public function with(string $locale, string $text): self
    {
        return new self([...$this->texts, $locale => $text]);
    }

    /**
     * Exact locale, then its language ('pt-BR' → 'pt'), then `$fallback`, then the first stored text.
     */
    public function resolve(string $locale, string $fallback = 'en'): string
    {
        foreach ([$locale, explode('-', $locale)[0], $fallback] as $candidate) {
            if (isset($this->texts[$candidate])) {
                return $this->texts[$candidate];
            }
        }

        return $this->texts[array_key_first($this->texts)];
    }
}
