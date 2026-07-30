<?php

namespace Iliaal\NameParser;

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
        if ($tokens !== null) {
            Text::assertInputTokenCount(count($tokens));
        }

        if (! mb_check_encoding($original, 'UTF-8')) {
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

        // an honorific that is also a real name costs a name part when it leads a
        // bare two-token input: "Lord Ashcroft" reads as title plus surname, but
        // first name Lord is equally valid and nothing in the string decides it.
        // A comma settles the question structurally ("Lord, Jack"), and a third
        // token leaves a given name behind either way, so neither is flagged.
        $lead = $tokens[0] ?? '';
        if ($lead !== '' && count($tokens) === 2 && ! str_contains($original, ',')) {
            $key = Text::key($lead);
            if (isset(SalutationMapper::NAME_COLLIDING_KEYS[$key])
                && ($salutations === null || array_key_exists($key, $salutations))) {
                $notes["'{$lead}' could be a name or a salutation; nothing in the input decides it"] = true;
            }
        }

        return ['ambiguous' => $notes !== [], 'notes' => array_keys($notes)];
    }
}
