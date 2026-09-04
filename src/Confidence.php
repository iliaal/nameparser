<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\AbstractMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;

/**
 * Advisory pass: flags inputs where a token collides with a credential AND the
 * casing signal is uninformative (uniform-case input, or a lowercase token), so
 * the import pipeline can route the row to manual review instead of trusting a
 * silently-chosen first/last split.
 */
class Confidence
{
    /**
     * When suffixes are supplied, only collisions present in that parser's
     * configured dictionaries contribute to the result.
     *
     * Nickname delimiters and whitespace mirror the Parser configuration the
     * input was parsed with (Name::getConfidence() forwards the stored
     * values): decoration mapping and comma splitting then agree with the
     * parse instead of silently falling back to defaults. Null keeps the
     * historical default-config behavior.
     *
     * @param  array<int|string, string>|null  $suffixes
     * @param  array<int|string, string>|null  $salutations
     * @param  list<string>|null  $tokens
     * @param  array<string, string>|null  $nicknameDelimiters
     * @return array{ambiguous: bool, notes: list<string>}
     */
    public static function assess(
        string $original,
        ?array $suffixes = null,
        ?array $salutations = null,
        ?array $tokens = null,
        ?array $nicknameDelimiters = null,
        ?string $whitespace = null,
    ): array {
        Text::assertInputByteBudget($original);
        $validUtf8 = mb_check_encoding($original, 'UTF-8');

        if ($tokens !== null) {
            self::assertSuppliedTokenBudgets($tokens);
            self::assertOriginalTokenBudget($original, $validUtf8, $whitespace);
        }

        if (! $validUtf8) {
            if ($tokens === null) {
                $tokens = preg_split(
                    '/[\s,]+/',
                    trim($original),
                    Text::MAX_INPUT_TOKENS + 1,
                    PREG_SPLIT_NO_EMPTY,
                ) ?: [];
                Text::assertInputTokenCount(count($tokens));
            }

            return ['ambiguous' => true, 'notes' => ['input is not valid UTF-8']];
        }

        if ($tokens === null) {
            $tokens = preg_split(
                self::tokenSplitPattern($whitespace, true),
                trim($original),
                Text::MAX_INPUT_TOKENS + 1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            Text::assertInputTokenCount(count($tokens));
        }

        // uniform-case from tokens (same shape as the parser), never from a
        // whole-string letters() strip on multi-megabyte hostile rows;
        // single-sourced through Text so caseless/digit-only tokens read
        // exactly as the mapper-level gates see them
        $uniformUpper = Text::isUniformUpperTokens($tokens);
        $uniformLower = self::isUniformLowerTokens($tokens);

        /** @var array<string, true> $notes */
        $notes = [];
        foreach ($tokens as $token) {
            $key = Text::key($token);
            if (! isset(SuffixMapper::AMBIGUOUS_KEYS[$key])) {
                continue;
            }

            if ($suffixes !== null && ! array_key_exists($key, $suffixes)) {
                continue;
            }

            $tokenLower = Text::isLowerCase($token);

            if ($uniformUpper) {
                // an uppercase token is read as a credential and stripped; flag
                // it only when it plausibly collides with a real name (Do, Ma,
                // Ba... or a Census surname like Ii/Iv/Mba), since casing
                // carries no signal here. Clean creds (RN/PT/OD...) stay
                // unflagged to keep review noise down on all-caps datasets.
                if (isset(SuffixMapper::NAME_LEANING_KEYS[$key])
                    || isset(SuffixMapper::SURNAME_COLLIDING_KEYS[$key])) {
                    $notes["'{$token}' could be a name or a credential; input casing is uniform"] = true;
                }
            } elseif ($uniformLower) {
                $notes["'{$token}' could be a name or a credential; input casing is uniform"] = true;
            } elseif ($tokenLower) {
                $notes["'{$token}' could be a name or a credential; token is lowercase"] = true;
            }
        }

        $lead = $tokens[0] ?? '';
        $key = Text::key($lead);
        if ($lead !== ''
            && isset(SalutationMapper::NAME_COLLIDING_KEYS[$key])
            && ($salutations === null || array_key_exists($key, $salutations))) {
            // Nicknames and suffixes decorate a name rather than resolving
            // whether a colliding leading title is a salutation or a given
            // name. A comma settles it only when name-bearing content exists
            // on both sides.
            $nameTokens = self::rawNameTokens(self::mapDecorations($tokens, $suffixes, $nicknameDelimiters));
            if (count($nameTokens) === 2
                && ! self::hasDecidingStructuralComma($original, $suffixes, $nicknameDelimiters, $whitespace)) {
                $notes["'{$lead}' could be a name or a salutation; nothing in the input decides it"] = true;
            }
        }

        return ['ambiguous' => $notes !== [], 'notes' => array_keys($notes)];
    }

    /**
     * @param  list<string>  $tokens
     */
    private static function assertSuppliedTokenBudgets(array $tokens): void
    {
        Text::assertInputTokenCount(count($tokens));

        $bytes = 0;
        foreach ($tokens as $token) {
            $bytes += strlen($token);
            if ($bytes > Text::MAX_INPUT_BYTES) {
                throw new \LengthException(
                    'Name tokens exceed the ' . Text::MAX_INPUT_BYTES . '-byte limit.',
                );
            }
        }
    }

    private static function assertOriginalTokenBudget(string $original, bool $validUtf8, ?string $whitespace = null): void
    {
        if (strlen($original) < (Text::MAX_INPUT_TOKENS * 2) + 1) {
            return;
        }

        $tokens = preg_split(
            $validUtf8 ? self::tokenSplitPattern($whitespace, true) : '/[\s,]+/',
            trim($original),
            Text::MAX_INPUT_TOKENS + 1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        Text::assertInputTokenCount(count($tokens));
    }

    /**
     * Token-split pattern for the configured whitespace: null keeps the
     * historical default-config behavior; otherwise the split mirrors the
     * Parser's whitespace handling for the same input (an empty set collapses
     * nothing, so plain spaces still separate, matching tokenizeWords()).
     */
    private static function tokenSplitPattern(?string $whitespace, bool $withComma): string
    {
        if ($whitespace === null) {
            return $withComma ? '/[\s,]+/u' : '/\s+/u';
        }

        if ($whitespace === '') {
            return $withComma ? '/[ ,]+/u' : '/ +/u';
        }

        $class = '[' . preg_quote($whitespace, '/') . ($withComma ? ',' : '') . ']+';

        return '/' . $class . '/u';
    }

    /**
     * @param  list<string>  $tokens
     */
    private static function isUniformLowerTokens(array $tokens): bool
    {
        $hasCased = false;

        foreach ($tokens as $token) {
            if (! Text::isCased($token)) {
                continue;
            }

            $hasCased = true;

            if (! Text::isLowerCase($token)) {
                return false;
            }
        }

        return $hasCased;
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<int|string, string>|null  $suffixes
     * @param  array<string, string>|null  $nicknameDelimiters
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private static function mapDecorations(array $tokens, ?array $suffixes, ?array $nicknameDelimiters = null): array
    {
        // shared factory construction (CR-023 minimal path): same
        // nickname-then-suffix order as before, built in one place
        ['suffix' => $suffixMapper, 'nickname' => $nicknameMapper] = AbstractMapper::decorationAnalyzers(
            $suffixes ?? English::SUFFIXES,
            $nicknameDelimiters ?? [],
        );

        return $suffixMapper->map($nicknameMapper->map($tokens));
    }

    /**
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     * @return list<string>
     */
    private static function rawNameTokens(array $parts): array
    {
        return array_values(array_filter(
            $parts,
            static fn(mixed $part): bool => is_string($part) && Text::letters($part) !== '',
        ));
    }

    /**
     * Whether a structural comma sits between name-bearing content on both
     * sides. Single-pass: each comma segment is decoration-mapped once with a
     * reused suffix mapper, recording the first and last name-bearing segment;
     * a deciding boundary exists exactly when those differ. The old
     * per-boundary full re-merge plus two fresh SuffixMapper passes never
     * early-returned on hostile all-suffix rows (all ~65k boundaries each
     * re-mapped O(n) tokens); this is linear in the input.
     *
     * @param  array<int|string, string>|null  $suffixes
     * @param  array<string, string>|null  $nicknameDelimiters
     */
    private static function hasDecidingStructuralComma(
        string $original,
        ?array $suffixes,
        ?array $nicknameDelimiters = null,
        ?string $whitespace = null,
    ): bool {
        if (! str_contains($original, ',')) {
            return false;
        }

        $parts = (new NicknameMapper($nicknameDelimiters ?? []))->map(
            self::splitCommaMarkers($original, $whitespace),
        );

        /** @var list<array<int, \Iliaal\NameParser\Part\AbstractPart|string>> $segments */
        $segments = [[]];
        $current = 0;
        foreach ($parts as $part) {
            if ($part === ',') {
                $segments[] = [];
                $current++;

                continue;
            }

            $segments[$current][] = $part;
        }

        ['suffix' => $suffixMapper] = AbstractMapper::decorationAnalyzers(
            $suffixes ?? English::SUFFIXES,
            $nicknameDelimiters ?? [],
        );

        $firstNameBearing = null;
        $lastNameBearing = null;
        foreach ($segments as $index => $segment) {
            if (self::rawNameTokens($suffixMapper->map($segment)) !== []) {
                $firstNameBearing ??= $index;
                $lastNameBearing = $index;
            }
        }

        return $firstNameBearing !== null
            && $lastNameBearing !== null
            && $firstNameBearing !== $lastNameBearing;
    }

    /**
     * Keep structural comma markers without allocating one array entry per
     * empty segment in a delimiter-heavy row.
     *
     * @return list<string>
     */
    private static function splitCommaMarkers(string $original, ?string $whitespace = null): array
    {
        $chunks = preg_split(
            self::tokenSplitPattern($whitespace, false),
            trim($original),
            Text::MAX_INPUT_TOKENS + 1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $pieces = [];

        foreach ($chunks as $chunk) {
            $offset = 0;
            $length = strlen($chunk);

            while (($comma = strpos($chunk, ',', $offset)) !== false) {
                if ($comma > $offset) {
                    $pieces[] = substr($chunk, $offset, $comma - $offset);
                }
                if ($pieces === [] || end($pieces) !== ',') {
                    $pieces[] = ',';
                }

                $offset = $comma + 1;
            }

            if ($offset < $length) {
                $pieces[] = substr($chunk, $offset);
            }
        }

        return $pieces;
    }
}
