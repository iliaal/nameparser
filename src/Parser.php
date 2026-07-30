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

    private bool $resyncCustomMappers = false;

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
            return $this->parseSplitName(
                $segments[0],
                implode(',', array_slice($segments, 1)),
            )
                ->setSource($name, $this->tokenizeSegments($segments));
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

            return $this->makeName($this->getFirstSegmentParser()->parse($surname)->getParts());
        }

        $uniformUpper = $this->isUniformUpperInput($surname . ' ' . $given);
        $segments = $this->splitStructuralCommas($given);
        $givenParts = count($segments) > 1
            ? $this->splitCommaCredentials($segments, $uniformUpper)
            : $this->tokenizeWords($given);

        return $this->parseSplitParts($surname, $givenParts, $uniformUpper);
    }

    /**
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $givenParts
     */
    private function parseSplitParts(string $surname, array $givenParts, bool $uniformUpper): Name
    {
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
            $surnameParser->parse($surname)->getParts(),
            $givenName->getParts(),
        );

        return $this->makeName($this->promoteSoleGenerationalSuffix($parts));
    }

    /**
     * when comma form left a junior/senior suffix but no given name (e.g.
     * "Smith, Junior"), the generational token is the given name, not a
     * credential. Multi-token left sides that already carry a first name
     * ("Sir James Reynolds, Junior") keep junior/senior as suffix.
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

            if ($key === 'junior' || $key === 'senior') {
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
        /** @var list<list<string>> $pendingCandidates trailing class-2 peels from mixed segments */
        $pendingCandidates = [];
        $runAnchored = false;
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

            $classes = [];
            $hasDictionarySuffix = false;
            foreach ($tokens as $token) {
                $class = $this->creditClass($token, $uniformInput);

                if ($class === 1) {
                    $hasDictionarySuffix = true;
                }

                $classes[] = [$token, $class];
            }

            if ($hasDictionarySuffix) {
                $hasCredentialAnchor = true;
            }

            if (! $this->isCredentialOnlySegment($classes)) {
                // a pure name segment ends any pure post-anchor run; leftover
                // peels without a following dictionary segment stay names
                $this->appendCandidateSegments($parts, $pendingCandidates, false);
                $pendingCandidates = [];

                // same-segment dictionary suffix anchors unknown candidates on
                // this segment ("John MD FACS") and subsequent pure candidate
                // segments ("John MD, FACS"). Hand the whole segment to the
                // suffix mapper so the ride policy matches space form.
                if ($hasDictionarySuffix) {
                    foreach ($this->mapCommaSegmentSuffixes(array_column($classes, 0), $uniformInput) as $part) {
                        $parts[] = $part;
                    }
                    $runAnchored = true;

                    continue;
                }

                $runAnchored = false;

                [$headTokens, $trailingCandidates] = $this->splitTrailingCandidates($classes);

                foreach ($this->mapCommaSegmentSuffixes($headTokens, $uniformInput) as $part) {
                    $parts[] = $part;
                }

                if ($trailingCandidates !== []) {
                    $pendingCandidates[] = $trailingCandidates;
                }

                continue;
            }

            if ($hasDictionarySuffix) {
                // mixed-segment trailing peels ride on this dictionary anchor
                $this->appendCandidateSegments($parts, $pendingCandidates, true);
                $pendingCandidates = [];
                $runAnchored = true;

                foreach ($classes as [$token, $class]) {
                    $parts[] = $class === 1
                        ? new Suffix($token, $this->getSuffixes()[Text::key($token)])
                        : new Suffix($token);
                }

                continue;
            }

            if ($runAnchored) {
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
        $this->appendCandidateSegments($parts, $pendingCandidates, false);

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
     * peel a trailing run of unknown-credential candidates (class 2) off a
     * mixed segment so a later dictionary segment can anchor them
     * ("John FACS, MD"). An all-candidate segment is not peeled: that path is
     * handled as pure candidates above.
     *
     * @param  list<array{0: string, 1: int}>  $classes
     * @return array{0: list<string>, 1: list<string>}
     */
    private function splitTrailingCandidates(array $classes): array
    {
        $count = count($classes);
        $lastNonCandidate = $count - 1;

        while ($lastNonCandidate >= 0 && $classes[$lastNonCandidate][1] === 2) {
            $lastNonCandidate--;
        }

        if ($lastNonCandidate < 0 || $lastNonCandidate === $count - 1) {
            return [array_column($classes, 0), []];
        }

        $head = [];
        for ($i = 0; $i <= $lastNonCandidate; $i++) {
            $head[] = $classes[$i][0];
        }

        $trailing = [];
        for ($i = $lastNonCandidate + 1; $i < $count; $i++) {
            $trailing[] = $classes[$i][0];
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
     * @param  array<int, \Iliaal\NameParser\Part\AbstractPart|string>  $parts
     * @param  list<list<string>>  $segments
     */
    private function appendCandidateSegments(array &$parts, array $segments, bool $asSuffix): void
    {
        foreach ($segments as $tokens) {
            foreach ($tokens as $token) {
                $parts[] = $asSuffix ? new Suffix($token) : $token;
            }
        }
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
     * dictionary suffix (class 1) or an unknown-credential candidate (class 2)
     *
     * @param  list<array{0: string, 1: int}>  $classes
     */
    private function isCredentialOnlySegment(array $classes): bool
    {
        if ($classes === []) {
            return false;
        }

        foreach ($classes as [, $class]) {
            if ($class === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 1 = dictionary suffix under the casing rule, 2 = unknown-credential
     * candidate (all-caps, >=2 letters, only when the input is not uniform
     * uppercase), 0 = neither (a name token)
     */
    private function creditClass(string $token, bool $uniformInput): int
    {
        $key = Text::key($token);

        if (array_key_exists($key, $this->getSuffixes())) {
            if (isset(SuffixMapper::AMBIGUOUS_KEYS[$key])) {
                return Text::matchesCredentialCase($token, $this->getSuffixes()[$key]) ? 1 : 0;
            }

            return 1;
        }

        if (! $uniformInput && Text::isUnknownCredentialCandidate($token)) {
            return 2;
        }

        return 0;
    }

    /**
     * true when every cased token in the raw input is uppercase, so casing
     * carries no signal. Judged over the whole comma-bearing string, matching
     * the mapper-level uniform-uppercase gates.
     */
    private function isUniformUpperInput(string $name): bool
    {
        $hasUpper = false;

        // split on commas too: a comma-dense hostile row must not become one
        // megabyte "token" that Text::letters() re-scans with a Unicode regex
        foreach (preg_split('/[\s,]+/u', $name) ?: [] as $token) {
            $letters = Text::letters($token);

            if ($letters === '') {
                continue;
            }

            if (mb_strtoupper($letters, 'UTF-8') !== $letters) {
                return false;
            }

            if ($letters !== mb_strtolower($letters, 'UTF-8')) {
                $hasUpper = true;
            }
        }

        return $hasUpper;
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
        return new Name($parts, $this->getSuffixes(), $this->getSalutations());
    }

    protected function getFirstSegmentParser(): Parser
    {
        return $this->firstSegmentParser ??= $this->newSegmentParser()->setMappers([
            new SalutationMapper(
                $this->getSalutations(),
                $this->getMaxSalutationIndex(),
                false,
                $this->getSuffixes(),
                $this->getNicknameDelimiters(),
            ),
            new SuffixMapper($this->getSuffixes(), false, 2),
            new NicknameMapper($this->getNicknameDelimiters()),
            new SuffixMapper($this->getSuffixes(), false, 2),
            new InitialMapper($this->getMaxCombinedInitials(), false, $this->getPrefixes()),
            new LastnameMapper($this->getPrefixes(), true),
            new FirstnameMapper(),
            new MiddlenameMapper(false, $this->getPrefixes()),
        ]);
    }

    protected function getSurnameSegmentParser(): Parser
    {
        // inherits delimiters for structural-comma masking on re-entered parse();
        // NicknameMapper runs so a left-side nick ("John (Bob) Smith, Jane") is
        // extracted rather than folded into the surname
        return $this->surnameSegmentParser ??= $this->newSegmentParser()->setMappers([
            new SuffixMapper($this->getSuffixes(), false, 1),
            new NicknameMapper($this->getNicknameDelimiters()),
            new SalutationMapper(
                $this->getSalutations(),
                $this->getMaxSalutationIndex(),
                true,
                $this->getSuffixes(),
                $this->getNicknameDelimiters(),
            ),
            new SuffixMapper($this->getSuffixes(), false, 1),
            new LastnameMapper($this->getPrefixes(), true, true),
        ]);
    }

    protected function getSecondSegmentParser(): Parser
    {
        if ($this->secondSegmentParser === null) {
            $this->secondSegmentInitialMapper = new InitialMapper(
                $this->getMaxCombinedInitials(),
                true,
                $this->getPrefixes(),
            );
            $this->secondSegmentSuffixMappers = [
                new SuffixMapper($this->getSuffixes(), true, 0),
                new SuffixMapper($this->getSuffixes(), true, 0),
            ];
            $this->secondSegmentParser = $this->newSegmentParser()->setMappers([
                $this->secondSegmentSuffixMappers[0],
                new NicknameMapper($this->getNicknameDelimiters()),
                new SalutationMapper(
                    $this->getSalutations(),
                    $this->getMaxSalutationIndex(),
                    true,
                    $this->getSuffixes(),
                    $this->getNicknameDelimiters(),
                ),
                $this->secondSegmentSuffixMappers[1],
                $this->secondSegmentInitialMapper,
                new FirstnameMapper(),
                new MiddlenameMapper(true, $this->getPrefixes()),
            ]);
        }

        return $this->secondSegmentParser;
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
            $this->mappers = [
                new SalutationMapper(
                    $this->getSalutations(),
                    $this->getMaxSalutationIndex(),
                    false,
                    $this->getSuffixes(),
                    $this->getNicknameDelimiters(),
                ),
                new SuffixMapper($this->getSuffixes()),
                new NicknameMapper($this->getNicknameDelimiters()),
                new SuffixMapper($this->getSuffixes()),
                new InitialMapper($this->getMaxCombinedInitials(), false, $this->getPrefixes()),
                new LastnameMapper($this->getPrefixes()),
                new FirstnameMapper(),
                new MiddlenameMapper(false, $this->getPrefixes()),
            ];
        }

        return $this->mappers;
    }

    /**
     * set the mappers for this parser.
     *
     * Only the single-segment (non-comma) pipeline uses this list. Comma input
     * ("Last, First") is parsed by dedicated surname/given-name sub-parsers
     * (getFirstSegmentParser/getSecondSegmentParser) that build their own mapper
     * lists, so a custom list set here does not affect comma forms.
     * setSurnameFirst(true) routes comma-less input through those same
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
        $promotesDefaultMappers = $mappers !== []
            && ! $this->customMappers
            && $this->mappers !== []
            && $mappers === $this->mappers;

        $this->mappers = $mappers;
        $this->customMappers = $mappers !== [];
        $this->resyncCustomMappers = $promotesDefaultMappers;

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

        if (! $this->customMappers) {
            $this->mappers = [];
            $this->resyncCustomMappers = false;
        } elseif ($this->resyncCustomMappers) {
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
                    false,
                    $this->getSuffixes(),
                    $this->getNicknameDelimiters(),
                );
            } elseif ($mapper instanceof NicknameMapper) {
                $this->mappers[$i] = new NicknameMapper($this->getNicknameDelimiters());
            } elseif ($mapper instanceof SuffixMapper) {
                $this->mappers[$i] = new SuffixMapper(
                    $this->getSuffixes(),
                    $mapper->matchesSinglePart(),
                    $mapper->getReservedParts(),
                );
            } elseif ($mapper instanceof LastnameMapper) {
                $this->mappers[$i] = new LastnameMapper($this->getPrefixes());
            } elseif ($mapper instanceof MiddlenameMapper) {
                $this->mappers[$i] = new MiddlenameMapper(false, $this->getPrefixes());
            }
        }
    }

    /**
     * normalize the name
     */
    protected function normalize(string $name): string
    {
        $whitespace = $this->getWhitespace();

        $name = trim($name);

        // an empty whitespace set has nothing to collapse; building the pattern
        // would emit "/[]+/", an E_WARNING per parse, so short-circuit.
        if ($whitespace === '') {
            return $name;
        }

        // preg_replace returns null on regex compile error; user-set whitespace
        // characters might produce an invalid pattern, so fall back to the input.
        $name = preg_replace($this->normalizePattern($whitespace), ' ', $name) ?? $name;

        // trim again: custom whitespace at the edges becomes a space above and
        // the leading trim() (default charset) would not have removed it.
        return trim($name);
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
        $offset = 0;

        while (($pos = strpos($masked, ',', $offset)) !== false) {
            $segments[] = substr($name, $offset, $pos - $offset);
            $offset = $pos + 1;
        }

        $segments[] = substr($name, $offset);

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

        // hostile megabyte rows would materialize a per-character array below;
        // real names are tiny, so past this size commas split unshielded.
        if (strlen($name) > 4096) {
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
        // skipping the per-character scan on the common bracket-free row
        $openerBytes = implode('', array_merge(array_keys($pairs), array_keys($symmetric)));
        if (strpbrk($name, $openerBytes) === false) {
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
        // lookahead is a bounded list walk instead of a rescan
        /** @var array<string, list<int>> $symmetricEnds */
        $symmetricEnds = [];
        $tokenEnds = [];
        for ($i = 1; $i <= $total; ++$i) {
            if ($this->isStructuralTokenBoundary($chars[$i] ?? null)) {
                $tokenEnds[] = $i;
            }
        }

        foreach (array_keys($symmetric) as $quote) {
            $quote = (string) $quote;
            $quoteChars = mb_str_split($quote, 1, 'UTF-8');
            $len = count($quoteChars);

            foreach ($tokenEnds as $end) {
                $start = $end - $len;
                if ($start >= 0 && $this->charsMatchAt($chars, $start, $quoteChars)) {
                    $symmetricEnds[$quote][] = $start;
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

        for ($i = 0; $i < $length; $i++) {
            if ($budgetInput[$i] === ' ' || $budgetInput[$i] === ',') {
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
