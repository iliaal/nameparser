<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Text;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class NicknameMapper extends AbstractMapper
{
    private const int MAX_NESTING_DEPTH = 64;

    /**
     * default nickname delimiter pairs; also used by Parser for structural
     * comma masking so the two stay in lockstep
     *
     * @var array<string, string>
     */
    public const array DEFAULT_DELIMITERS = [
        '[' => ']',
        '{' => '}',
        '(' => ')',
        '<' => '>',
        '"' => '"',
        '\'' => '\'',
    ];

    /**
     * @var array<string, string>
     */
    protected array $delimiters = self::DEFAULT_DELIMITERS;

    protected string $regexp;

    /**
     * per-map() memo: last part index whose token ends with the given symmetric
     * closer, or null when none does
     *
     * @var array<string, int|null>
     */
    private array $lastCloserIndex = [];

    /**
     * @param  array<string, string>  $delimiters
     */
    public function __construct(array $delimiters = [])
    {
        if ($delimiters !== []) {
            $this->delimiters = $delimiters;
        }

        // an empty-string key compiles to a degenerate pattern that matches
        // every token and warns per parse; an invalid-UTF-8 key or value makes
        // the /u pattern fail compilation with a warning per token. Drop both
        // classes; if nothing valid remains the mapper no-ops (buildRegexp
        // returns '').
        $this->delimiters = Text::sanitizeNicknameDelimiters($this->delimiters);

        $this->regexp = $this->buildRegexp();
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $parts = $this->normalizeParts($parts);

        if ($this->regexp === '') {
            return $parts;
        }

        $this->lastCloserIndex = [];

        $openingDelimiter = '';

        /** @var list<array{open: string, close: string, symmetric: bool}> $delimiterStack */
        $delimiterStack = [];

        /** @var array<string, true> $openSymmetric */
        $openSymmetric = [];

        /** @var PartArray $pending parts mapped under the current still-open delimiter */
        $pending = [];

        /** @var array<int, true> $emptyKeys keys whose cleaned nickname value was empty */
        $emptyKeys = [];

        /** @var list<int> $strayDrops lone symmetric-quote tokens to remove */
        $strayDrops = [];

        $openerBytes = $this->openerBytes();

        foreach ($parts as $k => $part) {
            if ($part instanceof AbstractPart) {
                continue;
            }

            $isEncapsulated = $delimiterStack !== [];

            // most tokens never open a nickname; skip the opener regex when no
            // delimiter byte is present and we are not already inside a span
            if (! $isEncapsulated && $openerBytes !== '' && strpbrk($part, $openerBytes) === false) {
                continue;
            }

            if (preg_match($this->regexp, $part, $matches)) {
                $opener = $matches[1];
                $closer = $this->delimiters[$opener] ?? '';
                $stripped = mb_substr($part, mb_strlen($opener, 'UTF-8'), null, 'UTF-8');
                $isSymmetric = $opener === $closer;

                // a symmetric delimiter (quote) is only an opener when its closing
                // partner appears later; otherwise a leading quote is an elided
                // particle ("'t Hooft") that must survive verbatim.
                $shouldOpen = ! $isSymmetric
                    || (! isset($openSymmetric[$opener])
                        && $this->symmetricCloserAppears($parts, $k, $stripped, $closer));

                if ($shouldOpen && count($delimiterStack) < self::MAX_NESTING_DEPTH) {
                    $delimiterStack[] = [
                        'open' => $opener,
                        'close' => $closer,
                        'symmetric' => $isSymmetric,
                    ];

                    if ($isSymmetric) {
                        $openSymmetric[$opener] = true;
                    }

                    if (! $isEncapsulated) {
                        $part = $stripped;
                        $openingDelimiter = $opener;
                        $pending = [];
                    }
                } elseif (! $isEncapsulated) {
                    if ($stripped === '') {
                        $strayDrops[] = $k;
                    }

                    continue;
                }
            }

            if ($delimiterStack === []) {
                continue;
            }

            $pending[$k] = $parts[$k];

            $closeCount = $this->matchingCloserCount($part, $delimiterStack);
            if ($closeCount > 0) {
                $closed = array_splice($delimiterStack, -$closeCount);

                foreach ($closed as $delimiter) {
                    if ($delimiter['symmetric']) {
                        unset($openSymmetric[$delimiter['open']]);
                    }
                }

                if ($delimiterStack === []) {
                    // the closer may carry glued trailing punctuation
                    // ("(Bob);"); drop it with the closer so it cannot leak
                    // into the nickname value
                    if (! str_ends_with($part, $closed[0]['close'])) {
                        $part = rtrim($part, '.,;:');
                    }

                    $outerCloserLength = mb_strlen($closed[0]['close'], 'UTF-8');
                    $part = mb_substr($part, 0, -$outerCloserLength, 'UTF-8');
                    $pending = [];
                }
            }

            $value = trim($part, '"\'');

            // a lone delimiter pair (" ( ) ") cleans to nothing; emitting an empty
            // Nickname pollutes getNickname() with joined spaces, so drop the token.
            if ($value === '') {
                $emptyKeys[$k] = true;

                continue;
            }

            $parts[$k] = new Nickname($value);
        }

        // an opening delimiter with no matching close is not a nickname: revert
        // the swallowed parts so the surname survives (e.g. "John (Bob Smith").
        if ($delimiterStack !== []) {
            foreach ($pending as $k => $original) {
                $parts[$k] = $original;

                // reverted tokens are restored verbatim, so a value that cleaned
                // empty must not also be dropped below
                unset($emptyKeys[$k]);
            }

            // the opening token still carries its unmatched delimiter char; drop
            // it so a stray "(" or quote does not leak into a name part
            // ("Bob Jones (" must not yield last name "Jones (").
            $open = array_key_first($pending);
            if ($open !== null && is_string($parts[$open])) {
                $cleaned = $parts[$open];
                if (str_starts_with($cleaned, $openingDelimiter)) {
                    $cleaned = mb_substr($cleaned, mb_strlen($openingDelimiter, 'UTF-8'), null, 'UTF-8');
                }

                $cleaned = rtrim($cleaned, ',;');
                if ($cleaned === '') {
                    unset($parts[$open]);
                } else {
                    $parts[$open] = $cleaned;
                }
            }
        }

        foreach ($strayDrops as $k) {
            unset($parts[$k]);
        }

        foreach (array_keys($emptyKeys) as $k) {
            unset($parts[$k]);
        }

        return array_values($parts);
    }

    /**
     * @param  list<array{open: string, close: string, symmetric: bool}>  $delimiterStack
     */
    private function matchingCloserCount(string $part, array $delimiterStack): int
    {
        $matches = $this->closerCountFor($part, $delimiterStack, false);
        if ($matches > 0) {
            return $matches;
        }

        // an asymmetric closer with glued trailing punctuation ("(Bob);") still
        // closes the span; the token-final rule only protects symmetric quotes
        // (a quote inside "'t Hooft" must not close anything)
        $trimmed = rtrim($part, '.,;:');
        if ($trimmed === $part || $trimmed === '') {
            return 0;
        }

        return $this->closerCountFor($trimmed, $delimiterStack, true);
    }

    /**
     * @param  list<array{open: string, close: string, symmetric: bool}>  $delimiterStack
     */
    private function closerCountFor(string $part, array $delimiterStack, bool $asymmetricOnly): int
    {
        $suffix = '';
        $matches = 0;
        $partLength = strlen($part);

        for ($i = count($delimiterStack) - 1; $i >= 0; $i--) {
            if ($asymmetricOnly && $delimiterStack[$i]['symmetric']) {
                break;
            }

            $closer = $delimiterStack[$i]['close'];
            if ($closer === '') {
                break;
            }

            $suffix .= $closer;
            if (strlen($suffix) > $partLength) {
                break;
            }

            if (str_ends_with($part, $suffix)) {
                $matches = count($delimiterStack) - $i;
            }
        }

        return $matches;
    }

    /**
     * whether a symmetric delimiter opened at $openKey has a matching closer
     * later: the same token's tail, or a subsequent token ending with $closer.
     * The last token index ending with each closer is precomputed per map()
     * call, so a run of unmatched openers stays linear instead of rescanning
     * the remaining parts for every one.
     *
     * @param  PartArray  $parts
     */
    private function symmetricCloserAppears(array $parts, int $openKey, string $stripped, string $closer): bool
    {
        $closerLength = mb_strlen($closer, 'UTF-8');

        if ($stripped !== '' && mb_substr($stripped, -$closerLength, null, 'UTF-8') === $closer) {
            return true;
        }

        if (! array_key_exists($closer, $this->lastCloserIndex)) {
            $last = null;
            foreach ($parts as $k => $part) {
                if (! is_string($part)
                    || mb_substr($part, -$closerLength, null, 'UTF-8') !== $closer) {
                    continue;
                }

                // a self-balanced quoted token ("'Genius'") closes itself; its
                // tail quote is not a closer for an earlier orphan opener, or a
                // leading elided particle ("'t") would swallow the row
                if (mb_strlen($part, 'UTF-8') >= $closerLength * 2
                    && str_starts_with($part, $closer)) {
                    continue;
                }

                $last = $k;
            }

            $this->lastCloserIndex[$closer] = $last;
        }

        $last = $this->lastCloserIndex[$closer];

        return $last !== null && $last > $openKey;
    }

    protected function buildRegexp(): string
    {
        if (empty($this->delimiters)) {
            return '';
        }

        $keys = array_keys($this->delimiters);

        // longest opener first so a multi-char delimiter ("<<") wins over a
        // single-char prefix ("<") when both are configured
        usort($keys, static fn(string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        $alternation = implode('|', array_map(
            static fn(string $key): string => preg_quote($key, '/'),
            $keys
        ));

        return '/^(' . $alternation . ')/u';
    }

    /**
     * concatenated opener characters for a cheap strpbrk prefilter
     */
    private function openerBytes(): string
    {
        return implode('', array_keys($this->delimiters));
    }
}
