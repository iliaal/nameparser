<?php

namespace Iliaal\NameParser;

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
     * @return array{ambiguous: bool, notes: list<string>}
     */
    public static function assess(string $original): array
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $original) ?? '';
        $uniformUpper = $letters !== '' && $letters === mb_strtoupper($letters, 'UTF-8')
            && $letters !== mb_strtolower($letters, 'UTF-8');
        $uniformLower = $letters !== '' && $letters === mb_strtolower($letters, 'UTF-8')
            && $letters !== mb_strtoupper($letters, 'UTF-8');

        $tokens = preg_split('/[\s,]+/u', trim($original), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $notes = [];
        foreach ($tokens as $token) {
            $key = strtolower(str_replace('.', '', $token));
            if (! isset(SuffixMapper::AMBIGUOUS_KEYS[$key])) {
                continue;
            }

            $tokenLetters = preg_replace('/[^\p{L}]/u', '', $token) ?? '';
            $tokenLower = $tokenLetters !== '' && $tokenLetters === mb_strtolower($tokenLetters, 'UTF-8')
                && $tokenLetters !== mb_strtoupper($tokenLetters, 'UTF-8');

            if ($uniformUpper) {
                // an uppercase token is read as a credential and stripped; only
                // a name-leaning key (Do, Ma, Ba...) is genuinely at risk of
                // being a mis-stripped name, so don't flag uppercase "RN"/"PT".
                if (isset(SuffixMapper::NAME_LEANING_KEYS[$key])) {
                    $notes[] = "'{$token}' could be a name or a credential; input casing is uniform";
                }
            } elseif ($uniformLower) {
                $notes[] = "'{$token}' could be a name or a credential; input casing is uniform";
            } elseif ($tokenLower) {
                $notes[] = "'{$token}' could be a name or a credential; token is lowercase";
            }
        }

        return ['ambiguous' => $notes !== [], 'notes' => $notes];
    }
}
