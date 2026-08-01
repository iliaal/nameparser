<?php

namespace Iliaal\NameParser;

/**
 * Shared token-normalization primitives. The parser's mappers and the advisory
 * Confidence pass must key and case-test tokens identically, so both routes go
 * through this single implementation rather than duplicating the transforms.
 *
 * @internal
 */
final class Text
{
    public const int MAX_INPUT_BYTES = 1024 * 1024;

    public const int MAX_INPUT_TOKENS = 65536;

    private const int MAX_NICKNAME_DELIMITER_BYTES = 64;

    private const int MAX_NICKNAME_DELIMITER_PAIRS = 32;

    private const array CREDENTIAL_TAIL_NOISE_KEYS = [
        'unknown' => true,
    ];

    /**
     * @var array<string, string>
     */
    private static array $cache = [];

    public static function assertInputByteBudget(string $input): void
    {
        if (strlen($input) > self::MAX_INPUT_BYTES) {
            throw new \LengthException(
                'Name input exceeds the ' . self::MAX_INPUT_BYTES . '-byte limit.',
            );
        }
    }

    public static function assertInputTokenCount(int $count): void
    {
        if ($count > self::MAX_INPUT_TOKENS) {
            throw new \LengthException(
                'Name input exceeds the ' . self::MAX_INPUT_TOKENS . '-token limit.',
            );
        }
    }

    /**
     * registry lookup key for the given word
     */
    public static function key(string $word): string
    {
        // the entry cap bounds the count, not the bytes: a run of huge unique
        // tokens would retain megabytes, and nothing that long is a name worth
        // caching anyway
        if (strlen($word) > 64) {
            return self::transform($word);
        }

        if (isset(self::$cache[$word])) {
            return self::$cache[$word];
        }

        // pure, config-independent transform, so cached entries never go stale;
        // cap the table and drop it wholesale to bound memory on huge batches.
        if (count(self::$cache) >= 4096) {
            self::$cache = [];
        }

        return self::$cache[$word] = self::transform($word);
    }

    private static function transform(string $word): string
    {
        $key = str_replace('.', '', $word);
        $key = trim($key, " \r\n\t\"'()[]{}<>");
        $key = rtrim($key, ',;:)');

        return mb_strtolower($key, 'UTF-8');
    }

    /**
     * the word's letters only, everything else stripped
     */
    public static function letters(string $word): string
    {
        return preg_replace('/[^\p{L}\p{M}]/u', '', $word) ?? '';
    }

    /**
     * @return list<string>
     */
    public static function graphemes(string $word): array
    {
        if ($word === '') {
            return [];
        }

        $matches = [];

        if (preg_match_all('/\X/u', $word, $matches) === false) {
            return str_split($word);
        }

        return $matches[0];
    }

    public static function graphemeLengthUpTo(string $word, int $limit): int
    {
        if ($word === '' || $limit < 1) {
            return 0;
        }

        $length = 0;
        $offset = 0;
        $bytes = strlen($word);

        while ($offset < $bytes && $length < $limit) {
            $match = [];
            $matched = preg_match('/\G\X/u', $word, $match, 0, $offset);

            if ($matched !== 1) {
                return min($limit, $bytes);
            }

            $offset += strlen($match[0]);
            ++$length;
        }

        return $length;
    }

    /**
     * @param  array<string, string>  $delimiters
     * @return array<string, string>
     */
    public static function sanitizeNicknameDelimiters(array $delimiters): array
    {
        return array_slice(
            array_filter(
                $delimiters,
                static fn(string $close, string $open): bool => $open !== ''
                    && $close !== ''
                    && strlen($open) <= self::MAX_NICKNAME_DELIMITER_BYTES
                    && strlen($close) <= self::MAX_NICKNAME_DELIMITER_BYTES
                    && mb_check_encoding($open, 'UTF-8')
                    && mb_check_encoding($close, 'UTF-8'),
                ARRAY_FILTER_USE_BOTH,
            ),
            0,
            self::MAX_NICKNAME_DELIMITER_PAIRS,
            true,
        );
    }

    public static function isCredentialTailNoise(string $token): bool
    {
        if (isset(self::CREDENTIAL_TAIL_NOISE_KEYS[self::key($token)])) {
            return true;
        }

        return preg_match('/[\p{L}\p{N}]/u', $token) !== 1;
    }

    /**
     * true when the word's letters are all uppercase and carry a case signal
     * (letters exist and are not caseless)
     */
    public static function isUpperCase(string $word): bool
    {
        $letters = self::letters($word);

        if ($letters === '') {
            return false;
        }

        return $letters === mb_strtoupper($letters, 'UTF-8')
            && $letters !== mb_strtolower($letters, 'UTF-8');
    }

    /**
     * true when the word's letters are all lowercase and carry a case signal
     */
    public static function isLowerCase(string $word): bool
    {
        $letters = self::letters($word);

        if ($letters === '') {
            return false;
        }

        return $letters === mb_strtolower($letters, 'UTF-8')
            && $letters !== mb_strtoupper($letters, 'UTF-8');
    }

    /**
     * true when the word's letters have a distinct upper/lower form (Latin,
     * Greek, Cyrillic) rather than a caseless script (Han, Hebrew, Arabic)
     */
    public static function isCased(string $word): bool
    {
        $letters = self::letters($word);

        return $letters !== ''
            && mb_strtolower($letters, 'UTF-8') !== mb_strtoupper($letters, 'UTF-8');
    }

    /**
     * ambiguous credentials normally require all-caps input, but a dictionary
     * may intentionally render a mixed-case credential such as "LAc"
     */
    public static function matchesCredentialCase(string $token, string $rendered): bool
    {
        return self::isUpperCase($token)
            || self::letters($token) === self::letters($rendered);
    }

    /**
     * true when the token is one self-contained nickname span: it starts with
     * an opener and ends with that opener's closer ("(Bob)", "'Doc'")
     *
     * @param  array<string, string>  $delimiters  sanitized opener => closer map
     */
    public static function isSpanWrappedToken(string $token, array $delimiters): bool
    {
        foreach ($delimiters as $open => $close) {
            if (strlen($token) > strlen((string) $open) + strlen($close)
                && str_starts_with($token, (string) $open)
                && str_ends_with($token, $close)) {
                return true;
            }
        }

        return false;
    }

    /**
     * an all-caps unknown token that reads as a credential candidate ("FACS"):
     * at least two letters, not bracket/quote-wrapped. Callers still gate on
     * dictionary membership and uniform-uppercase input.
     */
    public static function isUnknownCredentialCandidate(string $token): bool
    {
        // a bracket/quote-wrapped token is a nickname or aside ("(JJ)"), not a
        // credential; those are resolved by later mappers, so leave them be.
        if (preg_match('/[()\[\]{}<>"\']/', $token) === 1) {
            return false;
        }

        if (! self::isUpperCase($token)) {
            return false;
        }

        return self::graphemeLengthUpTo(self::letters($token), 2) >= 2;
    }
}
