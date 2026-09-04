<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\FirstnameMapper;
use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Mapper\MiddlenameMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\GivenNamePart;
use Iliaal\NameParser\Part\Ignored;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\Suffix;

class Parser
{
    private const string COMMA_PLACEHOLDER = "\x00";

    protected string $whitespace = " \r\n\t";

    /**
     * @var array<int, \Iliaal\NameParser\Mapper\AbstractMapper>
     */
    protected array $mappers = [];

    // private: internal bookkeeping, and a protected declaration would fatal
    // any subclass that already declares a property with this name
    private bool $customMappers = false;

    private bool $promotedDefaultMappers = false;

    /**
     * @var array<int, LanguageInterface>
     */
    protected array $languages = [];

    /**
     * @var array<string, string>
     */
    protected array $nicknameDelimiters = [];

    protected int $maxSalutationIndex = 0;

    protected int $maxCombinedInitials = 2;

    /**
     * when true, a space-separated name with no comma is read surname-first
     * (CJK order, "Mao Zedong"): the first token is the surname, the rest is the
     * given-name segment. The caller asserts the order for the batch, the same
     * contract as the comma form; auto-detection is not possible from romanized
     * text where "Lee Harvey" and "Mao Zedong" are structurally identical.
     */
    protected bool $surnameFirst = false;

    /**
     * memoized merge of all languages' lastname prefixes
     *
     * @var array<int|string, string>|null
     */
    private ?array $prefixes = null;

    /**
     * memoized merge of all languages' suffixes
     *
     * @var array<int|string, string>|null
     */
    private ?array $suffixes = null;

    /**
     * memoized merge of all languages' salutations
     *
     * @var array<int|string, string>|null
     */
    private ?array $salutations = null;

    /**
     * memoized merge of the default honorific connectors with any provided by
     * the configured languages (ConnectorsInterface)
     *
     * @var array<string, string>|null
     */
    private ?array $connectors = null;

    /**
     * memoized whitespace-collapse pattern, rebuilt only when the whitespace
     * character set changes; avoids recompiling the regex on every parse()
     */
    private ?string $normalizePattern = null;

    private ?string $normalizePatternKey = null;

    /**
     * memoized sub-parsers for the comma-separated segments; built once per
     * instance so a batch of comma names does not re-merge the dictionaries
     * on every row
     */
    private ?Parser $firstSegmentParser = null;

    private ?Parser $surnameSegmentParser = null;

    private ?Parser $secondSegmentParser = null;

    /**
     * the InitialMapper instance inside the second-segment sub-parser, held so
     * parseSplitName() can feed it the whole-input uniform-uppercase signal
     */
    private ?InitialMapper $secondSegmentInitialMapper = null;

    /**
     * @var list<SuffixMapper>
     */
    private array $secondSegmentSuffixMappers = [];

    /**
     * per-token case analysis memo, reset at the top of every parse()
     * (np-cr-016): the same token's letters are otherwise re-extracted by an
     * independent Unicode-regex scan at each call site (uniform-input gate,
     * creditClass, unknown-tail checks, per-mapper gates).
     *
     * @var array<string, array{letters: string, upper: bool, lower: bool, cased: bool}>
     */
    private array $tokenAnalysisMemo = [];

    /**
     * already-split comma tail stashed by parse() for the overridable
     * parseSplitName() hook (np-cr-006); consumed (nulled) on read
     *
     * @var list<string>|null
     */
    private ?array $preSplitTailSegments = null;

    /**
     * @param  array<int, LanguageInterface>  $languages
     */
    public function __construct(array $languages = [])
    {
        if (empty($languages)) {
            $languages = [new English()];
        }

        $this->languages = $languages;
    }

    /**
     * split full names into the following parts:
     * - prefix / salutation  (Mr., Mrs., etc)
     * - given name / first name
     * - middle initials
     * - surname / last name
     * - suffix (II, Phd, Jr, etc)
     */
    public function parse(string $name): Name
    {
        // SCRUB policy (np-cr-007): invalid UTF-8 bytes are replaced with
        // U+FFFD up front (batch-import friendly, deterministic downstream)
        // instead of degrading inconsistently per call site (letters() => '',
        // graphemes() byte-splits, preg_split => []) or throwing. Callers that
        // need strictness must validate before calling. Carve-out: when the
        // configured whitespace set itself is not valid UTF-8, the
        // bytewise-collapse contract owns the raw bytes (pinned by
        // testInvalidUtf8WhitespaceFallsBackToBytewiseWithoutWarnings), so
        // scrubbing is skipped rather than destroying configured separators.
        if (mb_check_encoding($this->whitespace, 'UTF-8')) {
            $name = mb_scrub($name, 'UTF-8');
        }

        // per-parse memo of per-token case analysis (np-cr-016); sub-parsers
        // are separate instances with their own memo
        $this->tokenAnalysisMemo = [];

        // drop sticky @internal overrides on the main pipeline (memoized mappers)
        foreach ($this->mappers as $mapper) {
            if ($mapper instanceof InitialMapper || $mapper instanceof SuffixMapper) {
                $mapper->setUniformUpperOverride(null);
            }
        }

        $this->assertInputByteBudget($name);
        $name = $this->normalize($name);
        $this->assertInputTokenBudget($name);

        // split on commas that are not shielded inside a nickname span, so
        // "John (Bob, Jr) Doe" is not bisected at the nickname's comma and a
        // given-side "(Jack, Robert)" stays one segment with its comma intact
        $segments = $this->splitStructuralCommas($name);

        if (count($segments) > 1) {
            // stash the already-split tail for the overridable parseSplitName()
            // hook below, so subclass overrides keep firing (pinned by
            // testCommaParsingUsesProtectedSplitHook) without paying an
            // implode+re-split round trip (np-cr-006)
            $this->preSplitTailSegments = array_slice($segments, 1);

            try {
                return $this->parseSplitName(
                    $segments[0],
                    implode(',', array_slice($segments, 1)),
                )
                    ->setSource($name, $this->tokenizeSegments($segments));
            } finally {
                $this->preSplitTailSegments = null;
            }
        }

        $tokens = $this->tokenizeWords($name);

        if ($this->surnameFirst) {
            // a leading salutation ("Dr. Kim Jong Un") is not the surname:
            // peel it off and re-attach it to the surname segment where
            // SalutationMapper classifies it, so the first real token
            // becomes the surname rather than being shifted away
            if (count($tokens) > 1 && ($taken = $this->takeSurnameFirst($tokens)) !== null) {
                return $this->parseSplitName($taken[0], implode(' ', $taken[1]))
                    ->setSource($name, $tokens);
            }
        }

        return $this->parseParts($tokens)->setSource($name, $tokens);
    }

    /**
     * handles split-parsing of comma-separated name parts: the surname segment
     * before the first comma, and the given-name segment (first/middle names
     * plus any trailing credentials) after it
     */
    protected function parseSplitName(string $surname, string $given): Name
    {
        // parse() stashes the already-split tail so this hook avoids a
        // re-split; direct callers (and nested surname-first recursion) fall
        // back to splitting $given
        $tailSegments = $this->preSplitTailSegments ?? $this->splitStructuralCommas($given);
        $this->preSplitTailSegments = null;

        return $this->parseSplitNameSegments($surname, $given, $tailSegments);
    }

