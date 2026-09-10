<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Suffix;

final class CommaCredentialTail
{
    /**
     * @param  array<int|string, string>  $suffixes  merged suffix dictionary
     * @param  \Closure(string): bool  $isUnknownCandidate  per-parse memoized unknown-candidate test
     * @param  \Closure(list<string>, bool): array<int, AbstractPart|string>  $mapSuffixes  suffix-mapper ride for mixed segments
     * @param  \Closure(string): bool|null  $isCredentialRider  per-parse memoized rider test; Text::isCredentialTailRider() when null
     */
    public function __construct(
        private array $suffixes,
        private \Closure $isUnknownCandidate,
        private \Closure $mapSuffixes,
        private ?\Closure $isCredentialRider = null,
    ) {}

    /**
     * classify the post-first-comma segments: a segment whose every token is a
     * credential (dictionary suffix under the casing rule, or an all-caps
     * unknown-credential candidate) becomes Suffix parts; the rest are returned
     * verbatim to fold back into the given segment.
     *
     * Unknown all-caps candidates ride only inside a contiguous credential run
     * anchored by a real dictionary suffix: post-anchor pure candidate segments
     * (`MD, FACS`), same-segment tails (`John Smith MD FACS`), and a trailing
     * candidate run in a mixed segment that a later dictionary segment anchors
     * (`John FACS, MD`). A pure candidate segment with no prior anchor
     * (`Smith, JOHN, MD` / `Smith, FACS, MD`) is kept as a name: it is
     * indistinguishable from an all-caps given name, so promoting it would
     * swallow real names into the suffix.
     *
     * @param  list<string>  $tailSegments
     * @return array<int, AbstractPart|string>
     */
    public function split(array $tailSegments, bool $uniformInput): array
    {
        /** @var array<int, AbstractPart|string> $parts */
        $parts = [];
        /** @var list<list<string>> $pendingCandidateRuns trailing UnknownCandidate peels from mixed segments */
        $pendingCandidateRuns = [];
        $credentialRunAnchored = false;
        $hasCredentialAnchor = false;

        foreach ($tailSegments as $segment) {
            $trimmed = trim($segment);
            if ($trimmed === '') {
                continue;
            }

            $tokens = self::tokenize($trimmed);
            if ($tokens === []) {
                continue;
            }

            [$tokenClasses, $hasDictionarySuffix] = $this->classifyTokens($tokens, $uniformInput);

            if ($hasDictionarySuffix) {
                $hasCredentialAnchor = true;
            }

            if (! self::isCredentialOnlySegment($tokenClasses)) {
                // A name segment ends the credential run; unanchored candidates stay names.
                array_push($parts, ...self::flattenCandidateRuns($pendingCandidateRuns, false));
                $pendingCandidateRuns = [];

                // Use the suffix mapper so mixed segments follow the space-form policy.
                if ($hasDictionarySuffix) {
                    foreach (($this->mapSuffixes)(array_column($tokenClasses, 0), $uniformInput) as $part) {
                        $parts[] = $part;
                    }

                    // Only a tail-ending run anchors the next segment: "MD John, PAUL" keeps PAUL.
                    $tokenClassesCount = count($tokenClasses);
                    $credentialRunAnchored = $tokenClasses[$tokenClassesCount - 1][1] !== TokenCredentialClass::Name;

                    continue;
                }

                $credentialRunAnchored = false;

                [$headTokens, $trailingCandidates] = self::splitTrailingCandidates($tokenClasses);

                foreach (($this->mapSuffixes)($headTokens, $uniformInput) as $part) {
                    $parts[] = $part;
                }

                if ($trailingCandidates !== []) {
                    $pendingCandidateRuns[] = $trailingCandidates;
                }

                continue;
            }

            if ($hasDictionarySuffix) {
                array_push($parts, ...self::flattenCandidateRuns($pendingCandidateRuns, true));
                $pendingCandidateRuns = [];
                $credentialRunAnchored = true;

                foreach ($tokenClasses as [$token, $class]) {
                    $parts[] = $this->newCredentialSuffix($token, $class);
                }

                continue;
            }

            if ($credentialRunAnchored) {
                foreach ($tokens as $token) {
                    $parts[] = new Suffix($token);
                }
            } else {
                // A later anchor must not swallow all-caps given names: "Smith, JOHN, MD".
                foreach ($tokens as $token) {
                    $parts[] = $token;
                }
            }
        }

        array_push($parts, ...self::flattenCandidateRuns($pendingCandidateRuns, false));

        // Any tail anchor drops placeholders and punctuation across the whole
        // given side. The surname segment is never purged; no anchor means no purge.
        if ($hasCredentialAnchor) {
            $parts = array_values(array_filter(
                $parts,
                static fn(AbstractPart|string $part): bool => ! is_string($part)
                    || ! Text::isCredentialTailNoise($part),
            ));
        }

        return $parts;
    }

