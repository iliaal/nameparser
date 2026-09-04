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

    /**
     * entries evicted oldest-first a quarter at a time, so a batch of unique
     * tokens degrades gradually instead of falling off the wholesale-clear
     * cliff (np-cr-015)
     */
    private const int MAX_KEY_CACHE_ENTRIES = 4096;

    private const int KEY_CACHE_EVICT_BATCH = 1024;

    /**
     * repeat lookups of mid-size tokens (65..4096 bytes) stay cheap without
     * letting huge tokens pin megabytes in the main table; anything larger
     * bypasses the cache (never a real name token)
     */
    private const int MAX_LONG_KEY_BYTES = 4096;

    private const int MAX_LONG_KEY_CACHE_ENTRIES = 256;

    private const int LONG_KEY_CACHE_EVICT_BATCH = 64;

    private const array CREDENTIAL_TAIL_NOISE_KEYS = [
        'unknown' => true,
    ];

    /**
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * @var array<string, string>
     */
    private static array $longCache = [];

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
        $length = strlen($word);

        if ($length > self::MAX_LONG_KEY_BYTES) {
            return self::transform($word);
        }

        if ($length > 64) {
            if (isset(self::$longCache[$word])) {
                return self::$longCache[$word];
            }

            if (count(self::$longCache) >= self::MAX_LONG_KEY_CACHE_ENTRIES) {
                self::$longCache = array_slice(
                    self::$longCache,
                    self::LONG_KEY_CACHE_EVICT_BATCH,
                    null,
                    true,
                );
            }

            return self::$longCache[$word] = self::transform($word);
        }

        if (isset(self::$cache[$word])) {
            return self::$cache[$word];
        }

        // pure, config-independent transform, so cached entries never go stale;
        // evict the oldest quarter instead of dropping the table wholesale, so
        // large unique-token batches degrade gradually (no 4096-entry cliff).
        if (count(self::$cache) >= self::MAX_KEY_CACHE_ENTRIES) {
            self::$cache = array_slice(
                self::$cache,
                self::KEY_CACHE_EVICT_BATCH,
                null,
                true,
            );
        }

        return self::$cache[$word] = self::transform($word);
    }

    /**
     * release cached key transforms (long-running importers)
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$longCache = [];
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
                    && mb_check_encoding($close, 'UTF-8')
                    && ! self::containsStructuralChar($open)
                    && ! self::containsStructuralChar($close),
                ARRAY_FILTER_USE_BOTH,
            ),
            0,
            self::MAX_NICKNAME_DELIMITER_PAIRS,
            true,
        );
    }

    /**
     * a delimiter containing a comma, NUL (the comma-mask placeholder), or any
     * whitespace/control character would silently corrupt the structural-comma
     * split or space tokenization (e.g. ',' => ',' shields every comma), so
     * such pairs are ignored (np-cr-012)
     */
    private static function containsStructuralChar(string $delimiter): bool
    {
        return str_contains($delimiter, ',')
            || str_contains($delimiter, "\x00")
            || preg_match('/[\s\p{Cc}]/u', $delimiter) === 1;
    }

    public static function isCredentialTailNoise(string $token): bool
    {
        if (isset(self::CREDENTIAL_TAIL_NOISE_KEYS[self::key($token)])) {
            return true;
        }

        return preg_match('/[\p{L}\p{N}]/u', $token) !== 1;
    }

    /**
     * true for the attested placeholder set ('Unknown'): dropped as credential
     * noise wherever an anchor exists, unlike punctuation-only noise which is
     * scoped to credential-bearing segments (np-cr-014, np-o-04)
     */
    public static function isCredentialPlaceholder(string $token): bool
    {
        return isset(self::CREDENTIAL_TAIL_NOISE_KEYS[self::key($token)]);
    }

    /**
     * the token's letters plus the case signals derived from them in a single
     * pass: one letters() regex instead of one per predicate (np-cr-016).
     * `upper`/`lower` carry the isUpperCase()/isLowerCase() semantics exactly
     * (letters exist, all one case, and the script has case).
     *
     * @return array{letters: string, upper: bool, lower: bool, cased: bool}
     */
    public static function analyzeToken(string $word): array
    {
        $letters = self::letters($word);

        if ($letters === '') {
            return ['letters' => '', 'upper' => false, 'lower' => false, 'cased' => false];
        }

        $upper = mb_strtoupper($letters, 'UTF-8');
        $lower = mb_strtolower($letters, 'UTF-8');
        $cased = $upper !== $lower;

        return [
            'letters' => $letters,
            'upper' => $cased && $letters === $upper,
            'lower' => $cased && $letters === $lower,
            'cased' => $cased,
        ];
    }

    /**
     * true when every cased token is uppercase and at least one cased token
     * exists (letters via letters(), caseless scripts via isCased semantics)
     *
     * @param  list<string>  $tokens
     */
    public static function isUniformUpperTokens(array $tokens): bool
    {
        $hasCased = false;

        foreach ($tokens as $token) {
            $analysis = self::analyzeToken((string) $token);

            if (! $analysis['cased']) {
                continue;
            }

            $hasCased = true;

            if (! $analysis['upper']) {
                return false;
            }
        }

        return $hasCased;
    }

    /**
     * a token that carries no lowercase letter, so it cannot be a title-case
     * name part: the spaced remainder of a credential ("D" in "PHARM D"), a
     * registration number ("2838"), or stray punctuation. Too weak to mark a
     * tail as credentials on its own; callers require a real candidate beside
     * it.
     */
    public static function isCredentialTailRider(string $token): bool
    {
        $letters = self::letters($token);

        return $letters === mb_strtoupper($letters, 'UTF-8');
    }

    /**
     * true when the word's letters are all uppercase and carry a case signal
     * (letters exist and are not caseless)
     */
    public static function isUpperCase(string $word): bool
    {
        return self::analyzeToken($word)['upper'];
    }

    /**
     * true when the word's letters are all lowercase and carry a case signal
     */
    public static function isLowerCase(string $word): bool
    {
        return self::analyzeToken($word)['lower'];
    }

    /**
     * true when the word's letters have a distinct upper/lower form (Latin,
     * Greek, Cyrillic) rather than a caseless script (Han, Hebrew, Arabic)
     */
    public static function isCased(string $word): bool
    {
        return self::analyzeToken($word)['cased'];
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