    /**
     * pre-split variant of parseSplitName(): the caller already bisected the
     * normalized input, so the tail segments are reused instead of imploding
     * and re-splitting them (np-cr-006)
     *
     * @param  list<string>  $tailSegments
     */
    private function parseSplitNameSegments(string $surname, string $given, array $tailSegments): Name
    {
        // a trailing comma ("John Smith MD,") produces an empty given segment;
        // parsing it would emit an empty Firstname part that pollutes exports
        // with a trailing space
        if (trim($given) === '') {
            // a credential-only tail ("Kim Jong Un, MD") leaves an empty given
            // segment; under surname-first the caller asserted CJK order, so
            // split the surname segment the same way rather than falling back to
            // Western order (which would read "Jong Un" as the surname). A
            // leading salutation ("Dr. Kim Jong Un, MD") is peeled first, same
            // as the comma-less surname-first route, so the honorific is not
            // shifted away as the surname token.
            if ($this->surnameFirst) {
                $surnameTokens = $this->tokenizeWords(trim($surname));
                $taken = $this->takeSurnameFirst($surnameTokens);

                if ($taken !== null) {
                    return $this->parseSplitName($taken[0], implode(' ', $taken[1]));
                }

                $reattached = $this->reattachLeadingSalutations($surnameTokens);
                if ($reattached !== null) {
                    $surname = $reattached;
                }
            }

            return $this->makeName($this->getFirstSegmentParser()->parseNormalizedSegment($surname)->getParts());
        }

        $uniformUpper = $this->isUniformUpperInput($surname . ' ' . $given);
        $givenParts = count($tailSegments) > 1
            ? $this->splitCommaCredentials($tailSegments, $uniformUpper)
            : $this->tokenizeWords($given);

        return $this->parseSplitParts($surname, $givenParts, $uniformUpper);
    }

    /**
     * parse an already-normalized comma segment (no budgets, normalize, comma
     * masking, or re-split): the segment was sliced from normalized input, so
     * tokenizing and running the mapper pipeline directly yields identical
     * parts without paying normalization/masking twice (np-cr-006)
     */
    private function parseNormalizedSegment(string $segment): Name
    {
        return $this->parseParts($this->tokenizeWords(trim($segment)));
    }

    /**
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $givenParts
     */
    private function parseSplitParts(string $surname, array $givenParts, bool $uniformUpper): Name
    {
        // a comma tail of all-caps unknown tokens is a credential run, not a
        // given name, once the left side already carries a given name of its
        // own: "Christina Nemec, LMHP" is Western order plus a credential, not
        // a surname "Christina Nemec" given-named "LMHP". Surname-only left
        // sides ("Nguyen, VI") keep the comma reading, and uniform-case input
        // carries no casing signal to read either way.
        if (! $uniformUpper && ! $this->surnameFirst && $this->isUnknownCredentialTail($givenParts)) {
            $western = $this->getFirstSegmentParser()->parseNormalizedSegment($surname);

            if ($this->hasGivenNameParts($western)) {
                return $this->makeName(array_merge(
                    $western->getParts(),
                    $this->creditTailParts($givenParts),
                ));
            }
        }

        $secondSegment = $this->getSecondSegmentParser();
        $this->secondSegmentInitialMapper?->setUniformUpperOverride($uniformUpper);
        foreach ($this->secondSegmentSuffixMappers as $mapper) {
            $mapper->setUniformUpperOverride($uniformUpper);
        }

        try {
            $givenName = $secondSegment->parseParts($givenParts);
        } finally {
            $this->secondSegmentInitialMapper?->setUniformUpperOverride(null);
            foreach ($this->secondSegmentSuffixMappers as $mapper) {
                $mapper->setUniformUpperOverride(null);
            }
        }

        if ($this->surnameFirst && ! $this->hasGivenNameParts($givenName)) {
            $taken = $this->takeSurnameFirst($this->tokenizeWords(trim($surname)));

            if ($taken !== null) {
                $base = $this->parseSplitParts(
                    $taken[0],
                    $taken[1],
                    $this->isUniformUpperInput($surname),
                );

                return $this->makeName(array_merge($base->getParts(), $givenName->getParts()));
            }
        }

        $surnameParser = $this->hasGivenNameParts($givenName)
            ? $this->getSurnameSegmentParser()
            : $this->getFirstSegmentParser();

        $parts = array_merge(
            $surnameParser->parseNormalizedSegment($surname)->getParts(),
            $givenName->getParts(),
        );

        return $this->makeName($this->promoteSoleGenerationalSuffix($parts));
    }

    /**
     * when comma form left a generational suffix but no given name (e.g.
     * "Smith, Junior" or "Smith, Jr"), the generational token is the given
     * name, not a credential: jr/sr are first-class suffix keys, so the
     * abbreviations promote exactly like junior/senior (np-cr-018).
     * Multi-token left sides that already carry a first name
     * ("Sir James Reynolds, Junior") keep the token as suffix.
     *
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private function promoteSoleGenerationalSuffix(array $parts): array
    {
        $hasGiven = false;
        $hasLast = false;
        /** @var list<int> $genIndexes */
        $genIndexes = [];

        foreach ($parts as $i => $part) {
            if ($part instanceof GivenNamePart && $part->normalize() !== '') {
                $hasGiven = true;
            }

            if ($part instanceof Lastname && $part->normalize() !== '') {
                $hasLast = true;
            }

            if (! ($part instanceof Suffix)) {
                continue;
            }

            $key = Text::key($part->getValue());

            if ($key === 'junior' || $key === 'senior' || $key === 'jr' || $key === 'sr') {
                $genIndexes[] = $i;
            }
        }

        if ($hasGiven || ! $hasLast || $genIndexes === []) {
            return $parts;
        }

        foreach ($genIndexes as $i) {
            $part = $parts[$i];
            if ($part instanceof Suffix) {
                $parts[$i] = new Firstname($part->getValue());
            }
        }

