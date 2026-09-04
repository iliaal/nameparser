<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\AbstractMapper;
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
     * bound on the per-parse token-analysis memo (np-r2-05): real names hold
     * a handful of distinct tokens, so this never binds outside hostile
     * unique-token rows, where it caps both the retained entries and the
     * memoized (vs recomputed) work per parse.
     */
    private const int MAX_TOKEN_ANALYSIS_ENTRIES = 1024;

    /**
     * per-token case analysis memo, reset at the top of every parse() and
     * dropped again at its end (np-cr-016, np-r2-05): the same token's
     * letters are otherwise re-extracted by an independent Unicode-regex
     * scan at each call site (uniform-input gate, creditClass, unknown-tail
     * checks, per-mapper gates). Capped at MAX_TOKEN_ANALYSIS_ENTRIES so a
     * hostile unique-token row cannot pin an unbounded entry set; past the
     * cap tokens are analyzed without memoizing (same result, no retention).
     *
     * @var array<string, array{letters: string, upper: bool, lower: bool, cased: bool}>
     */
    private array $tokenAnalysisMemo = [];

    /**
     * already-split comma tails stashed by parse() for the overridable
     * parseSplitName() hook (np-cr-006), as a stack of [given, tail] pairs.
     * The hook consumes (pops) the head pair only when its given string
     * matches the hook's $given, so a re-entrant parse() for another input
     * (a subclass override routing a second name through the hook first)
     * neither consumes nor overwrites the outer tail (np-r2-02).
     *
     * @var list<array{0: string, 1: list<string>}>
     */
    private array $preSplitTailStack = [];

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
        // are separate instances with their own memo. The memo is dropped
        // again at the end of parse() (see finally below) so a hostile
        // unique-token row does not pin entries between parses (np-r2-05).
        $this->tokenAnalysisMemo = [];

        // drop sticky @internal overrides on the main pipeline (memoized mappers)
        AbstractMapper::resetUniformUpperOverrides($this->mappers);

        try {
            return $this->parseInput($name);
        } finally {
            $this->tokenAnalysisMemo = [];
        }
    }

    /**
     * parse() body without the per-parse setup/teardown above: budgets,
     * normalization, comma routing, and the single-segment pipeline
     */
    private function parseInput(string $name): Name
    {

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
            $tail = array_slice($segments, 1);
            $this->preSplitTailStack[] = [implode(',', $tail), $tail];

            try {
                return $this->parseSplitName($segments[0], implode(',', $tail))
                    ->setSource($name, $this->tokenizeSegments($segments));
            } finally {
                array_pop($this->preSplitTailStack);
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
        // re-split; the head pair is consumed only when its given string
        // matches this call's $given, so a re-entrant hook invocation for
        // another input splits fresh instead of stealing the outer tail
        // (np-r2-02). Direct callers (and nested surname-first recursion)
        // fall back to splitting $given.
        $tailSegments = null;
        $head = end($this->preSplitTailStack);

        if ($head !== false && $head[0] === $given) {
            $tailSegments = $head[1];
            array_pop($this->preSplitTailStack);
        }

        return $this->parseSplitNameSegments($surname, $given, $tailSegments ?? $this->splitStructuralCommas($given));
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
     * thin router over CommaCredentialTail (np-cr-026): classify the
     * post-first-comma segments into Suffix parts and given-name tokens.
     *
     * @param  list<string>  $tailSegments
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart|string>
     */
    private function splitCommaCredentials(array $tailSegments, bool $uniformInput): array
    {
        return $this->commaCredentialTail()->split($tailSegments, $uniformInput);
    }

    /**
     * credential-tail classifier wired with the live suffix dictionary, the
     * per-parse memoized unknown-candidate and credential-rider tests, and
     * the second-segment suffix-mapper ride (np-cr-026, np-r2-04)
     */
    private function commaCredentialTail(): CommaCredentialTail
    {
        return new CommaCredentialTail(
            $this->getSuffixes(),
            $this->isMemoizedUnknownCandidate(...),
            $this->mapCommaSegmentSuffixes(...),
            $this->isMemoizedCredentialRider(...),
        );
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
     * per-parse memoized token analysis (np-cr-016), capped so a hostile
     * unique-token row cannot grow the memo without bound (np-r2-05). Past
     * the cap tokens are analyzed without memoizing: same result, no entry.
     *
     * @return array{letters: string, upper: bool, lower: bool, cased: bool}
     */
    private function analyzeToken(string $token): array
    {
        $cached = $this->tokenAnalysisMemo[$token] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        $analysis = Text::analyzeToken($token);

        if (count($this->tokenAnalysisMemo) < self::MAX_TOKEN_ANALYSIS_ENTRIES) {
            $this->tokenAnalysisMemo[$token] = $analysis;
        }

        return $analysis;
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
     * Text::isCredentialTailRider() over the per-parse memo: the expensive
     * letters() scan is shared with the memoized analysis, and the comparison
     * itself is Text's expression verbatim. Text stays canonical.
     */
    private function isMemoizedCredentialRider(string $token): bool
    {
        $letters = $this->analyzeToken($token)['letters'];

        return $letters === mb_strtoupper($letters, 'UTF-8');
    }

    /**
     * true when every cased token in the raw input is uppercase, so casing
     * carries no signal. Judged over the whole comma-bearing string, matching
     * the mapper-level uniform-uppercase gates. Runs over the per-parse
     * token-analysis memo (same decision as Text::isUniformUpperTokens(),
     * which stays canonical) so the gate shares each token's single
     * letters() scan with the credential checks (np-r2-05).
     */
    private function isUniformUpperInput(string $name): bool
    {
        // split on commas too: a comma-dense hostile row must not become one
        // megabyte "token". The split is capped at MAX_INPUT_TOKENS + 1 so a
        // hostile whitespace-class row (VT/FF/NBSP/U+2000+) stays within the
        // documented 65k-token cost even when the byte pre-filter below counts
        // only ASCII separators (np-cr-001, np-cr-027).
        $tokens = preg_split('/[\s,]+/u', $name, Text::MAX_INPUT_TOKENS + 1) ?: [];
        $hasCased = false;

        foreach ($tokens as $token) {
            $analysis = $this->analyzeToken((string) $token);

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
     * thin router over CommaCredentialTail (np-cr-026): every token of a
     * comma tail reads as a credential, with at least one not in the
     * dictionary. An already-mapped Suffix rides along, so a mixed tail
     * ("Yates, MOT, OTR/L") still qualifies; anything name-shaped disqualifies.
     *
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $givenParts
     */
    private function isUnknownCredentialTail(array $givenParts): bool
    {
        return $this->commaCredentialTail()->isUnknownTail($givenParts);
    }

    /**
     * thin router over CommaCredentialTail (np-cr-026).
     *
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $givenParts
     * @return array<int, \Iliaal\NameParser\Part\AbstractPart>
     */
    private function creditTailParts(array $givenParts): array
    {
        return $this->commaCredentialTail()->creditParts($givenParts);
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
        return $this->firstSegmentParser ??= SegmentParserFactory::newSegmentParser(
            $this->getWhitespace(),
            $this->getNicknameDelimiters(),
        )->setMappers(
            SegmentParserFactory::newDefaultPipeline(
                true,
                $this->getSalutations(),
                $this->getMaxSalutationIndex(),
                $this->getSuffixes(),
                $this->getNicknameDelimiters(),
                $this->getConnectors(),
                $this->getMaxCombinedInitials(),
                $this->getLastnamePrefixes(),
            ),
        );
    }

    protected function getSurnameSegmentParser(): Parser
    {
        // inherits delimiters for structural-comma masking on re-entered parse();
        // NicknameMapper runs so a left-side nick ("John (Bob) Smith, Jane") is
        // extracted rather than folded into the surname
        return $this->surnameSegmentParser ??= SegmentParserFactory::newSegmentParser(
            $this->getWhitespace(),
            $this->getNicknameDelimiters(),
        )->setMappers([
            SegmentParserFactory::newSuffixMapper($this->getSuffixes(), false, 1, $this->getNicknameDelimiters()),
            SegmentParserFactory::newNicknameMapper($this->getNicknameDelimiters()),
            SegmentParserFactory::newSalutationMapper(
                $this->getSalutations(),
                $this->getMaxSalutationIndex(),
                true,
                $this->getSuffixes(),
                $this->getNicknameDelimiters(),
                $this->getConnectors(),
            ),
            SegmentParserFactory::newSuffixMapper($this->getSuffixes(), false, 1, $this->getNicknameDelimiters()),
            SegmentParserFactory::newLastnameMapper($this->getLastnamePrefixes(), true, true),
        ]);
    }

    protected function getSecondSegmentParser(): Parser
    {
        if ($this->secondSegmentParser === null) {
            $this->secondSegmentInitialMapper = SegmentParserFactory::newInitialMapper(
                $this->getMaxCombinedInitials(),
                true,
                $this->getLastnamePrefixes(),
            );
            $this->secondSegmentSuffixMappers = [
                SegmentParserFactory::newSuffixMapper($this->getSuffixes(), true, 0, $this->getNicknameDelimiters()),
                SegmentParserFactory::newSuffixMapper($this->getSuffixes(), true, 0, $this->getNicknameDelimiters()),
            ];
            $this->secondSegmentParser = SegmentParserFactory::newSegmentParser(
                $this->getWhitespace(),
                $this->getNicknameDelimiters(),
            )->setMappers([
                $this->secondSegmentSuffixMappers[0],
                SegmentParserFactory::newNicknameMapper($this->getNicknameDelimiters()),
                SegmentParserFactory::newSalutationMapper(
                    $this->getSalutations(),
                    $this->getMaxSalutationIndex(),
                    true,
                    $this->getSuffixes(),
                    $this->getNicknameDelimiters(),
                    $this->getConnectors(),
                ),
                $this->secondSegmentSuffixMappers[1],
                $this->secondSegmentInitialMapper,
                SegmentParserFactory::newFirstnameMapper(),
                SegmentParserFactory::newMiddlenameMapper(true, $this->getLastnamePrefixes()),
            ]);
        }

        return $this->secondSegmentParser;
    }

    /**
     * get the mappers for this parser
     *
     * @return array<int, \Iliaal\NameParser\Mapper\AbstractMapper>
     */
    public function getMappers(): array
    {
        if (! $this->customMappers && empty($this->mappers)) {
            $this->mappers = SegmentParserFactory::newDefaultPipeline(
                false,
                $this->getSalutations(),
                $this->getMaxSalutationIndex(),
                $this->getSuffixes(),
                $this->getNicknameDelimiters(),
                $this->getConnectors(),
                $this->getMaxCombinedInitials(),
                $this->getLastnamePrefixes(),
            );
        }

        return $this->mappers;
    }

    /**
     * set the mappers for this parser.
     *
     * Only the single-segment (non-comma) pipeline uses this list. Comma input
     * ("Last, First") is parsed by dedicated surname/given-name sub-parsers
     * (getFirstSegmentParser/getSecondSegmentParser) whose lists are built
     * from the same SegmentParserFactory element builders as the default
     * pipeline, so a custom list set here does not affect comma
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
     * parser config, preserving mapper order. Every branch routes through
     * the SegmentParserFactory element builders (np-r2-03), so a factory
     * default/stage change cannot leave resync stale; per-mapper flags are
     * read off the mapper being replaced.
     */
    private function resyncConfigurableMappers(): void
    {
        foreach ($this->mappers as $i => $mapper) {
            if ($mapper instanceof InitialMapper) {
                $this->mappers[$i] = SegmentParserFactory::newInitialMapper(
                    $this->getMaxCombinedInitials(),
                    $mapper->matchesLastPart(),
                    $this->getLastnamePrefixes(),
                );
            } elseif ($mapper instanceof SalutationMapper) {
                $this->mappers[$i] = SegmentParserFactory::newSalutationMapper(
                    $this->getSalutations(),
                    $this->getMaxSalutationIndex(),
                    $mapper->requiresRemainder(),
                    $this->getSuffixes(),
                    $this->getNicknameDelimiters(),
                    $this->getConnectors(),
                );
            } elseif ($mapper instanceof NicknameMapper) {
                $this->mappers[$i] = SegmentParserFactory::newNicknameMapper($this->getNicknameDelimiters());
            } elseif ($mapper instanceof SuffixMapper) {
                $this->mappers[$i] = SegmentParserFactory::newSuffixMapper(
                    $this->getSuffixes(),
                    $mapper->matchesSinglePart(),
                    $mapper->getReservedParts(),
                    $this->getNicknameDelimiters(),
                );
            } elseif ($mapper instanceof LastnameMapper) {
                $this->mappers[$i] = SegmentParserFactory::newLastnameMapper(
                    $this->getLastnamePrefixes(),
                    $mapper->matchesSinglePart(),
                    $mapper->isSurnameOnly(),
                );
            } elseif ($mapper instanceof MiddlenameMapper) {
                $this->mappers[$i] = SegmentParserFactory::newMiddlenameMapper(
                    $mapper->mapsWithoutLastname(),
                    $this->getLastnamePrefixes(),
                );
            } elseif ($mapper instanceof FirstnameMapper) {
                $this->mappers[$i] = SegmentParserFactory::newFirstnameMapper();
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
     * thin router over StructuralCommaSplitter (np-cr-026): split on every
     * comma that is not shielded inside a matched delimiter span. Segments
     * are sliced from the original string, so shielded commas survive
     * verbatim inside their segment.
     *
     * @return list<string>
     */
    private function splitStructuralCommas(string $name): array
    {
        return StructuralCommaSplitter::split($name, $this->getNicknameDelimiters());
    }

    /**
     * thin router over StructuralCommaSplitter (np-cr-026): replace each
     * comma that falls inside a matched delimiter pair with a placeholder so
     * the comma split leaves the nickname intact.
     */
    private function maskDelimitedCommas(string $name): string
    {
        return StructuralCommaSplitter::mask($name, $this->getNicknameDelimiters());
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
