<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Ignored;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Suffix;
use Iliaal\NameParser\Text;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class SuffixMapper extends AbstractMapper
{
    private ?bool $uniformUpperOverride = null;

    /**
     * Suffix keys that also occur as real given names / surnames (Vietnamese
     * "Do"/"Vi", Chinese "Ma", roman numerals, short allied-health creds).
     * These get casing + position disambiguation; everything else keeps the
     * original always-strip behavior.
     */
    public const array AMBIGUOUS_KEYS = [
        'do' => true, 'vi' => true, 'vii' => true, 'viii' => true,
        'ix' => true, 'x' => true, 'ma' => true, 'ms' => true,
        'pe' => true, 'dc' => true, 'pa' => true,
        // multi-char roman numerals + creds that are also real US surnames
        // (Census: Ii, Iv, Mba); casing still strips the genuine credential.
        'ii' => true, 'iii' => true, 'iv' => true, 'mba' => true,
        // short allied-health creds that are also real names ("Ba", "Lac",
        // initials "Rn"/"Pt"); casing still strips the uppercase credential.
        'ba' => true, 'bs' => true, 'lac' => true, 'np' => true,
        'od' => true, 'pt' => true, 'rd' => true, 'rn' => true,
    ];

    /**
     * The subset of AMBIGUOUS_KEYS that lean toward being a real name rather
     * than a credential. Used by Confidence to decide whether an uppercase
     * token in uniform-case input is genuinely undecidable: an uppercase "DO"
     * could be the surname Do, but an uppercase "RN" is almost always a cred.
     */
    public const array NAME_LEANING_KEYS = [
        'do' => true, 'vi' => true, 'ma' => true, 'ba' => true, 'lac' => true,
    ];

    /**
     * AMBIGUOUS_KEYS that also occur as real US surnames per Census data (Ii,
     * Iv, Mba and the related roman numerals). Distinct from NAME_LEANING_KEYS:
     * under any single casing these read as a credential, but in uniform-case
     * input where casing carries no signal they could equally be a surname, so
     * Confidence treats an all-caps occurrence as undecidable. Clean creds that
     * are not real names (Rn, Pt, Od...) stay suppressed to keep review noise
     * down on the all-caps datasets this parser targets.
     */
    public const array SURNAME_COLLIDING_KEYS = [
        'ii' => true, 'iii' => true, 'iv' => true, 'mba' => true,
    ];

    /**
     * @var array<string, string>
     */
    private array $spanDelimiters;

    private string $spanDelimiterBytes;

    /**
     * @param  array<int|string, string>  $suffixes
     * @param  array<string, string>  $nicknameDelimiters  lets the suffix scan
     *   recognize a token that belongs to a multi-token nickname span ("Jr)")
     *   and leave it for NicknameMapper instead of consuming it as a credential
     */
    public function __construct(
        protected array $suffixes,
        protected bool $matchSinglePart = false,
        protected int $reservedParts = 2,
        array $nicknameDelimiters = [],
    ) {
        $this->spanDelimiters = Text::sanitizeNicknameDelimiters($nicknameDelimiters);
        $this->spanDelimiterBytes = implode('', array_merge(
            array_keys($this->spanDelimiters),
            array_values($this->spanDelimiters),
        ));
    }

    public function matchesSinglePart(): bool
    {
        return $this->matchSinglePart;
    }

    public function getReservedParts(): int
    {
        return $this->reservedParts;
    }

    /**
     * @internal Comma-pipeline whole-input casing signal. Always reset after
     * the parse; the mapper is memoized. Not part of the stable public API.
     */
    public function setUniformUpperOverride(?bool $override): void
    {
        $this->uniformUpperOverride = $override;
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $parts = $this->normalizeParts($parts);

        if ($this->isMatchingSinglePart($parts)) {
            $first = $parts[0];
            if (is_string($first)) {
                $parts[0] = new Suffix($first, $this->suffixes[$this->getKey($first)]);
            }

            return $parts;
        }

        // casing carries no signal under uniform-uppercase input, so unknown
        // all-caps tokens are not treated as credential candidates there.
        // Computed lazily: most rows never reach the candidate check.
        $uniformUpper = null;

        // in the comma given-segment config a leading credential run precedes
        // the given name ("Smith, MD John"); map it before the tail scan so it
        // is not left as a stray token that gets split into phantom initials.
        $leadingSuffixIndexes = $this->reservedParts === 0
            ? $this->mapLeadingSuffixRun($parts)
            : [];
        /** @var array<int, true> $leadingCandidateIndexes */
        $leadingCandidateIndexes = [];
        if ($leadingSuffixIndexes !== []) {
            $uniformUpper = $this->isUniformUpperContext($parts, $this->uniformUpperOverride);

            if (! $uniformUpper) {
                // the run may skip over nickname parts, so continue from the
                // slot after its last mapped index, not from its length
                $leadingCandidateIndexes = $this->mapLeadingUnknownCredentialRun(
                    $parts,
                    $leadingSuffixIndexes[count($leadingSuffixIndexes) - 1] + 1,
                );
            }
        }
        /** @var array<int, true> $leadingSet */
        $leadingSet = array_fill_keys($leadingSuffixIndexes, true);

        /** @var list<int> $suffixIndexes */
        $suffixIndexes = [];
        /** @var array<int, true> $noiseIndexes */
        $noiseIndexes = [];
        /** @var array<int, true> $candidateIndexes */
        $candidateIndexes = [];
        $mappedSuffix = false;
        $crossedBridge = false;
        // prefix opener-presence table: one bool per (delimiter pair, token
        // position) recording whether an unmatched opener appeared in any
        // earlier raw token, so the per-suffix isSpanTailToken() check is O(1)
        // instead of re-scanning all earlier parts with paired substr_count.
        $spanOpenerPrefix = $this->buildSpanOpenerPrefix($parts);
        for ($k = count($parts) - 1; $k >= 0; $k--) {
            if (isset($leadingSet[$k])) {
                continue;
            }

            $part = $parts[$k];

            // decoration parts are transparent to the credential scan: a
            // nickname or an ignored connector ("MD & PhD") sits inside the
            // tail without ending it
            if ($part instanceof Nickname || $part instanceof Ignored) {
                continue;
            }

            if (! is_string($part)) {
                break;
            }

            if (! $this->isSuffix($part)) {
                if (! $this->canSkipInterruptedTailAtIndex($k)) {
                    break;
                }

                // noise keeps precedence over the candidate check so a
                // placeholder ("Unknown") is dropped, not carried as a cred.
                if ($this->isTailNoise($part)) {
                    $noiseIndexes[$k] = true;

                    continue;
                }

                $uniformUpper ??= $this->isUniformUpperContext($parts, $this->uniformUpperOverride);

                // a candidate is only credible inside the contiguous credential
                // run at the tail; once a preserved name token has been crossed
                // ("John Paul JM Smith MD"), an all-caps token is a combined
                // initial, not a stray credential.
                if (! $crossedBridge && ! $uniformUpper && $this->isUnknownCredentialCandidate($part)) {
                    $candidateIndexes[$k] = true;

                    continue;
                }

                if (! $mappedSuffix) {
                    break;
                }

                $crossedBridge = true;

                continue;
            }

            // the closer token of a multi-token nickname span keys as a suffix
            // ("Jr)" in "(Bob Jr)"); consuming it would orphan the opener and
            // shred the span. A stray closer with no earlier opener
            // ("John Smith MD)") is ordinary trailing punctuation and maps.
            if ($this->isSpanTailToken($parts, $k, $spanOpenerPrefix)) {
                break;
            }

            if (! $this->canMapAtIndex($parts, $part, $k)) {
                break;
            }

            $suffixIndexes[] = $k;
            $mappedSuffix = true;
        }

        if ($suffixIndexes === []) {
            $candidateIndexes = [];
        }
        $candidateIndexes += $leadingCandidateIndexes;

        $suffixIndexes = array_merge($leadingSuffixIndexes, $suffixIndexes);

        // candidates ride along only when a real dictionary suffix anchored the
        // tail; with none, the outcome stays byte-identical to no stripping.
        if ($suffixIndexes === []) {
            return $parts;
        }

        // a given segment must not map entirely to credentials when it started
        // with name-shaped tokens: "Smith, JOHN MD" keeps JOHN as the first
        // name, same as the comma-separated "Smith, JOHN, MD". Candidates left
        // of the leftmost dictionary suffix are the name, not stray creds.
        if ($this->reservedParts === 0 && $candidateIndexes !== []) {
            /** @var array<int, true> $creditSet */
            $creditSet = array_fill_keys($suffixIndexes, true) + $candidateIndexes + $noiseIndexes;
            $hasSurvivor = false;
            foreach ($parts as $i => $survivor) {
                if (is_string($survivor) && ! isset($creditSet[$i])) {
                    $hasSurvivor = true;

                    break;
                }
            }

            if (! $hasSurvivor) {
                $minDictionaryIndex = min($suffixIndexes);
                foreach (array_keys($candidateIndexes) as $i) {
                    if ($i < $minDictionaryIndex) {
                        unset($candidateIndexes[$i]);
                    }
                }
            }
        }

        return $this->rewriteCredentialTail($parts, $suffixIndexes, $noiseIndexes, $candidateIndexes);
    }

    /**
     * @param  PartArray  $parts
     * @param  list<int>  $suffixIndexes
     * @param  array<int, true>  $noiseIndexes
     * @param  array<int, true>  $candidateIndexes
     * @return PartArray
     */
    private function rewriteCredentialTail(array $parts, array $suffixIndexes, array $noiseIndexes, array $candidateIndexes): array
    {
        /** @var array<int, true> $suffixIndexSet */
        $suffixIndexSet = array_fill_keys($suffixIndexes, true);

        // dictionary suffixes and unknown-credential candidates both render as
        // Suffix parts, merged in original left-to-right order
        $creditIndexes = array_keys($suffixIndexSet + $candidateIndexes);
        sort($creditIndexes);
        /** @var array<int, true> $creditSet */
        $creditSet = array_fill_keys($creditIndexes, true);
        $firstCreditIndex = $creditIndexes[0];

        $rewritten = [];

        for ($i = 0; $i < $firstCreditIndex; $i++) {
            if (isset($noiseIndexes[$i])) {
                continue;
            }

            $rewritten[] = $parts[$i];
        }

        for ($i = $firstCreditIndex; $i < count($parts); $i++) {
            if (isset($creditSet[$i]) || isset($noiseIndexes[$i])) {
                continue;
            }

            $rewritten[] = $parts[$i];
        }

        foreach ($creditIndexes as $index) {
            $part = $parts[$index];

            if (! is_string($part)) {
                continue;
            }

            $rewritten[] = isset($suffixIndexSet[$index])
                ? new Suffix($part, $this->suffixes[$this->getKey($part)])
                : new Suffix($part);
        }

        return $rewritten;
    }

    /**
     * @param  PartArray  $parts
     */
    protected function isMatchingSinglePart(array $parts): bool
    {
        if (! $this->matchSinglePart) {
            return false;
        }

        if (count($parts) !== 1 || ! is_string($parts[0])) {
            return false;
        }

        return $this->isSuffix($parts[0]);
    }

    protected function isSuffix(AbstractPart|string $part): bool
    {
        if ($part instanceof AbstractPart) {
            return false;
        }

        if (! array_key_exists($this->getKey($part), $this->suffixes)) {
            return false;
        }

        if ($this->isAmbiguous($part)) {
            // casing as signal: ALL-CAPS reads as a credential ("DO", "VI"),
            // Title/lower case reads as a name token ("Do", "Vi"). An exact
            // mixed-case rendered form such as "LAc" is also a credential.
            return $this->matchesCredentialCase($part);
        }

        return true;
    }

    protected function isAmbiguous(string $part): bool
    {
        return isset(self::AMBIGUOUS_KEYS[$this->getKey($part)]);
    }

    /**
     * @param  PartArray  $parts
     */
    protected function canMapAtIndex(array $parts, string $part, int $index): bool
    {
        if ($this->getKey($part) === 'ma' && $this->isPrecededBySingleInitial($parts, $index)) {
            return false;
        }

        // a single letter after a real given name in a comma given segment is a
        // middle initial, not a roman-numeral suffix: "Lapin, Michelle I" is
        // registry LAST, FIRST MI form. A credential-only segment ("Smith, MD I")
        // has no preceding name token and keeps the roman reading. Digit keys
        // (German ordinals "2.") carry no initial reading and are exempt.
        $key = $this->getKey($part);
        if ($this->reservedParts === 0
            && mb_strlen($key, 'UTF-8') < 2
            && preg_match('/\p{L}/u', $key) === 1
            && $this->hasPrecedingNameToken($parts, $index)) {
            return false;
        }

        if ($index > $this->reservedParts - 1) {
            return true;
        }

        if ($this->reservedParts !== 2 || $index !== 1) {
            return false;
        }

        $key = $this->getKey($part);

        // a bare single-letter roman numeral right after the first name is far
        // more likely a surname or stray initial ("Malcolm X") than a suffix,
        // so the relaxed slot only takes multi-character suffix keys
        if (mb_strlen($key, 'UTF-8') < 2) {
            return false;
        }

        return ! in_array($key, ['junior', 'senior'], true);
    }

    private function canSkipInterruptedTailAtIndex(int $index): bool
    {
        return $index > $this->reservedParts - 1;
    }

    /**
     * leading run of dictionary suffixes at the head of a comma given segment
     * ("MD John"), returned as the indexes to map. Only applies when a
     * non-suffix name token remains after the run, so a segment that is nothing
     * but credentials falls through to the normal tail logic instead.
     *
     * @param  PartArray  $parts
     * @return list<int>
     */
    private function mapLeadingSuffixRun(array $parts): array
    {
        /** @var list<int> $run */
        $run = [];
        $count = count($parts);
        $k = 0;

        for (; $k < $count; $k++) {
            $part = $parts[$k];

            // an already-extracted nickname does not end the leading run:
            // "Smith, (Doc) MD John" still carries the credential run
            if ($part instanceof Nickname) {
                continue;
            }

            if (! is_string($part) || ! $this->isSuffix($part)) {
                break;
            }

            // junior/senior at the head of a given segment are names
            // ("Smith, Junior Paul"), not leading credentials like "MD John"
            $key = $this->getKey($part);
            if ($key === 'junior' || $key === 'senior') {
                break;
            }

            $run[] = $k;
        }

        if ($run === []) {
            return [];
        }

        for (; $k < $count; $k++) {
            $part = $parts[$k];

            if (is_string($part) && ! $this->isSuffix($part)) {
                return $run;
            }
        }

        return [];
    }

    /**
     * Unknown credentials can ride on a dictionary credential only while they
     * are immediately adjacent to its leading run. The first name token ends
     * the run, so a later uppercase name is never pulled backward into it.
     *
     * @param  PartArray  $parts
     * @return array<int, true>
     */
    private function mapLeadingUnknownCredentialRun(array $parts, int $start): array
    {
        /** @var array<int, true> $run */
        $run = [];

        for ($index = $start; $index < count($parts); $index++) {
            $part = $parts[$index];
            if ($part instanceof Nickname) {
                continue;
            }

            if (! is_string($part) || ! $this->isUnknownCredentialCandidate($part)) {
                break;
            }

            $run[$index] = true;
        }

        return $run;
    }

    /**
     * Prefix opener-presence table, one column per span delimiter pair: the
     * column entry at each token position records whether an unmatched opener
     * appeared in any earlier raw token. Built once per map() in a single
     * linear pass so the per-suffix isSpanTailToken() check below is O(1).
     * Empty when no token can open a span (no delimiters configured, or no
     * delimiter byte anywhere in the row), in which case nothing is a tail.
     *
     * @param  PartArray  $parts
     * @return list<list<bool>>
     */
    private function buildSpanOpenerPrefix(array $parts): array
    {
        if ($this->spanDelimiterBytes === '') {
            return [];
        }

        $hasDelimiterByte = false;

        foreach ($parts as $part) {
            if (is_string($part) && strpbrk($part, $this->spanDelimiterBytes) !== false) {
                $hasDelimiterByte = true;

                break;
            }
        }

        if (! $hasDelimiterByte) {
            return [];
        }

        $prefix = [];

        foreach ($this->spanDelimiters as $open => $close) {
            $open = (string) $open;
            $symmetric = $open === $close;
            $seen = false;
            $column = [];

            foreach ($parts as $part) {
                $column[] = $seen;

                if (! is_string($part)) {
                    continue;
                }

                if ($symmetric
                    ? substr_count($part, $open) % 2 === 1
                    : substr_count($part, $open) > substr_count($part, $close)) {
                    $seen = true;
                }
            }

            $prefix[] = $column;
        }

        return $prefix;
    }

    /**
     * true when the token's unbalanced closing delimiter pairs with an
     * unmatched opener in an earlier raw token, i.e. the token is the tail of
     * a multi-token nickname span ("(Bob Jr)"). A self-contained "(MD)" is
     * balanced, and a stray closer with no earlier opener is not a span tail.
     * The earlier-opener half is an O(1) lookup into the prefix table built by
     * buildSpanOpenerPrefix(), not a re-scan of all earlier parts.
     *
     * @param  PartArray  $parts
     * @param  list<list<bool>>  $spanOpenerPrefix
     */
    private function isSpanTailToken(array $parts, int $index, array $spanOpenerPrefix = []): bool
    {
        $part = $parts[$index];
        if (! is_string($part)
            || $this->spanDelimiterBytes === ''
            || $spanOpenerPrefix === []
            || strpbrk($part, $this->spanDelimiterBytes) === false) {
            return false;
        }

        $pair = 0;

        foreach ($this->spanDelimiters as $open => $close) {
            $open = (string) $open;
            $symmetric = $open === $close;

            if ($symmetric) {
                if (substr_count($part, $open) % 2 !== 1) {
                    $pair++;

                    continue;
                }
            } elseif (substr_count($part, $close) <= substr_count($part, $open)) {
                $pair++;

                continue;
            }

            if ($spanOpenerPrefix[$pair][$index] ?? false) {
                return true;
            }

            $pair++;
        }

        return false;
    }

    /**
     * whether a raw name-shaped token (letters, not a credential, not noise)
     * precedes the given index; decoration parts and extracted nicknames are
     * transparent to the check
     *
     * @param  PartArray  $parts
     */
    private function hasPrecedingNameToken(array $parts, int $index): bool
    {
        for ($i = 0; $i < $index; $i++) {
            $part = $parts[$i];
            if (! is_string($part)) {
                continue;
            }

            if ($this->isSuffix($part) || Text::isCredentialTailNoise($part)) {
                continue;
            }

            if (Text::letters($part) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * an all-caps unknown token in the credential tail ("FACS", "CCRN"): not a
     * dictionary suffix, but its casing reads as a credential. Guarded by the
     * caller against uniform-uppercase input, where caps carry no signal.
     */
    private function isUnknownCredentialCandidate(string $part): bool
    {
        if (array_key_exists($this->getKey($part), $this->suffixes)) {
            return false;
        }

        return Text::isUnknownCredentialCandidate($part);
    }

    private function isTailNoise(string $part): bool
    {
        return Text::isCredentialTailNoise($part);
    }

    /**
     * @param  PartArray  $parts
     */
    private function isPrecededBySingleInitial(array $parts, int $index): bool
    {
        // a nickname between the initial and the token must not flip the
        // classification ("John A (Bob) MA" reads like "John A MA"), whether it
        // was already extracted or is still a raw self-contained span
        for ($i = $index - 1; $i >= 0; $i--) {
            $previous = $parts[$i];

            if ($previous instanceof Nickname) {
                continue;
            }

            if (is_string($previous)
                && Text::isSpanWrappedToken($previous, $this->spanDelimiters)) {
                continue;
            }

            if (! is_string($previous)) {
                return false;
            }

            return Text::graphemeLengthUpTo(Text::letters($previous), 2) === 1;
        }

        return false;
    }

    protected function isUpperCase(string $part): bool
    {
        return Text::isUpperCase($part);
    }

    private function matchesCredentialCase(string $part): bool
    {
        return $this->isUpperCase($part)
            || Text::letters($part) === Text::letters($this->suffixes[$this->getKey($part)]);
    }
}