        return $parts;
    }

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
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private function splitCommaCredentials(array $tailSegments, bool $uniformInput): array
    {
        /** @var array<int, \Iliaal\NameParser\Part\AbstractPart|string> $parts */
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

            $tokens = $this->tokenizeWords($trimmed);
            if ($tokens === []) {
                continue;
            }

            $tokenClasses = [];
            $hasDictionarySuffix = false;
            foreach ($tokens as $token) {
                $class = $this->credentialClass($token, $uniformInput);

                if ($class === TokenCredentialClass::DictionaryCredential) {
                    $hasDictionarySuffix = true;
                }

                $tokenClasses[] = [$token, $class];
            }

            if ($hasDictionarySuffix) {
                $hasCredentialAnchor = true;
            }

            if (! $this->isCredentialOnlySegment($tokenClasses)) {
                // a pure name segment ends any pure post-anchor run; leftover
                // peels without a following dictionary segment stay names
                array_push($parts, ...$this->flattenCandidateRuns($pendingCandidateRuns, false));
                $pendingCandidateRuns = [];

                // same-segment dictionary suffix anchors unknown candidates on
                // this segment ("John MD FACS") and subsequent pure candidate
                // segments ("John MD, FACS"). Hand the whole segment to the
                // suffix mapper so the ride policy matches space form.
                if ($hasDictionarySuffix) {
                    foreach ($this->mapCommaSegmentSuffixes(array_column($tokenClasses, 0), $uniformInput) as $part) {
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

                [$headTokens, $trailingCandidates] = $this->splitTrailingCandidates($tokenClasses);

                foreach ($this->mapCommaSegmentSuffixes($headTokens, $uniformInput) as $part) {
                    $parts[] = $part;
                }

                if ($trailingCandidates !== []) {
                    $pendingCandidateRuns[] = $trailingCandidates;
                }

                continue;
            }

            if ($hasDictionarySuffix) {
                // mixed-segment trailing peels ride on this dictionary anchor
                array_push($parts, ...$this->flattenCandidateRuns($pendingCandidateRuns, true));
                $pendingCandidateRuns = [];
                $credentialRunAnchored = true;

                foreach ($tokenClasses as [$token, $class]) {
                    $parts[] = $class === TokenCredentialClass::DictionaryCredential
                        ? new Suffix($token, $this->getSuffixes()[Text::key($token)])
                        : new Suffix($token);
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
        array_push($parts, ...$this->flattenCandidateRuns($pendingCandidateRuns, false));

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
                static fn(\Iliaal\NameParser\Part\AbstractPart|string $part): bool => ! is_string($part)
                    || ! Text::isCredentialTailNoise($part),
            ));
        }

        return $parts;
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
    private function splitTrailingCandidates(array $tokenClasses): array
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
     * @return list<string>
     */
    private function tokenizeWords(string $text): array
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

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function tokenizeSegments(array $segments): array
    {
        $tokens = [];

        foreach ($segments as $segment) {
            foreach ($this->tokenizeWords(trim($segment)) as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * flatten pending unknown-candidate runs back into parts, as suffixes when
     * a dictionary segment anchored them, else as name tokens
     *
     * @param  list<list<string>>  $runs
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private function flattenCandidateRuns(array $runs, bool $asSuffix): array
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
     * @param  list<string>  $tokens
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private function mapCommaSegmentSuffixes(array $tokens, bool $uniformUpper): array
    {
        $this->getSecondSegmentParser();
        $mapper = $this->secondSegmentSuffixMappers[0] ?? null;

        if ($mapper === null) {
            return $tokens;
        }

        $mapper->setUniformUpperOverride($uniformUpper);

        try {
            return $mapper->map($tokens);
        } finally {
            $mapper->setUniformUpperOverride(null);
        }
    }

    /**
     * a segment is credential-only when it has tokens and every one is a
     * dictionary credential or an unknown-credential candidate
     *
     * @param  list<array{0: string, 1: TokenCredentialClass}>  $tokenClasses
     */
    private function isCredentialOnlySegment(array $tokenClasses): bool
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
     * classify a comma-tail token for the credential scan (np-cr-025): the
     * former 0/1/2 magic ints are TokenCredentialClass cases
     */
    private function credentialClass(string $token, bool $uniformInput): TokenCredentialClass
    {
        $key = Text::key($token);

        if (array_key_exists($key, $this->getSuffixes())) {
            if (isset(SuffixMapper::AMBIGUOUS_KEYS[$key])) {
                return Text::matchesCredentialCase($token, $this->getSuffixes()[$key])
                    ? TokenCredentialClass::DictionaryCredential
                    : TokenCredentialClass::Name;
            }

            return TokenCredentialClass::DictionaryCredential;
        }

        if (! $uniformInput && $this->isMemoizedUnknownCandidate($token)) {
            return TokenCredentialClass::UnknownCandidate;
        }

        return TokenCredentialClass::Name;
    }

    /**
     * per-parse memoized token analysis (np-cr-016)
     *
     * @return array{letters: string, upper: bool, lower: bool, cased: bool}
     */
    private function analyzeToken(string $token): array
    {
        return $this->tokenAnalysisMemo[$token] ??= Text::analyzeToken($token);
    }

    /**
     * Text::isUnknownCredentialCandidate() over the per-parse memo, so the
     * token's letters are extracted once per parse instead of once per
     * segment scan. Mirrors the Text definition (bracket/quote wrap,
     * all-caps with case signal, >= 2 letters); Text stays canonical.
     */
    private function isMemoizedUnknownCandidate(string $token): bool
    {
        // byte check: the wrap characters are ASCII, which never appear inside
        // a multibyte sequence of valid UTF-8 input
        if (strpbrk($token, '()[]{}<>"\'') !== false) {
            return false;
        }

        $analysis = $this->analyzeToken($token);

        if (! $analysis['upper']) {
            return false;
        }

        return Text::graphemeLengthUpTo($analysis['letters'], 2) >= 2;
    }

    /**
     * true when every cased token in the raw input is uppercase, so casing
     * carries no signal. Judged over the whole comma-bearing string, matching
     * the mapper-level uniform-uppercase gates.
     */
    private function isUniformUpperInput(string $name): bool
    {
        // split on commas too: a comma-dense hostile row must not become one
        // megabyte "token". The split is capped at MAX_INPUT_TOKENS + 1 so a
        // hostile whitespace-class row (VT/FF/NBSP/U+2000+) stays within the
        // documented 65k-token cost even when the byte pre-filter below counts
        // only ASCII separators (np-cr-001, np-cr-027).
        $tokens = preg_split('/[\s,]+/u', $name, Text::MAX_INPUT_TOKENS + 1) ?: [];

        return Text::isUniformUpperTokens($tokens);
    }

    /**
     * every token of a comma tail reads as a credential, with at least one not
     * in the dictionary. An already-mapped Suffix rides along, so a mixed tail
     * ("Yates, MOT, OTR/L") still qualifies; anything name-shaped disqualifies.
     *
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $givenParts
     */
    private function isUnknownCredentialTail(array $givenParts): bool
    {
        $suffixes = $this->getSuffixes();
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
                if (! array_key_exists(Text::key($part), $suffixes)) {
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
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $givenParts
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart>
     */
    private function creditTailParts(array $givenParts): array
    {
        $suffixes = $this->getSuffixes();
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
            $parts[] = array_key_exists($key, $suffixes)
                ? new Suffix($part, $suffixes[$key])
                : new Suffix($part);
        }

        return $parts;
    }

    protected function hasGivenNameParts(Name $name): bool
    {
        foreach ($name->getParts() as $part) {
            if ($part instanceof GivenNamePart && $part->normalize() !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     */
    private function parseParts(array $parts): Name
    {
        // empty string tokens (double spaces when whitespace collapse is off)
        // would otherwise become empty Firstname/Middlename parts and pollute
        // joined exports with a stray space
        $filtered = [];
        foreach ($parts as $part) {
            if (is_string($part) && $part === '') {
                continue;
            }

            $filtered[] = $part;
        }

        foreach ($this->getMappers() as $mapper) {
            $filtered = array_values($mapper->map($filtered));
        }

        return $this->makeName($filtered);
    }

    /**
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     */
    private function makeName(array $parts): Name
    {
        // forward the parse-time config so getConfidence() agrees with
        // custom-config parses (np-cr-004: sibling-owned Name side)
        return new Name(
            $parts,
            $this->getSuffixes(),
            $this->getSalutations(),
            $this->getNicknameDelimiters(),
            $this->getWhitespace(),
        );
    }

    protected function getFirstSegmentParser(): Parser
    {
        return $this->firstSegmentParser ??= $this->newSegmentParser()->setMappers(
            $this->newDefaultPipeline(true),
        );
    }

    protected function getSurnameSegmentParser(): Parser
    {
        // inherits delimiters for structural-comma masking on re-entered parse();
        // NicknameMapper runs so a left-side nick ("John (Bob) Smith, Jane") is
        // extracted rather than folded into the surname
        return $this->surnameSegmentParser ??= $this->newSegmentParser()->setMappers([
            $this->newSuffixMapper(false, 1),
            $this->newNicknameMapper(),
            $this->newSalutationMapper(true),
            $this->newSuffixMapper(false, 1),
            $this->newLastnameMapper(true, true),
        ]);
    }

    protected function getSecondSegmentParser(): Parser
    {
        if ($this->secondSegmentParser === null) {
            $this->secondSegmentInitialMapper = $this->newInitialMapper(true);
            $this->secondSegmentSuffixMappers = [
                $this->newSuffixMapper(true, 0),
                $this->newSuffixMapper(true, 0),
            ];
            $this->secondSegmentParser = $this->newSegmentParser()->setMappers([
                $this->secondSegmentSuffixMappers[0],
                $this->newNicknameMapper(),
                $this->newSalutationMapper(true),
                $this->secondSegmentSuffixMappers[1],
                $this->secondSegmentInitialMapper,
                new FirstnameMapper(),
                $this->newMiddlenameMapper(true),
            ]);
        }

        return $this->secondSegmentParser;
    }

    /**
     * the default single-segment mapper pipeline, also the base the
     * comma-segment builders derive from (np-o-13): adding, removing, or
     * reordering a stage happens here and in the element factories below, not
     * in four inline lists drifting in lockstep.
     *
     * @return array<int, \Iliaal\NameParser\Mapper\AbstractMapper>
     */
    private function newDefaultPipeline(bool $surnameSegmentBias = false): array
    {
        return [
            $this->newSalutationMapper(false),
            $this->newSuffixMapper(false, 2),
            $this->newNicknameMapper(),
            $this->newSuffixMapper(false, 2),
            $this->newInitialMapper(false),
            $this->newLastnameMapper($surnameSegmentBias),
            new FirstnameMapper(),
            $this->newMiddlenameMapper(false),
        ];
    }

    private function newSalutationMapper(bool $requireRemainder): SalutationMapper
    {
        return new SalutationMapper(
            $this->getSalutations(),
            $this->getMaxSalutationIndex(),
            $requireRemainder,
            $this->getSuffixes(),
            $this->getNicknameDelimiters(),
            $this->getConnectors(),
        );
    }

    private function newSuffixMapper(bool $matchSinglePart, int $reservedParts): SuffixMapper
    {
        return new SuffixMapper(
            $this->getSuffixes(),
            $matchSinglePart,
            $reservedParts,
            $this->getNicknameDelimiters(),
        );
    }

    private function newNicknameMapper(): NicknameMapper
    {
        return new NicknameMapper($this->getNicknameDelimiters());
    }

    private function newInitialMapper(bool $matchLastPart): InitialMapper
    {
        return new InitialMapper(
            $this->getMaxCombinedInitials(),
            $matchLastPart,
            $this->getLastnamePrefixes(),
        );
    }

    private function newLastnameMapper(bool $matchSinglePart, bool $surnameOnly = false): LastnameMapper
    {
        return new LastnameMapper($this->getLastnamePrefixes(), $matchSinglePart, $surnameOnly);
    }

    private function newMiddlenameMapper(bool $mapWithoutLastname): MiddlenameMapper
    {
        return new MiddlenameMapper($mapWithoutLastname, $this->getLastnamePrefixes());
    }

    /**
     * sub-parsers re-enter parse() on already-split segments, so they must
     * inherit both whitespace and nickname delimiters: the structural-comma
     * mask keys off $this->nicknameDelimiters, not the mapper constructor arg
     */
    private function newSegmentParser(): Parser
    {
        return (new Parser())
            ->setWhitespace($this->getWhitespace())
            ->setNicknameDelimiters($this->getNicknameDelimiters());
    }

    /**
     * get the mappers for this parser
     *
     * @return array<int, \Iliaal\NameParser\Mapper\AbstractMapper>
     */
    public function getMappers(): array
    {
        if (! $this->customMappers && empty($this->mappers)) {
            $this->mappers = $this->newDefaultPipeline();
        }

        return $this->mappers;
    }

    /**
     * set the mappers for this parser.
     *
     * Only the single-segment (non-comma) pipeline uses this list. Comma input
     * ("Last, First") is parsed by dedicated surname/given-name sub-parsers
     * (getFirstSegmentParser/getSecondSegmentParser) whose lists are built
     * from the same element factories (newSalutationMapper/newSuffixMapper/...)
     * as the default pipeline, so a custom list set here does not affect comma
     * forms (and is never silently half-applied: segment behavior is pinned by
     * test). setSurnameFirst(true) routes comma-less input through those same
     * sub-parsers, so a custom list does not apply on that path either. The
     * language dictionaries do propagate to the sub-parsers.
     *
     * Name::getConfidence() always uses the language-merged suffix dictionary
     * (getSuffixes()), not a custom SuffixMapper's constructor map.
     *
     * An empty list resets the parser to the default pipeline.
     *
     * @param  array<int, \Iliaal\NameParser\Mapper\AbstractMapper>  $mappers
     */
    public function setMappers(array $mappers): Parser
    {
        // an identity re-set of an already-promoted parser-owned list keeps the
        // promotion latch; without this, a second setMappers(getMappers()) call
        // silently detached config setters from the pipeline
        $promotesDefaultMappers = $mappers !== []
            && $this->mappers !== []
            && $mappers === $this->mappers
            && (! $this->customMappers || $this->promotedDefaultMappers);

        $this->mappers = $mappers;
        $this->customMappers = $mappers !== [];
        $this->promotedDefaultMappers = $promotesDefaultMappers;

        return $this;
    }

    /**
     * drop the memoized mapper pipeline and comma-segment sub-parsers so the
     * next parse() rebuilds them from the current configuration. Config setters
     * call this; without it, changing a setting after the first parse() has no
     * effect on a reused instance.
     */
    private function invalidateMapperCache(): void
    {
        // languages are constructor-fixed for stock use; clear dict memos so a
        // subclass that reassigns $languages and then calls a config setter does
        // not keep the first merge forever
        $this->prefixes = null;
        $this->suffixes = null;
        $this->salutations = null;
        $this->connectors = null;

        if (! $this->customMappers) {
            $this->mappers = [];
            $this->promotedDefaultMappers = false;
        } elseif ($this->promotedDefaultMappers) {
            // a caller may promote getMappers() into a custom list; those
            // parser-owned defaults still follow later config changes
            $this->resyncConfigurableMappers();
        }

        $this->firstSegmentParser = null;
        $this->surnameSegmentParser = null;
        $this->secondSegmentParser = null;
        $this->secondSegmentInitialMapper = null;
        $this->secondSegmentSuffixMappers = [];
    }

    /**
     * rebuild configurable mappers in a promoted default list from current
     * parser config, preserving mapper order
     */
    private function resyncConfigurableMappers(): void
    {
        foreach ($this->mappers as $i => $mapper) {
            if ($mapper instanceof InitialMapper) {
                $this->mappers[$i] = new InitialMapper(
                    $this->maxCombinedInitials,
                    $mapper->matchesLastPart(),
                    $this->getPrefixes(),
                );
            } elseif ($mapper instanceof SalutationMapper) {
                $this->mappers[$i] = new SalutationMapper(
                    $this->getSalutations(),
                    $this->maxSalutationIndex,
                    $mapper->requiresRemainder(),
                    $this->getSuffixes(),
                    $this->getNicknameDelimiters(),
                    $this->getConnectors(),
                );
            } elseif ($mapper instanceof NicknameMapper) {
                $this->mappers[$i] = new NicknameMapper($this->getNicknameDelimiters());
            } elseif ($mapper instanceof SuffixMapper) {
                $this->mappers[$i] = new SuffixMapper(
                    $this->getSuffixes(),
                    $mapper->matchesSinglePart(),
                    $mapper->getReservedParts(),
                    $this->getNicknameDelimiters(),
                );
            } elseif ($mapper instanceof LastnameMapper) {
                $this->mappers[$i] = new LastnameMapper(
                    $this->getPrefixes(),
                    $mapper->matchesSinglePart(),
                    $mapper->isSurnameOnly(),
                );
            } elseif ($mapper instanceof MiddlenameMapper) {
                $this->mappers[$i] = new MiddlenameMapper(
                    $mapper->mapsWithoutLastname(),
                    $this->getPrefixes(),
                );
            }
        }
    }

    /**
     * normalize the name: collapse the configured whitespace run, then strip
     * what must never reach the mappers.
     *
     * Allowlist (np-cr-003): everything survives except C0/C1 and other Cc
     * controls plus the bidi/format Cf characters that reorder or hollow out
     * display text (U+061C, U+180E, U+200B-U+200F, U+202A-U+202E,
     * U+2060-U+2064, U+2066-U+206F, U+FEFF). Stripped characters cannot
     * inject terminal/log sequences, truncate NUL-sensitive consumers, or
     * impersonate via bidi reordering; letters, marks, numbers, punctuation,
     * symbols, and separators pass through untouched (no NFKC: "ﬁ" stays
     * "ﬁ"). Note a custom whitespace set that excludes a control (e.g. "\t")
     * still loses it here; whitespace collapsing only decides what becomes a
     * space.
     */
    protected function normalize(string $name): string
    {
        $whitespace = $this->getWhitespace();

        $name = trim($name);

        // NUL is stripped bytewise first (np-o-03): it collides with the
        // COMMA_PLACEHOLDER invariant, and the control-strip regex below is a
        // no-op on invalid UTF-8 (returns null), which must not let a NUL
        // through to the mask/placeholder path.
        $name = str_replace("\x00", '', $name);

        // an empty whitespace set has nothing to collapse; building the pattern
        // would emit "/[]+/", an E_WARNING per parse, so short-circuit with a
        // bytewise passthrough (legacy contract, pinned by
        // testSplitParsersHonorConfiguredWhitespace): no collapse, no control
        // strip. NUL was already removed above for the placeholder invariant.
        if ($whitespace === '') {
            return $name;
        }

        // preg_replace returns null on regex compile error; user-set whitespace
        // characters might produce an invalid pattern, so fall back to the input.
        $name = preg_replace($this->normalizePattern($whitespace), ' ', $name) ?? $name;

        // trim again: custom whitespace at the edges becomes a space above and
        // the leading trim() (default charset) would not have removed it.
        return $this->stripControlChars(trim($name));
    }

    /**
     * strip remaining Cc controls and bidi/format Cf characters (see
     * normalize() for the allowlist decision)
     */
    private function stripControlChars(string $name): string
    {
        return preg_replace(
            '/[\p{Cc}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u',
            '',
            $name,
        ) ?? $name;
    }

    /**
     * build (or reuse) the whitespace-collapse pattern for the given set
     */
    private function normalizePattern(string $whitespace): string
    {
        if ($this->normalizePattern === null || $this->normalizePatternKey !== $whitespace) {
            // /u so multibyte whitespace (U+3000, NBSP) matches whole characters;
            // a bytewise class would eat those bytes out of unrelated CJK glyphs.
            // Invalid UTF-8 input makes preg_replace return null, which the
            // caller's ?? fallback already covers. A whitespace set that is not
            // valid UTF-8 cannot compile under /u at all (a warning per parse),
            // so it keeps the bytewise semantics instead.
            $unicode = mb_check_encoding($whitespace, 'UTF-8') ? 'u' : '';
            $this->normalizePattern = '/[' . preg_quote($whitespace, '/') . ']+/' . $unicode;
            $this->normalizePatternKey = $whitespace;
        }

        return $this->normalizePattern;
    }

    /**
     * split on every comma that is not shielded inside a matched delimiter
     * span. Segments are sliced from the original string, so shielded commas
     * survive verbatim inside their segment.
     *
     * @return list<string>
     */
    private function splitStructuralCommas(string $name): array
    {
        if (! str_contains($name, ',')) {
            return [$name];
        }

        // masking only swaps ',' <-> a same-width placeholder, so byte offsets
        // in the masked string map directly back onto the original
        $masked = $this->maskDelimitedCommas($name);

        $segments = [];
        $hasStructuralComma = false;
        $offset = 0;

        while (($pos = strpos($masked, ',', $offset)) !== false) {
            $hasStructuralComma = true;
            $segment = substr($name, $offset, $pos - $offset);
            if ($segment !== '' || $segments === [] || end($segments) !== '') {
                $segments[] = $segment;
            }

            $offset = $pos + 1;
        }

        if (! $hasStructuralComma) {
            return [$name];
        }

        $segment = substr($name, $offset);
        if ($segment !== '' || end($segments) !== '') {
            $segments[] = $segment;
        }

        if (count($segments) === 1) {
            $segments[] = '';
        }

        return $segments;
    }

    /**
     * replace each comma that falls inside a matched delimiter pair with a
     * placeholder so the comma split leaves the nickname intact. Only spans
     * that actually close are masked; an unmatched opener masks nothing. A
     * symmetric delimiter (quote) opens only at a token start with a token-end
     * closer later, mirroring NicknameMapper, so a mid-token apostrophe
     * (O'Brien) or an elided particle ('t) never shields a comma.
     */
    private function maskDelimitedCommas(string $name): string
    {
        if (! str_contains($name, ',')) {
            return $name;
        }

        // char/byte-mix guard (np-o-02): the char scan below substitutes
        // invalid sequences (changing byte length) while the split slices byte
        // offsets, so invalid UTF-8 plus an opener byte could shield/expose the
        // wrong comma. Bail to unmasked (deterministic split); parse() scrubs
        // invalid input up front, so this only fires for raw-byte callers.
        if (! mb_check_encoding($name, 'UTF-8')) {
            return $name;
        }

        $delimiters = Text::sanitizeNicknameDelimiters($this->getNicknameDelimiters());

        $pairs = [];
        /** @var array<string, true> $symmetric */
        $symmetric = [];
        foreach ($delimiters as $open => $close) {
            if ($open === '' || $close === '') {
                continue;
            }

            if ($open === $close) {
                $symmetric[$open] = true;
            } else {
                $pairs[$open] = $close;
            }
        }

        if ($pairs === [] && $symmetric === []) {
            return $name;
        }

        // byte-level pre-check: no opener byte present means nothing to mask,
        // skipping the scan on the common bracket-free row
        $openerBytes = implode('', array_merge(array_keys($pairs), array_keys($symmetric)));
        if (strpbrk($name, $openerBytes) === false) {
            return $name;
        }

        // single-byte delimiters scan bytewise (np-cr-011, np-cr-017):
        // identical results to the char scan on valid UTF-8 (an ASCII byte
        // never appears inside a multibyte sequence), with no per-character
        // array, so no length cap is needed and long rows keep their shielding
        // within bounded cost (nesting-depth + masked-comma caps, documented
        // on the byte scanner).
        if ($this->allSingleByteDelimiters($pairs, $symmetric)) {
            return $this->maskDelimitedCommasAscii($name, $pairs, $symmetric);
        }

        // multibyte delimiters keep the char scan below; hostile megabyte rows
        // would materialize a per-character array, so past this size commas
        // split unshielded (documented real-names-are-tiny tradeoff).
        if (strlen($name) > 4096) {
            return $name;
        }

        $chars = mb_str_split($name, 1, 'UTF-8');
        $total = count($chars);

        // pre-split every delimiter once; openers sorted longest-first so a
        // multi-character delimiter ("<<") wins over a single-char prefix ("<")
        /** @var list<array{list<string>, string, bool}> $openers opener chars, closer string, is-symmetric */
        $openers = [];
        foreach ($pairs as $open => $close) {
            $openers[] = [mb_str_split((string) $open, 1, 'UTF-8'), $close, false];
        }
        foreach (array_keys($symmetric) as $quote) {
            $openers[] = [mb_str_split((string) $quote, 1, 'UTF-8'), (string) $quote, true];
        }
        usort($openers, static fn(array $a, array $b): int => count($b[0]) <=> count($a[0]));

        /** @var array<string, list<array{list<string>, string, bool}>> $openersByFirst */
        $openersByFirst = [];
        foreach ($openers as $opener) {
            $openersByFirst[$opener[0][0]][] = $opener;
        }

        // token-end offsets per symmetric delimiter, so each opener's closer
        // lookahead is a bounded list walk instead of a rescan. Skipped when
        // no symmetric delimiter exists (np-cr-011): the common asymmetric
        // row avoids the token-range materialization entirely.
        /** @var array<string, list<int>> $symmetricEnds */
        $symmetricEnds = [];
        if ($symmetric !== []) {
            /** @var list<array{int, int}> $tokenRanges token start, end (exclusive) */
            $tokenRanges = [];
            $tokenStart = null;
            for ($i = 0; $i <= $total; ++$i) {
                if ($this->isStructuralTokenBoundary($chars[$i] ?? null)) {
                    if ($tokenStart !== null) {
                        $tokenRanges[] = [$tokenStart, $i];
                        $tokenStart = null;
                    }
                } elseif ($tokenStart === null) {
                    $tokenStart = $i;
                }
            }

            foreach (array_keys($symmetric) as $quote) {
                $quote = (string) $quote;
                $quoteChars = mb_str_split($quote, 1, 'UTF-8');
                $len = count($quoteChars);

                foreach ($tokenRanges as [$start, $end]) {
                    $closerStart = $end - $len;
                    if ($closerStart < $start || ! $this->charsMatchAt($chars, $closerStart, $quoteChars)) {
                        continue;
                    }

                    // a self-balanced quoted token ("'Genius'") closes itself; its
                    // tail quote must not serve as the closer for an earlier orphan
                    // opener, or a leading elided particle ("'t") would open a span
                    // that swallows the structural comma
                    if ($end - $start >= $len * 2 && $this->charsMatchAt($chars, $start, $quoteChars)) {
                        continue;
                    }

                    $symmetricEnds[$quote][] = $closerStart;
                }
            }
        }

        /** @var list<array{list<string>, bool}> $closers open spans' closer chars + is-symmetric */
        $closers = [];
        /** @var list<string> $openQuotes symmetric delimiters currently open */
        $openQuotes = [];
        /** @var list<list<int>> $pendingCommas comma offsets per open span */
        $pendingCommas = [];
        /** @var array<int, true> $mask */
        $mask = [];

        for ($i = 0; $i < $total;) {
            $depth = count($closers);

            if ($depth > 0) {
                [$closerChars, $isSymmetric] = $closers[$depth - 1];
                $closerLen = count($closerChars);

                if ($this->charsMatchAt($chars, $i, $closerChars)
                    && (! $isSymmetric
                        || $this->isStructuralTokenBoundary($chars[$i + $closerLen] ?? null))) {
                    array_pop($closers);
                    if ($isSymmetric) {
                        array_pop($openQuotes);
                    }
                    foreach (array_pop($pendingCommas) ?? [] as $pos) {
                        $mask[$pos] = true;
                    }

                    $i += $closerLen;

                    continue;
                }
            }

            foreach ($openersByFirst[$chars[$i]] ?? [] as [$openChars, $close, $isSymmetric]) {
                if (
                    $isSymmetric
                    && ! $this->isStructuralTokenBoundary($chars[$i - 1] ?? null)
                ) {
                    continue;
                }

                if (! $this->charsMatchAt($chars, $i, $openChars)) {
                    continue;
                }

                $openLen = count($openChars);

                if ($isSymmetric) {
                    $hasCloser = false;
                    foreach ($symmetricEnds[$close] ?? [] as $end) {
                        if ($end >= $i + $openLen) {
                            $hasCloser = true;

                            break;
                        }
                    }

                    if (! $hasCloser || in_array($close, $openQuotes, true)) {
                        continue;
                    }

                    $openQuotes[] = $close;
                }

                $closers[] = [mb_str_split($close, 1, 'UTF-8'), $isSymmetric];
                $pendingCommas[] = [];
                $i += $openLen;

                continue 2;
            }

            if ($chars[$i] === ',' && $depth > 0) {
                $pendingCommas[$depth - 1][] = $i;
            }

            $i++;
        }

        if ($mask === []) {
            return $name;
        }

        foreach (array_keys($mask) as $pos) {
            $chars[$pos] = self::COMMA_PLACEHOLDER;
        }

        return implode('', $chars);
    }
    /**
     * bounds for the byte-level mask scan (np-cr-017): nesting depth and total
     * masked commas are capped so a hostile row (megabytes of openers/commas)
     * costs bounded time and memory. Past the caps further openers read as
     * literals and further commas as structural. Only reachable on non-name
     * input: the token budget rejects such rows at parse() top, and real
     * nickname spans nest one or two deep with a handful of commas.
     */
    private const int MAX_MASK_NESTING_DEPTH = 128;

    private const int MAX_MASKED_COMMAS = 65536;

    /**
     * whether every delimiter is one byte (on valid UTF-8 input, one byte is
     * ASCII, which never appears inside a multibyte sequence)
     *
     * @param  array<string, string>  $pairs
     * @param  array<string, true>  $symmetric
     */
    private function allSingleByteDelimiters(array $pairs, array $symmetric): bool
    {
        foreach ($pairs as $open => $close) {
            if (strlen((string) $open) !== 1 || strlen($close) !== 1) {
                return false;
            }
        }

        foreach ($symmetric as $quote => $_) {
            if (strlen((string) $quote) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * byte-level twin of the char scan in maskDelimitedCommas(), used when all
     * delimiters are single-byte: byte offsets are char offsets on valid UTF-8
     * input, so the results are identical with no per-character array and no
     * length cap (np-cr-011, np-cr-017). Mirrors the char scan rule for rule,
     * including symmetric open-at-token-start / close-at-token-end.
     *
     * @param  array<string, string>  $pairs
     * @param  array<string, true>  $symmetric
     */
    private function maskDelimitedCommasAscii(string $name, array $pairs, array $symmetric): string
    {
        $asciiPairs = [];
        foreach ($pairs as $open => $close) {
            $asciiPairs[(string) $open] = $close;
        }

        $length = strlen($name);

        // token-end byte offsets per symmetric quote (closer length is 1), so
        // each opener's closer lookahead is a bounded list walk
        /** @var array<string, list<int>> $symmetricEnds */
        $symmetricEnds = [];
        if ($symmetric !== []) {
            $tokenStart = null;
            for ($i = 0; $i <= $length; ++$i) {
                $byte = $i < $length ? $name[$i] : null;

                if ($byte === null || $byte === ' ' || $byte === ',') {
                    if ($tokenStart !== null) {
                        $end = $i;

                        foreach ($symmetric as $quote => $_) {
                            $quote = (string) $quote;
                            $closerStart = $end - 1;

                            if ($closerStart < $tokenStart || $name[$closerStart] !== $quote) {
                                continue;
                            }

                            // a self-balanced quoted token ("'Genius'") closes
                            // itself; its tail quote must not serve as the
                            // closer for an earlier orphan opener
                            if ($end - $tokenStart >= 2 && $name[$tokenStart] === $quote) {
                                continue;
                            }

                            $symmetricEnds[$quote][] = $closerStart;
                        }

                        $tokenStart = null;
                    }
                } elseif ($tokenStart === null) {
                    $tokenStart = $i;
                }
            }
        }

        /** @var list<array{string, bool}> $closers open spans' closer byte + is-symmetric */
        $closers = [];
        /** @var list<string> $openQuotes symmetric delimiters currently open */
        $openQuotes = [];
        /** @var list<list<int>> $pendingCommas comma offsets per open span */
        $pendingCommas = [];
        /** @var array<int, true> $mask */
        $mask = [];
        $trackedCommas = 0;

        for ($i = 0; $i < $length;) {
            $depth = count($closers);
            $byte = $name[$i];

            if ($depth > 0) {
                [$closeByte, $isSymmetric] = $closers[$depth - 1];

                if ($byte === $closeByte
                    && (! $isSymmetric
                        || $this->isStructuralTokenBoundary($i + 1 < $length ? $name[$i + 1] : null))) {
                    array_pop($closers);
                    if ($isSymmetric) {
                        array_pop($openQuotes);
                    }
                    foreach (array_pop($pendingCommas) ?? [] as $pos) {
                        $mask[$pos] = true;
                    }

                    ++$i;

                    continue;
                }
            }

            $canOpen = $depth < self::MAX_MASK_NESTING_DEPTH
                && $trackedCommas < self::MAX_MASKED_COMMAS;

            if ($canOpen && isset($asciiPairs[$byte])) {
                $closers[] = [$asciiPairs[$byte], false];
                $pendingCommas[] = [];
                ++$i;

                continue;
            }

            if ($canOpen
                && isset($symmetric[$byte])
                && $this->isStructuralTokenBoundary($i > 0 ? $name[$i - 1] : null)) {
                $hasCloser = false;
                foreach ($symmetricEnds[$byte] ?? [] as $end) {
                    if ($end >= $i + 1) {
                        $hasCloser = true;

                        break;
                    }
                }

                if ($hasCloser && ! in_array($byte, $openQuotes, true)) {
                    $openQuotes[] = $byte;
                    $closers[] = [$byte, true];
                    $pendingCommas[] = [];
                    ++$i;

                    continue;
                }
            }

            if ($byte === ',' && $depth > 0 && $trackedCommas < self::MAX_MASKED_COMMAS) {
                $pendingCommas[$depth - 1][] = $i;
                ++$trackedCommas;
            }

            ++$i;
        }

        if ($mask === []) {
            return $name;
        }

        foreach (array_keys($mask) as $pos) {
            $name[$pos] = self::COMMA_PLACEHOLDER;
        }

        return $name;
    }

    /**
     * whether the character sequence at $offset equals $needle
     *
     * @param  list<string>  $chars
     * @param  list<string>  $needle
     */
    private function charsMatchAt(array $chars, int $offset, array $needle): bool
    {
        foreach ($needle as $j => $needleChar) {
            if (($chars[$offset + $j] ?? null) !== $needleChar) {
                return false;
            }
        }

        return true;
    }

    private function isStructuralTokenBoundary(?string $char): bool
    {
        return $char === null || $char === ' ' || $char === ',';
    }

    private function assertInputByteBudget(string $name): void
    {
        Text::assertInputByteBudget($name);
    }

    private function assertInputTokenBudget(string $name): void
    {
        // Exceeding N non-empty tokens needs at least N one-byte tokens and N-1
        // one-byte separators. Normal names cannot reach the token ceiling, so
        // avoid scanning them a second time.
        if (strlen($name) < (Text::MAX_INPUT_TOKENS * 2) + 1) {
            return;
        }

        $budgetInput = $this->maskDelimitedCommas($name);
        $tokens = 0;
        $insideToken = false;
        $length = strlen($budgetInput);

        // separators mirror the PCRE \s class of the isUniformUpperInput split
        // over ASCII (space, \t\n\r\v\f) plus comma; multibyte Unicode spaces
        // (NBSP, U+2000+) ride inside counted tokens, so this byte counter is a
        // conservative pre-filter while the capped preg_split bounds the real
        // split cost (np-cr-001)
        for ($i = 0; $i < $length; $i++) {
            if ($budgetInput[$i] === ' '
                || $budgetInput[$i] === ','
                || $budgetInput[$i] === "\t"
                || $budgetInput[$i] === "\n"
                || $budgetInput[$i] === "\r"
                || $budgetInput[$i] === "\x0B"
                || $budgetInput[$i] === "\x0C") {
                $insideToken = false;

                continue;
            }

            if ($insideToken) {
                continue;
            }

            $insideToken = true;
            $tokens++;

            Text::assertInputTokenCount($tokens);
        }
    }

    /**
     * peel leading salutations and take the next token as the surname. Returns
     * null when fewer than two name tokens remain after peeling.
     *
     * @param  list<string>  $tokens
     * @return array{0: string, 1: list<string>}|null
     */
    private function takeSurnameFirst(array $tokens): ?array
    {
        $peeled = $this->peelLeadingSalutations($tokens);

        if (count($tokens) < 2) {
            return null;
        }

        $surname = array_shift($tokens);
        $segment = $peeled === []
            ? $surname
            : implode(' ', $peeled) . ' ' . $surname;

        return [$segment, $tokens];
    }

    /**
     * when the surname segment collapses to a single name token after peel,
     * reattach any leading salutations so they stay on the segment (empty-given
     * credential-only tail under surname-first)
     *
     * @param  list<string>  $tokens
     */
    private function reattachLeadingSalutations(array $tokens): ?string
    {
        $peeled = $this->peelLeadingSalutations($tokens);

        if ($peeled === []) {
            return null;
        }

        return implode(' ', array_merge($peeled, $tokens));
    }

    /**
     * remove leading salutation tokens from $tokens (by reference) and return
     * them, greedily matching multi-word salutations ("his honour") first. Used
     * by the surname-first router so a leading honorific attaches to the surname
     * segment instead of being shifted away as the surname itself.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function peelLeadingSalutations(array &$tokens): array
    {
        $mapped = (new SalutationMapper(
            $this->getSalutations(),
            suffixes: $this->getSuffixes(),
            nicknameDelimiters: $this->getNicknameDelimiters(),
            connectors: $this->getConnectors(),
        ))->map($tokens);
        $offset = 0;
        $peeled = [];
        $sawSalutation = false;

        if (isset($mapped[0], $mapped[1])
            && is_string($mapped[0])
            && Text::key($mapped[0]) === 'the'
            && $mapped[1] instanceof Salutation) {
            $peeled[] = $mapped[0];
            $offset++;
        }

        while (isset($mapped[$offset]) && $mapped[$offset] instanceof Salutation) {
            $peeled[] = $mapped[$offset]->normalize();
            $sawSalutation = true;
            $offset++;
        }

        if (! $sawSalutation) {
            return [];
        }

        $tokens = [];
        foreach (array_slice($mapped, $offset) as $part) {
            if (is_string($part)) {
                $tokens[] = $part;
            } elseif ($part instanceof Ignored) {
                // an unattributed connector must stay visible in getParts();
                // the downstream segment mapper re-wraps the raw token
                $tokens[] = $part->getValue();
            }
        }

        return $peeled;
    }

    /**
     * get a string of characters that are supposed to be treated as whitespace
     */
    public function getWhitespace(): string
    {
        return $this->whitespace;
    }

    /**
     * set the string of characters that are supposed to be treated as whitespace
     */
    public function setWhitespace(string $whitespace): Parser
    {
        $this->whitespace = $whitespace;
        $this->invalidateMapperCache();

        return $this;
    }

    /**
     * @return array<int|string, string>
     */
    public function getLastnamePrefixes(): array
    {
        return $this->prefixes ??= $this->mergeFromLanguages('getLastnamePrefixes');
    }

    /**
     * merged suffix dictionary for the configured languages (first language wins
     * on key collision). Use as the second argument to Confidence::assess().
     *
     * @return array<int|string, string>
     */
    public function getSuffixes(): array
    {
        return $this->suffixes ??= $this->mergeFromLanguages('getSuffixes');
    }

    /**
     * @return array<int|string, string>
     */
    public function getSalutations(): array
    {
        return $this->salutations ??= $this->mergeFromLanguages('getSalutations');
    }

    /**
     * honorific connector tokens ("Mr. and Mrs.", "Herr und Frau") as key =>
     * rendered form. Languages add theirs via ConnectorsInterface (first
     * language wins on collision); the English defaults are always present.
     *
     * @return array<string, string>
     */
    public function getConnectors(): array
    {
        if ($this->connectors === null) {
            $merged = [];

            foreach ($this->languages as $language) {
                if ($language instanceof ConnectorsInterface) {
                    $merged += $language->getConnectors();
                }
            }

            $this->connectors = $merged + SalutationMapper::DEFAULT_CONNECTORS;
        }

        return $this->connectors;
    }

    /**
     * legacy alias kept for subclasses (np-cr-025): new code calls
     * getLastnamePrefixes() directly
     *
     * @return array<int|string, string>
     */
    protected function getPrefixes(): array
    {
        return $this->getLastnamePrefixes();
    }

    /**
     * @param  'getSuffixes'|'getSalutations'|'getLastnamePrefixes'  $method
     * @return array<int|string, string>
     */
    private function mergeFromLanguages(string $method): array
    {
        $merged = [];

        foreach ($this->languages as $language) {
            $merged += $language->$method();
        }

        return $merged;
    }

    /**
     * configured nickname delimiter pairs (defaults when none were set);
     * parsing ignores invalid or over-limit entries
     *
     * @return array<string, string>
     */
    public function getNicknameDelimiters(): array
    {
        return $this->nicknameDelimiters !== []
            ? $this->nicknameDelimiters
            : NicknameMapper::DEFAULT_DELIMITERS;
    }

    /**
     * @param  array<string, string>  $nicknameDelimiters
     */
    public function setNicknameDelimiters(array $nicknameDelimiters): Parser
    {
        $this->nicknameDelimiters = $nicknameDelimiters;
        $this->invalidateMapperCache();

        return $this;
    }

    public function getMaxSalutationIndex(): int
    {
        return $this->maxSalutationIndex;
    }

    public function setMaxSalutationIndex(int $maxSalutationIndex): Parser
    {
        $this->maxSalutationIndex = $maxSalutationIndex;
        $this->invalidateMapperCache();

        return $this;
    }

    public function getMaxCombinedInitials(): int
    {
        return $this->maxCombinedInitials;
    }

    public function setMaxCombinedInitials(int $maxCombinedInitials): Parser
    {
        if ($maxCombinedInitials < 0 || $maxCombinedInitials > InitialMapper::MAX_COMBINED) {
            throw new \InvalidArgumentException(
                'Combined initials limit must be between 0 and ' . InitialMapper::MAX_COMBINED,
            );
        }

        $this->maxCombinedInitials = $maxCombinedInitials;
        $this->invalidateMapperCache();

        return $this;
    }

    public function isSurnameFirst(): bool
    {
        return $this->surnameFirst;
    }

    /**
     * read space-separated input surname-first (CJK order). Only affects names
     * without a comma. This path routes through the comma-form surname/given
     * sub-parsers, not the configurable mapper pipeline, so a custom setMappers()
     * list does not apply here; there is no cache to drop.
     */
    public function setSurnameFirst(bool $surnameFirst): Parser
    {
        $this->surnameFirst = $surnameFirst;

        return $this;
    }
}