    /**
     * every token of a comma tail reads as a credential, with at least one not
     * in the dictionary. An already-mapped Suffix rides along, so a mixed tail
     * ("Yates, MOT, OTR/L") still qualifies; anything name-shaped disqualifies.
     *
     * @param  array<int, AbstractPart|string>  $givenParts
     */
    public function isUnknownTail(array $givenParts): bool
    {
        $hasUnknown = false;
        $isUnknownCandidate = $this->isUnknownCandidate;
        $isCredentialRider = $this->isCredentialRider ?? Text::isCredentialTailRider(...);

        foreach ($givenParts as $part) {
            if ($part instanceof Suffix) {
                continue;
            }

            if (! is_string($part)) {
                return false;
            }

            if ($part === '') {
                continue;
            }

            if ($isUnknownCandidate($part)) {
                // Dictionary-only tails use ordinary comma parsing for canonical rendering.
                if (! array_key_exists(Text::key($part), $this->suffixes)) {
                    $hasUnknown = true;
                }

                continue;
            }

            // Short or numeric fragments ("PHARM D", "OTA/L 2838") need a real candidate;
            // riders alone must not turn "Assam, P" into a credential tail.
            if ($isCredentialRider($part)) {
                continue;
            }

            return false;
        }

        return $hasUnknown;
    }

    /**
     * @param  array<int, AbstractPart|string>  $givenParts
     * @return array<int, AbstractPart>
     */
    public function creditParts(array $givenParts): array
    {
        $parts = [];

        foreach ($givenParts as $part) {
            if (! is_string($part)) {
                $parts[] = $part;

                continue;
            }

            if ($part === '') {
                continue;
            }

            $key = Text::key($part);
            $parts[] = array_key_exists($key, $this->suffixes)
                ? new Suffix($part, $this->suffixes[$key])
                : new Suffix($part);
        }

        return $parts;
    }

    /**
     * @param  list<string>  $tokens
     * @return array{0: list<array{0: string, 1: TokenCredentialClass}>, 1: bool}
     */
    private function classifyTokens(array $tokens, bool $uniformInput): array
    {
        $tokenClasses = [];
        $hasDictionarySuffix = false;
        foreach ($tokens as $token) {
            $class = $this->credentialClass($token, $uniformInput);

            if ($class === TokenCredentialClass::DictionaryCredential) {
                $hasDictionarySuffix = true;
            }

            $tokenClasses[] = [$token, $class];
        }

        return [$tokenClasses, $hasDictionarySuffix];
    }

    private function credentialClass(string $token, bool $uniformInput): TokenCredentialClass
    {
        $key = Text::key($token);

        if (array_key_exists($key, $this->suffixes)) {
            if (isset(SuffixMapper::AMBIGUOUS_KEYS[$key])) {
                return Text::matchesCredentialCase($token, $this->suffixes[$key])
                    ? TokenCredentialClass::DictionaryCredential
                    : TokenCredentialClass::Name;
            }

            return TokenCredentialClass::DictionaryCredential;
        }

        if (! $uniformInput && ($this->isUnknownCandidate)($token)) {
            return TokenCredentialClass::UnknownCandidate;
        }

        return TokenCredentialClass::Name;
    }

    private function newCredentialSuffix(string $token, TokenCredentialClass $class): Suffix
    {
        return $class === TokenCredentialClass::DictionaryCredential
            ? new Suffix($token, $this->suffixes[Text::key($token)])
            : new Suffix($token);
    }

    /**
     * @param  list<array{0: string, 1: TokenCredentialClass}>  $tokenClasses
     */
    private static function isCredentialOnlySegment(array $tokenClasses): bool
    {
        if ($tokenClasses === []) {
            return false;
        }

        foreach ($tokenClasses as [, $class]) {
            if ($class === TokenCredentialClass::Name) {
                return false;
            }
        }

        return true;
    }

    /**
     * Defer mixed-segment tails so a later dictionary segment can anchor them.
     * Leave all-candidate segments intact for the pure-segment path.
     *
     * @param  list<array{0: string, 1: TokenCredentialClass}>  $tokenClasses
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function splitTrailingCandidates(array $tokenClasses): array
    {
        $count = count($tokenClasses);
        $lastNonCandidate = $count - 1;

        while ($lastNonCandidate >= 0 && $tokenClasses[$lastNonCandidate][1] === TokenCredentialClass::UnknownCandidate) {
            $lastNonCandidate--;
        }

        if ($lastNonCandidate < 0 || $lastNonCandidate === $count - 1) {
            return [array_column($tokenClasses, 0), []];
        }

        $head = [];
        for ($i = 0; $i <= $lastNonCandidate; $i++) {
            $head[] = $tokenClasses[$i][0];
        }

        $trailing = [];
        for ($i = $lastNonCandidate + 1; $i < $count; $i++) {
            $trailing[] = $tokenClasses[$i][0];
        }

        return [$head, $trailing];
    }

    /**
     * @param  list<list<string>>  $runs
     * @return array<int, AbstractPart|string>
     */
    private static function flattenCandidateRuns(array $runs, bool $asSuffix): array
    {
        $parts = [];

        foreach ($runs as $tokens) {
            foreach ($tokens as $token) {
                $parts[] = $asSuffix ? new Suffix($token) : $token;
            }
        }

        return $parts;
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        /** @var list<string> $tokens */
        $tokens = [];

        foreach (explode(' ', $text) as $token) {
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
