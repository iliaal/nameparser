<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Ignored;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\SalutationConnector;
use Iliaal\NameParser\Text;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class SalutationMapper extends AbstractMapper
{
    /**
     * The article that may sit between the start of the name and an honorific
     * ("The Rev. Mark Williams"). Anything else ends the leading run.
     */
    private const string LEADING_ARTICLE = 'the';

    /**
     * Tokens that join two titles into one honorific ("Mr. and Mrs."), keyed
     * by registry lookup key with the rendered output form as the value. Both
     * default spellings render as "and" so they normalize to one salutation.
     * A language can extend the set via ConnectorsInterface ("Herr und Frau").
     */
    public const array DEFAULT_CONNECTORS = [
        'and' => 'and', '&' => 'and',
    ];

    /**
     * @var array<string, string>
     */
    private array $connectors;

    /**
     * @var array<string, string>
     */
    private array $spanDelimiters;

    /**
     * Salutation keys that are also real personal names, so reading one as an
     * honorific costs a name part. Attested in the bundled NPI corpus: Lord (3
     * surnames), Master (1 surname), Hon (1 given name). Dame, Lady and Pastor
     * are unattested there but collide in other populations (Pastor is both a
     * Spanish surname and a given name). Drives the requireRemainder guard
     * below, and the leading-title note in Confidence.
     */
    public const array NAME_COLLIDING_KEYS = [
        'dame' => true, 'hon' => true, 'lady' => true,
        'lord' => true, 'master' => true, 'pastor' => true,
    ];

    /**
     * Multi-word salutation patterns ("the honorable", "his honour"), split
     * once. Single-word salutations are handled by the exact-match check in
     * matchAt(), so only these need the subset loop.
     *
     * @var list<array{array<int, string>, string}>
     */
    private array $multiWord = [];

    /**
     * @var array<string, true>
     */
    private array $multiWordStarts = [];

    /**
     * Multi-word patterns indexed by first key, longest first, so matchAt()
     * only tries patterns that can open at the current token instead of
     * scanning every pattern per token.
     *
     * @var array<string, list<array{array<int, string>, string}>>
     */
    private array $multiWordByFirst = [];

    /**
     * Lazily-built decoration-analyzer pair for analyzeRemainder() (shared
     * factory construction); built once per mapper instance instead of two
     * throwaway mappers per call. The inputs are constructor-fixed, so the
     * pair never goes stale.
     *
     * @var array{suffix: SuffixMapper, nickname: NicknameMapper}|null
     */
    private ?array $analyzerPair = null;

    /**
     * @param  array<int|string, string>  $salutations
     * @param  bool  $requireRemainder  refuse to consume the segment's last
     *                                  token, for segments the caller has
     *                                  already asserted to be a surname
     * @param  array<int|string, string>  $suffixes
     * @param  array<string, string>  $nicknameDelimiters
     * @param  array<string, string>  $connectors  connector key => rendered
     *                                             form; empty keeps the
     *                                             English defaults
     */
    public function __construct(
        protected array $salutations,
        protected int $maxIndex = 0,
        protected bool $requireRemainder = false,
        protected array $suffixes = [],
        protected array $nicknameDelimiters = [],
        array $connectors = [],
    ) {
        $this->connectors = $connectors === [] ? self::DEFAULT_CONNECTORS : $connectors;
        $this->spanDelimiters = Text::sanitizeNicknameDelimiters($nicknameDelimiters);

        foreach ($salutations as $key => $salutation) {
            if (str_contains((string) $key, ' ')) {
                $keys = explode(' ', (string) $key);
                $this->multiWord[] = [$keys, $salutation];
                $this->multiWordStarts[$keys[0]] = true;
            }
        }

        usort(
            $this->multiWord,
            static fn(array $left, array $right): int => count($right[0]) <=> count($left[0]),
        );

        foreach ($this->multiWord as $pattern) {
            $this->multiWordByFirst[$pattern[0][0]][] = $pattern;
        }
    }

    public function requiresRemainder(): bool
    {
        return $this->requireRemainder;
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $parts = $this->normalizeParts($parts);

        $max = ($this->maxIndex > 0)
            ? min($this->maxIndex, count($parts))
            : max(1, count($parts) - 1);

        $mapped = [];
        $input = 0;
        $scanned = 0;
        $total = count($parts);
        /** @var array{int, int}|null $remainderState */
        $remainderState = null;

        while ($input < $total && $scanned < $max) {
            $current = $parts[$input];

            if ($current instanceof AbstractPart) {
                break;
            }

            [$part, $consumed] = $this->matchAt($parts, $input);

            // a connector joining two titles is part of the honorific, not a
            // given name ("Mr. and Mrs. Brad Smith" keeps Brad as the first
            // name). It needs a title on both sides, so a stray "and" is never
            // absorbed, and it does not count toward the scan budget because it
            // is not itself a title.
            if (is_string($part)
                && isset($this->connectors[$this->getKey($part)])
                && $mapped !== []
                && end($mapped) instanceof Salutation) {
                $next = $input + $consumed;
                [$rightTitle, $rightConsumed] = isset($parts[$next])
                    ? $this->matchAt($parts, $next)
                    : [null, 0];

                if ($rightTitle instanceof Salutation) {
                    $remainderState ??= $this->analyzeRemainder($parts, $next + $rightConsumed);
                }

                if ($rightTitle instanceof Salutation
                    && $remainderState !== null
                    && $remainderState[1] >= $next + $rightConsumed) {
                    $mapped[] = new SalutationConnector($part, $this->connectors[$this->getKey($part)]);
                    $input += $consumed;

                    continue;
                }
            }

            // honorifics lead the name, so only a bare article may sit between
            // the start and a title ("The Rev. Mark Williams"). Once a real name
            // token is seen, a later dictionary hit belongs to the person rather
            // than to a title, so "John Lord Smith Jr" keeps Lord as a middle
            // name. An explicit maxSalutationIndex is the caller asserting that
            // titles do appear further in ("Francis Mr"), so it opts out.
            if ($this->maxIndex <= 0
                && is_string($part)
                && $this->getKey($part) !== self::LEADING_ARTICLE) {
                break;
            }

            // A terminal title/name collision after another mapped title is the
            // only available surname ("Mr. and Mrs. Lord"). The comma form
            // independently asserts that its segment must retain a surname.
            // Unambiguous titles remain salutations in both paths.
            if (isset(self::NAME_COLLIDING_KEYS[$this->getKey($current)])
                && ($this->requireRemainder || end($mapped) instanceof Salutation)) {
                $remainderState ??= $this->analyzeRemainder($parts, $input + $consumed);

                if ($remainderState[0] < $input + $consumed) {
                    break;
                }
            }

            $mapped[] = $part;
            $input += $consumed;
            $scanned++;
        }

        return $this->ignoreUnattributedTokens(
            array_merge($mapped, array_slice($parts, $input)),
            count($mapped),
        );
    }

    /**
     * A conjunction that the honorific did not absorb belongs to nobody: it is
     * neither a title nor a name, so title-casing it into a given or middle name
     * ("Andrew and Sally Smith" reporting the middle name "And") is wrong under
     * every reading. A title directly after such a conjunction addresses a
     * second person ("Mr. Andrew and Mrs Sally Smith"), so it is not this
     * person's name part either.
     *
     * Both are marked Ignored, which no getter exports, rather than dropped, so
     * the text stays visible in Name::getParts(). This does not identify the
     * second person; the given name beside the title is left where it lands.
     *
     * The title rule requires the preceding conjunction on purpose. Several
     * salutation keys double as credentials ("ms" is both Ms. and MS), and this
     * mapper runs before SuffixMapper in the single-segment pipeline, so a blanket
     * mid-stream title rule would swallow the credential in "Jane Doe MS".
     *
     * @param  PartArray  $parts
     * @return PartArray
     */
    private function ignoreUnattributedTokens(array $parts, int $start): array
    {
        $afterConnector = false;

        foreach ($parts as $index => $part) {
            // a nickname between the connector and the title is transparent:
            // "Mr. and (Bob) Mrs. Smith" still has an unattributed Mrs., which
            // must not be title-cased into the first name. Applies to already
            // extracted Nickname parts and raw self-contained span tokens; the
            // span test only matters while a connector is pending.
            if ($index >= $start && $part instanceof Nickname) {
                continue;
            }

            if ($index < $start || ! is_string($part)) {
                $afterConnector = false;

                continue;
            }

            if (isset($this->connectors[$this->getKey($part)])) {
                $parts[$index] = new Ignored($part);
                $afterConnector = true;

                continue;
            }

            if ($afterConnector && Text::isSpanWrappedToken($part, $this->spanDelimiters)) {
                continue;
            }

            $titleLength = $afterConnector
                ? $this->getUnattributedTitleLength($parts, $index)
                : 0;

            for ($offset = 0; $offset < $titleLength; $offset++) {
                $titlePart = $parts[$index + $offset] ?? null;
                if (is_string($titlePart)) {
                    $parts[$index + $offset] = new Ignored($titlePart);
                }
            }

            $afterConnector = false;
        }

        return $parts;
    }

    /**
     * a dictionary title that is not also a real personal name, so reading it as
     * a second addressee's honorific costs nothing ("Lord" and the other
     * NAME_COLLIDING_KEYS stay name parts)
     *
     * @param  PartArray  $parts
     */
    private function getUnattributedTitleLength(array $parts, int $index): int
    {
        [$part, $consumed] = $this->matchAt($parts, $index);

        $current = $parts[$index];

        return $part instanceof Salutation
            && is_string($current)
            && ! isset(self::NAME_COLLIDING_KEYS[$this->getKey($current)])
                ? $consumed
                : 0;
    }

    /**
     * @param  PartArray  $parts
     * @return array{AbstractPart|string, int}
     */
    private function matchAt(array $parts, int $start): array
    {
        $current = $parts[$start];

        if (! is_string($current)) {
            return [$current, 1];
        }

        $currentKey = $this->getKey($current);

        if (! isset($this->multiWordStarts[$currentKey])
            && array_key_exists($currentKey, $this->salutations)) {
            return [new Salutation($current, $this->salutations[$currentKey]), 1];
        }

        foreach ($this->multiWordByFirst[$currentKey] ?? [] as [$keys, $salutation]) {
            $length = count($keys);

            $subset = array_slice($parts, $start, $length);

            if ($this->isMatchingSubset($keys, $subset)) {
                return [new Salutation(implode(' ', $subset), $salutation), $length];
            }
        }

        if (array_key_exists($currentKey, $this->salutations)) {
            return [new Salutation($current, $this->salutations[$currentKey]), 1];
        }

        return [$current, 1];
    }

    /**
     * @param  PartArray  $parts
     * @return array{int, int} last raw-name index and last named-person index
     */
    private function analyzeRemainder(array $parts, int $start): array
    {
        $decorated = array_slice($parts, $start);

        if ($this->suffixes !== [] || $this->nicknameDelimiters !== []) {
            // lazily-reused analyzer pair (shared factory construction): the
            // same suffix-nickname-suffix order as before, without two
            // throwaway mappers and a triple map per call
            $this->analyzerPair ??= self::decorationAnalyzers($this->suffixes, $this->nicknameDelimiters);
            $decorated = $this->analyzerPair['suffix']->map($decorated);
            $decorated = $this->analyzerPair['nickname']->map($decorated);
            $decorated = $this->analyzerPair['suffix']->map($decorated);
        }

        // multi-word match spans precomputed once per call instead of
        // re-scanning every pattern at every token in isSalutationTokenAt()
        $multiWordCover = $this->multiWordSpanCover($decorated);

        $lastRawNameIndex = -1;
        $lastNamedPersonIndex = -1;
        for ($index = count($decorated) - 1; $index >= 0; $index--) {
            $part = $decorated[$index];
            if (! is_string($part) || Text::letters($part) === '') {
                continue;
            }

            $lastRawNameIndex = max($lastRawNameIndex, $start + $index);
            $key = $this->getKey($part);
            if ($key === self::LEADING_ARTICLE || isset($this->connectors[$key])) {
                continue;
            }

            if ($this->isSalutationTokenAt($decorated, $index, $multiWordCover)) {
                continue;
            }

            $lastNamedPersonIndex = $start + $index;

            break;
        }

        return [$lastRawNameIndex, $lastNamedPersonIndex];
    }

    /**
     * Indexes of tokens covered by a multi-word salutation match, computed in
     * one forward pass. Equivalent to the per-token offset loop it replaces:
     * a token is covered exactly when some pattern matches a span containing
     * it, and matchAt() finds the same longest-first match the loop would.
     *
     * @param  PartArray  $parts
     * @return array<int, true>
     */
    private function multiWordSpanCover(array $parts): array
    {
        if ($this->multiWord === []) {
            return [];
        }

        $cover = [];
        $total = count($parts);

        foreach ($parts as $index => $part) {
            if (! is_string($part)) {
                continue;
            }

            [, $consumed] = $this->matchAt($parts, $index);

            if ($consumed > 1) {
                for ($k = $index; $k < $index + $consumed && $k < $total; $k++) {
                    $cover[$k] = true;
                }
            }
        }

        return $cover;
    }

    /**
     * @param  PartArray  $parts
     * @param  array<int, true>|null  $multiWordCover
     */
    private function isSalutationTokenAt(array $parts, int $index, ?array $multiWordCover = null): bool
    {
        $current = $parts[$index] ?? null;
        if (! is_string($current)) {
            return false;
        }

        $key = $this->getKey($current);
        [$part] = $this->matchAt($parts, $index);
        // An isolated colliding token can be the shared surname after a joint
        // title. It is unambiguously a title only within a multi-word match or
        // when a connector explicitly introduces it as the next title.
        if ($part instanceof Salutation && ! isset(self::NAME_COLLIDING_KEYS[$key])) {
            return true;
        }

        if ($multiWordCover !== null) {
            if (isset($multiWordCover[$index])) {
                return true;
            }
        } else {
            foreach ($this->multiWord as [$keys]) {
                for ($offset = 0; $offset < count($keys); $offset++) {
                    $start = $index - $offset;
                    if ($start < 0) {
                        continue;
                    }

                    if ($this->isMatchingSubset($keys, array_slice($parts, $start, count($keys)))) {
                        return true;
                    }
                }
            }
        }

        $previous = $parts[$index - 1] ?? null;

        return $part instanceof Salutation
            && is_string($previous)
            && isset($this->connectors[$this->getKey($previous)]);
    }

    /**
     * check if the given subset matches the given keys entry by entry,
     * which means word by word, except that we first need to key-ify
     * the subset words
     *
     * @param  array<int, string>  $keys
     * @param  PartArray  $subset
     *
     * @phpstan-assert-if-true array<int, string> $subset
     */
    private function isMatchingSubset(array $keys, array $subset): bool
    {
        // array_slice() returns fewer parts than the pattern near the end of the
        // token list; without this a one-token tail would match the first key of
        // a multi-word salutation ("Smith, Her" -> "Her Honour").
        if (count($subset) !== count($keys)) {
            return false;
        }

        for ($i = 0; $i < count($subset); $i++) {
            $part = $subset[$i];
            if (! is_string($part) || $this->getKey($part) !== $keys[$i]) {
                return false;
            }
        }

        return true;
    }
}
