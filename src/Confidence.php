<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
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
     * @param  array<int|string, string>|null  $suffixes
     * @param  array<int|string, string>|null  $salutations
     * @param  list<string>|null  $tokens
     * @return array{ambiguous: bool, notes: list<string>}
     */
    public static function assess(
        string $original,
        ?array $suffixes = null,
        ?array $salutations = null,
        ?array $tokens = null,
    ): array {
        Text::assertInputByteBudget($original);
        $validUtf8 = mb_check_encoding($original, 'UTF-8');

        if ($tokens !== null) {
            self::assertSuppliedTokenBudgets($tokens);
            self::assertOriginalTokenBudget($original, $validUtf8);
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
                '/[\s,]+/u',
                trim($original),
                Text::MAX_INPUT_TOKENS + 1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            Text::assertInputTokenCount(count($tokens));
        }

        // derive uniform-case from tokens (same shape as the parser), never from
        // a whole-string letters() strip on multi-megabyte hostile rows
        $uniformUpper = true;
        $uniformLower = true;
        $hasCased = false;
        foreach ($tokens as $token) {
            $letters = Text::letters($token);
            if ($letters === '') {
                continue;
            }
            $hasCased = $hasCased
                || $letters !== mb_strtolower($letters, 'UTF-8')
                || $letters !== mb_strtoupper($letters, 'UTF-8');
            if ($letters !== mb_strtoupper($letters, 'UTF-8')) {
                $uniformUpper = false;
            }
            if ($letters !== mb_strtolower($letters, 'UTF-8')) {
                $uniformLower = false;
            }
        }
        if (! $hasCased) {
            $uniformUpper = false;
            $uniformLower = false;
        }

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
            $nameTokens = self::rawNameTokens(self::mapDecorations($tokens, $suffixes));
            if (count($nameTokens) === 2
                && ! self::hasDecidingStructuralComma($original, $suffixes)) {
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

    private static function assertOriginalTokenBudget(string $original, bool $validUtf8): void
    {
        if (strlen($original) < (Text::MAX_INPUT_TOKENS * 2) + 1) {
            return;
        }

        $tokens = preg_split(
            $validUtf8 ? '/[\s,]+/u' : '/[\s,]+/',
            trim($original),
            Text::MAX_INPUT_TOKENS + 1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        Text::assertInputTokenCount(count($tokens));
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<int|string, string>|null  $suffixes
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private static function mapDecorations(array $tokens, ?array $suffixes): array
    {
        $parts = (new NicknameMapper())->map($tokens);

        return (new SuffixMapper($suffixes ?? English::SUFFIXES, true, 0))->map($parts);
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
     * @param  array<int|string, string>|null  $suffixes
     */
    private static function hasDecidingStructuralComma(string $original, ?array $suffixes): bool
    {
        if (! str_contains($original, ',')) {
            return false;
        }

        $parts = (new NicknameMapper())->map(self::splitCommaMarkers($original));

        /** @var list<array<int, \Iliaal\NameParser\Part\AbstractPart|string>> $segments */
        $segments = [[]];
        foreach ($parts as $part) {
            if ($part === ',') {
                $segments[] = [];

                continue;
            }

            $segments[array_key_last($segments)][] = $part;
        }

        for ($boundary = 1; $boundary < count($segments); $boundary++) {
            $left = array_merge(...array_slice($segments, 0, $boundary));
            $right = array_merge(...array_slice($segments, $boundary));

            if (self::rawNameTokens(self::mapPartDecorations($left, $suffixes)) !== []
                && self::rawNameTokens(self::mapPartDecorations($right, $suffixes)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep structural comma markers without allocating one array entry per
     * empty segment in a delimiter-heavy row.
     *
     * @return list<string>
     */
    private static function splitCommaMarkers(string $original): array
    {
        $chunks = preg_split(
            '/\s+/u',
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

    /**
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     * @param  array<int|string, string>|null  $suffixes
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private static function mapPartDecorations(array $parts, ?array $suffixes): array
    {
        return (new SuffixMapper($suffixes ?? English::SUFFIXES, true, 0))->map($parts);
    }
}
