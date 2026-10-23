<?php

namespace App\Support\Search;

/**
 * Splits free text into the capped list of tokens used by the database
 * fallback search and builds the matching `ILIKE` patterns.
 */
final class TextTokens
{
    /**
     * Split text on whitespace and keep at most `$max` non-empty tokens.
     *
     * @return list<string>
     */
    public static function from(string $text, int $max = 5): array
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($tokens === false ? [] : $tokens, 0, $max);
    }

    /**
     * Build a `%token%` pattern where the LIKE wildcards and the escape
     * character itself are matched literally.
     */
    public static function containsPattern(string $token): string
    {
        return '%'.addcslashes($token, '\\%_').'%';
    }
}
