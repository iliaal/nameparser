<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Suffix;

/**
 * comma-tail credential classification for Parser (np-cr-026): deciding which
 * post-first-comma segments are credentials (dictionary suffixes under the
 * casing rule, or all-caps unknown-credential candidates riding a dictionary
 * anchor) and which fold back into the given name as plain tokens.
 *
 * The algorithm is Parser's, moved verbatim; the state it needs comes in
 * through the constructor (the live suffix dictionary, the per-parse
 * memoized unknown-candidate test, and the second-segment suffix-mapper
 * ride), so the class owns the scan while Parser keeps its memo fields,
 * sub-parser wiring, and protected hooks.
 */
final class CommaCredentialTail
{
    /**
     * @param  array<int|string, string>  $suffixes  merged suffix dictionary
     * @param  \Closure(string): bool  $isUnknownCandidate  per-parse memoized unknown-candidate test
     * @param  \Closure(list<string>, bool): array<int, AbstractPart|string>  $mapSuffixes  suffix-mapper ride for mixed segments
     */
    public function __construct(
        private array $suffixes,
        private \Closure $isUnknownCandidate,
        private \Closure $mapSuffixes,
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
                // a pure name segment ends any pure post-anchor run; leftover
                // peels without a following dictionary segment stay names
                array_push($parts, ...self::flattenCandidateRuns($pendingCandidateRuns, false));
                $pendingCandidateRuns = [];

                // same-segment dictionary suffix anchors unknown candidates on
                // this segment ("John MD FACS") and subsequent pure candidate
                // segments ("John MD, FACS"). Hand the whole segment to the
                // suffix mapper so the ride policy matches space form.
                if ($hasDictionarySuffix) {
                    foreach (($this->mapSuffixes)(array_column($tokenClasses, 0), $uniformInput) as $part) {
                        $parts[] = $part;
                    }

                    // the anchor reaches the next segment only when the
                    // credential run touches this segment's tail; a leading
                    // run ("MD John") must not promote a name in a following
                    // segment ("Smith, MD John, PAUL" keeps PAUL)
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
                // mixed-segment trailing peels ride on this dictionary anchor
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
                // pure unknown-candidate segment with no dictionary anchor yet:
                // keep as name tokens (not pending). Promoting later would turn
                // an all-caps given name into a suffix ("Smith, JOHN, MD").
                foreach ($tokens as $token) {
                    $parts[] = $token;
                }
            }
        }

        // trailing peels with no dictionary segment after them stay names
        array_push($parts, ...self::flattenCandidateRuns($pendingCandidateRuns, false));

        // Drop contract, documented (np-cr-014 STOPPED, np-o-04): once ANY tail
        // segment anchors, Unknown placeholders AND punctuation-only noise drop
        // from the WHOLE given side, including pure-name segments ('Smith,
        // Jane, -, MD' loses '-'). Scoping the purge to credential-bearing
        // segments was implemented and reverted: the existing suite
        // (CommaSegmentTest::commaCredentialNoiseProvider, 'punctuation
        // before/after anchor') pins the cross-segment drops, and the suite
        // wins over the bead. 'John Unknown, MD' still keeps Unknown: the
        // surname segment never enters this purge (documented otherwise, per
        // the bead's acceptance). Without an anchor nothing drops
        // (testCommaTailNoiseWithoutCredentialAnchorIsPreserved).
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

            if (Text::isUnknownCredentialCandidate($part)) {
                // a wholly in-dictionary tail is already routed correctly by
                // the ordinary comma pipeline, which also canonicalizes the
                // rendering ("PHD" to "PhD"); only an unknown token needs this
                // path.
                if (! array_key_exists(Text::key($part), $this->suffixes)) {
                    $hasUnknown = true;
                }

                continue;
            }

            // a spaced or numbered credential ("PHARM D", "OTA/L 2838") leaves
            // tokens too short or too digit-heavy to stand as candidates on
            // their own. They ride along beside a real one; a tail of nothing
            // but riders never qualifies, so a lone "Assam, P" keeps the comma
            // reading rather than guessing the initial is a credential.
            if (Text::isCredentialTailRider($part)) {
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
     * classify one segment's tokens, reporting the per-token classes and
     * whether any token is a dictionary credential (the anchor signal).
     *
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

    /**
     * classify a comma-tail token for the credential scan (np-cr-025): the
     * former 0/1/2 magic ints are TokenCredentialClass cases
     */
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

    /**
     * a dictionary-anchored credential token renders with its dictionary form;
     * an unknown candidate renders verbatim.
     */
    private function newCredentialSuffix(string $token, TokenCredentialClass $class): Suffix
    {
        return $class === TokenCredentialClass::DictionaryCredential
            ? new Suffix($token, $this->suffixes[Text::key($token)])
            : new Suffix($token);
    }

    /**
     * a segment is credential-only when it has tokens and every one is a
     * dictionary credential or an unknown-credential candidate
     *
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
     * peel a trailing run of unknown-credential candidates off a mixed segment
     * so a later dictionary segment can anchor them ("John FACS, MD"). An
     * all-candidate segment is not peeled: that path is handled as pure
     * candidates above.
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
     * flatten pending unknown-candidate runs back into parts, as suffixes when
     * a dictionary segment anchored them, else as name tokens
     *
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
