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
     * These get casing and position disambiguation; all other keys always strip.
     */
    public const array AMBIGUOUS_KEYS = [
        'do' => true, 'vi' => true, 'vii' => true, 'viii' => true,
        'ix' => true, 'x' => true, 'ma' => true, 'ms' => true,
        'pe' => true, 'dc' => true, 'pa' => true,
        'ii' => true, 'iii' => true, 'iv' => true, 'mba' => true,
        'ba' => true, 'bs' => true, 'lac' => true, 'np' => true,
        'od' => true, 'pt' => true, 'rd' => true, 'rn' => true,
    ];

    /**
     * The subset of AMBIGUOUS_KEYS that lean toward a real name. Confidence
     * flags these as undecidable in uniform-case input: an uppercase "DO"
     * could be the surname Do, but an uppercase "RN" is almost always a cred.
     */
    public const array NAME_LEANING_KEYS = [
        'do' => true, 'vi' => true, 'ma' => true, 'ba' => true, 'lac' => true,
    ];

    /**
     * AMBIGUOUS_KEYS that also occur as real US surnames per Census data (Ii,
     * Iv, Mba and the related roman numerals). Unlike NAME_LEANING_KEYS these
     * read as credentials under mixed casing, but uniform-case input could
     * equally mean a surname, so Confidence flags an all-caps occurrence.
     * Credentials that are not real names (Rn, Pt, Od...) stay unflagged to
     * keep review volume down on all-caps datasets.
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

        // Uniform-uppercase input has no casing signal; compute it only for candidates.
        $uniformUpper = null;

        // Map leading credentials before initials can split them in "Smith, MD John".
        $leadingSuffixIndexes = $this->reservedParts === 0
            ? $this->mapLeadingSuffixRun($parts)
            : [];
        /** @var array<int, true> $leadingCandidateIndexes */
        $leadingCandidateIndexes = [];
        if ($leadingSuffixIndexes !== []) {
            $uniformUpper = $this->isUniformUpperContext($parts, $this->uniformUpperOverride);

            if (! $uniformUpper) {
                // Skipped nicknames make the last mapped index differ from the run length.
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
        // Precompute opener presence so each suffix-span check stays O(1).
        $spanOpenerPrefix = $this->buildSpanOpenerPrefix($parts);
        for ($k = count($parts) - 1; $k >= 0; $k--) {
            if (isset($leadingSet[$k])) {
                continue;
            }

            $part = $parts[$k];

            // Nicknames and ignored connectors do not end a credential run.
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

                if ($this->isTailNoise($part)) {
                    $noiseIndexes[$k] = true;

                    continue;
                }

                $uniformUpper ??= $this->isUniformUpperContext($parts, $this->uniformUpperOverride);

                // Crossing a name ends the candidate run: JM in "John Paul JM Smith MD" is initials.
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

            // Preserve nickname closers such as "(Bob Jr)"; an unpaired "MD)" can still be a suffix.
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

        // Unknown candidates require a dictionary anchor.
        if ($suffixIndexes === []) {
            return $parts;
        }

        // Preserve all-caps given names before the first anchor: "Smith, JOHN MD".
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
            // ALL-CAPS or an exact dictionary rendering (LAc) identifies a credential.
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

        // After a given name, I/V/X denote middle initials. Credential-only
        // segments and numeric ordinals retain the suffix reading.
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

        // A lone roman letter after a firstname more likely denotes a surname or initial.
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

            if ($part instanceof Nickname) {
                continue;
            }

            if (! is_string($part) || ! $this->isSuffix($part)) {
                break;
            }

            // Junior/Senior at the head of a given segment are names.
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
     * Only candidates adjacent to the leading credential run can join it.
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
     * Record earlier unmatched openers per delimiter and position for O(1) tail checks.
     * Return an empty table when no token can open a span.
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

            foreach ($parts as $index => $part) {
                $column[] = $seen;

                if (! is_string($part)) {
                    continue;
                }

                if ($symmetric) {
                    $delta = self::symmetricBoundaryDelta($part, $open);
                    if ($delta === 0
                        && self::isStandaloneSymmetricDelimiter($part, $open)
                        && self::hasLaterSymmetricCloser($parts, $index, $open)) {
                        $delta = 1;
                    }
                    if ($delta > 0) {
                        $seen = true;
                    } elseif ($delta < 0) {
                        $seen = false;
                    }
                } elseif (substr_count($part, $open) > substr_count($part, $close)) {
                    $seen = true;
                }
            }

            $prefix[] = $column;
        }

        return $prefix;
    }

    /**
     * An unbalanced closer is a span tail only when an earlier opener exists.
     * Self-contained "(MD)" and stray "MD)" remain eligible suffixes.
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
                if (self::symmetricBoundaryDelta($part, $open) >= 0) {
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
     * Symmetric delimiters open only at a token start and close only at a token
     * end. Internal apostrophes therefore have no delimiter state effect.
     */
    private static function symmetricBoundaryDelta(string $part, string $delimiter): int
    {
        $delimiterLength = strlen($delimiter);
        if ($delimiterLength === 0) {
            return 0;
        }
        $partLength = strlen($part);
        $leading = 0;
        while ($leading * $delimiterLength < $partLength
            && substr($part, $leading * $delimiterLength, $delimiterLength) === $delimiter) {
            $leading++;
        }
        if ($leading > 1 && $leading * $delimiterLength === $partLength) {
            return $leading % 2 === 1 ? 1 : 0;
        }

        $trailing = 0;
        $trailingLimit = max(0, $partLength - ($leading * $delimiterLength));
        while ($trailing * $delimiterLength < $trailingLimit
            && substr(
                $part,
                $partLength - ($trailing + 1) * $delimiterLength,
                $delimiterLength,
            ) === $delimiter) {
            $trailing++;
        }

        return $leading - $trailing;
    }

    private static function isStandaloneSymmetricDelimiter(string $part, string $delimiter): bool
    {
        return $part === $delimiter;
    }

    /**
     * @param  PartArray  $parts
     */
    private static function hasLaterSymmetricCloser(array $parts, int $openIndex, string $delimiter): bool
    {
        $delimiterLength = strlen($delimiter);
        for ($index = $openIndex + 1, $count = count($parts); $index < $count; $index++) {
            $part = $parts[$index];
            if (! is_string($part) || ! str_ends_with($part, $delimiter)) {
                continue;
            }

            if (str_starts_with($part, $delimiter) && strlen($part) >= $delimiterLength * 2) {
                continue;
            }

            return true;
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
        // Nicknames must not change the preceding-initial signal in "John A (Bob) MA".
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
