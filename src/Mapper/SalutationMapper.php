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
     * Only the article may precede a leading honorific.
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
     * Name collisions attested in NPI data include Lord, Master, and Hon;
     * Dame, Lady, and Pastor collide in other populations. Used by the
     * remainder guard and confidence assessment.
     */
    public const array NAME_COLLIDING_KEYS = [
        'dame' => true, 'hon' => true, 'lady' => true,
        'lord' => true, 'master' => true, 'pastor' => true,
    ];

    /**
     * @var list<array{array<int, string>, string}>
     */
    private array $multiWord = [];

    /**
     * @var array<string, true>
     */
    private array $multiWordStarts = [];

    /**
     * Index by first key, longest match first.
     *
     * @var array<string, list<array{array<int, string>, string}>>
     */
    private array $multiWordByFirst = [];

    /**
     * Constructor-fixed inputs make the cached analyzer pair safe to reuse.
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

            // Connectors require a title on both sides and do not consume the title budget.
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

            // Titles normally form a leading run; an explicit maxSalutationIndex
            // allows later titles. Preserve Lord in "John Lord Smith Jr".
            if ($this->maxIndex <= 0
                && is_string($part)
                && $this->getKey($part) !== self::LEADING_ARTICLE) {
                break;
            }

            // A final colliding title may be the only surname: "Mr. and Mrs. Lord".
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
     * Unabsorbed connectors and their following titles remain visible as
     * Ignored parts without being exported as names. This does not identify
     * the second person or reassign their given name.
     *
     * Require a connector before ignoring a title: Ms./MS also denotes a
     * credential, and SuffixMapper has not yet run.
     *
     * @param  PartArray  $parts
     * @return PartArray
     */
    private function ignoreUnattributedTokens(array $parts, int $start): array
    {
        $afterConnector = false;

        foreach ($parts as $index => $part) {
            // Nicknames between a connector and title do not break their association.
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
     * Exclude colliding personal names when identifying an unattributed title.
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
            $this->analyzerPair ??= self::decorationAnalyzers($this->suffixes, $this->nicknameDelimiters);
            $decorated = $this->analyzerPair['suffix']->map($decorated);
            $decorated = $this->analyzerPair['nickname']->map($decorated);
            $decorated = $this->analyzerPair['suffix']->map($decorated);
        }

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
     * Precompute multi-word salutation coverage in one forward pass.
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
        // A colliding token may be the shared surname; a multi-word match or
        // preceding connector is needed to identify it as another title.
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
     * @param  array<int, string>  $keys
     * @param  PartArray  $subset
     *
     * @phpstan-assert-if-true array<int, string> $subset
     */
    private function isMatchingSubset(array $keys, array $subset): bool
    {
        // A truncated tail must not match a longer title: Her is not Her Honour.
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
