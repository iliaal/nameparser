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
     * Shared with structural-comma masking.
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
     * @var array<string, array{int, int}|null> Next future token and byte offset for each closer.
     */
    private array $nextNestedCloserPosition = [];

    private string $quoteTrimCharacters = '';

    /**
     * @param  array<string, string>  $delimiters
     */
    public function __construct(array $delimiters = [])
    {
        if ($delimiters !== []) {
            $this->delimiters = $delimiters;
        }

        // Reject delimiter keys that would make the Unicode regex invalid or match every token.
        $this->delimiters = Text::sanitizeNicknameDelimiters($this->delimiters);
        $this->quoteTrimCharacters = (($this->delimiters['"'] ?? null) === '"' ? '"' : '')
            . (($this->delimiters["'"] ?? null) === "'" ? "'" : '');

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
        $this->nextNestedCloserPosition = [];

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

            if (! $isEncapsulated && $openerBytes !== '' && strpbrk($part, $openerBytes) === false) {
                continue;
            }

            if (preg_match($this->regexp, $part, $matches)) {
                $opener = $matches[1];
                $closer = $this->delimiters[$opener] ?? '';
                $stripped = self::stripOpener($part, $opener);
                $isSymmetric = $opener === $closer;

                // An unmatched leading quote may be an elided particle ("'t Hooft").
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

                    // Adjacent asymmetric openers belong to the same span, but
                    // only the outermost delimiter is removed from its text.
                    if ($opener !== '' && str_starts_with($part, $opener)
                        && mb_check_encoding($part, 'UTF-8')) {
                        $this->appendNestedOpeners($stripped, $delimiterStack, $parts, $k);
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
                    // Drop punctuation glued to the closer, as in "(Bob);".
                    if (! str_ends_with($part, $closed[0]['close'])) {
                        $part = rtrim($part, '.,;:');
                    }

                    $outerCloserLength = mb_strlen($closed[0]['close'], 'UTF-8');
                    $part = mb_substr($part, 0, -$outerCloserLength, 'UTF-8');
                    $pending = [];
                }
            }

            $value = $this->quoteTrimCharacters === '' ? $part : trim($part, $this->quoteTrimCharacters);

            // Empty nickname parts would add spaces to getNickname().
            if ($value === '') {
                $emptyKeys[$k] = true;

                continue;
            }

            $parts[$k] = new Nickname($value);
        }

        // Restore unclosed spans so "John (Bob Smith" keeps its surname.
        if ($delimiterStack !== []) {
            foreach ($pending as $k => $original) {
                $parts[$k] = $original;

                // Restored raw tokens must not also be dropped as empty nicknames.
                unset($emptyKeys[$k]);
            }

            // Remove a lone unmatched opener so "Bob Jones (" keeps surname Jones.
            $open = array_key_first($pending);
            if ($open !== null && is_string($parts[$open])) {
                $cleaned = $parts[$open];
                if (str_starts_with($cleaned, $openingDelimiter)) {
                    $cleaned = self::stripOpener($cleaned, $openingDelimiter);
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
     * @param  PartArray  $parts
     */
    private function appendNestedOpeners(string $remaining, array &$delimiterStack, array $parts, int $openKey): void
    {
        $prefix = $remaining;
        $offset = 0;
        /** @var list<array{open: string, close: string, symmetric: bool, offset: int}> $nested */
        $nested = [];
        while (count($delimiterStack) + count($nested) < self::MAX_NESTING_DEPTH
            && preg_match($this->regexp, $prefix, $matches)) {
            $open = $matches[1];
            $close = $this->delimiters[$open] ?? '';
            $parentClose = $nested === []
                ? $delimiterStack[count($delimiterStack) - 1]['close']
                : $nested[count($nested) - 1]['close'];

            // Closers take precedence over overlapping custom openers.
            // Quotes must start a token, and custom regex captures must be
            // configured, nonempty prefixes before consuming another opener.
            if (str_starts_with($prefix, $parentClose)
                || $open === '' || $close === '' || $open === $close
                || ! str_starts_with($prefix, $open)) {
                break;
            }

            $offset += strlen($open);
            $nested[] = ['open' => $open, 'close' => $close, 'symmetric' => false, 'offset' => $offset];
            $prefix = self::stripOpener($prefix, $open);
        }

        // Validate inside out: a descendant may consume an enclosing closer,
        // as the inner parentheses do in ([(Bob) Robert] Z).
        $accepted = count($nested);
        $after = null;
        for ($i = count($nested) - 1; $i >= 0; --$i) {
            $candidate = $nested[$i];
            $start = $after ?? [$openKey, $candidate['offset']];
            $innerPosition = $this->nextNestedCloser($parts, $openKey, $remaining, $candidate['close'], $start);

            // Identical closers still imply nesting when the outer span is
            // incomplete, preserving restoration of inputs such as (((Bob).
            if ($innerPosition === null && $i + 1 < $accepted
                && $candidate['close'] === $nested[$i + 1]['close']) {
                $innerPosition = $this->nextNestedCloser(
                    $parts,
                    $openKey,
                    $remaining,
                    $candidate['close'],
                    [$openKey, $candidate['offset']],
                );
            }

            if ($innerPosition !== null) {
                foreach (array_merge($delimiterStack, array_slice($nested, 0, $i)) as $enclosing) {
                    if ($enclosing['close'] === $candidate['close']) {
                        continue;
                    }

                    $outerPosition = $this->nextNestedCloser($parts, $openKey, $remaining, $enclosing['close'], $start);
                    if ($outerPosition !== null
                        && ($innerPosition[0] > $outerPosition[0]
                            || ($innerPosition[0] === $outerPosition[0]
                                && $innerPosition[1] + strlen($candidate['close']) > $outerPosition[1]))) {
                        $innerPosition = null;

                        break;
                    }
                }
            }

            if ($innerPosition === null) {
                $accepted = $i;
                $after = null;
            } else {
                $end = [$innerPosition[0], $innerPosition[1] + strlen($candidate['close'])];
                $after = $after === null || $end > $after ? $end : $after;
            }
        }

        foreach (array_slice($nested, 0, $accepted) as $candidate) {
            unset($candidate['offset']);
            $delimiterStack[] = $candidate;
        }
    }

    /**
     * Cache the next future occurrence from the current opening token. Searches
     * after a matched descendant are local to the bounded adjacent opener chain.
     *
     * @param  PartArray  $parts
     * @param  array{int, int}  $start
     * @return array{int, int}|null
     */
    private function nextNestedCloser(array $parts, int $openKey, string $remaining, string $closer, array $start): ?array
    {
        if ($start[0] === $openKey) {
            $offset = strpos($remaining, $closer, $start[1]);
            if ($offset !== false) {
                return [$openKey, $offset];
            }

            if (array_key_exists($closer, $this->nextNestedCloserPosition)) {
                $next = $this->nextNestedCloserPosition[$closer];
                if ($next === null || $next[0] > $openKey) {
                    return $next;
                }
            }
        }

        $total = count($parts);
        for ($i = max($openKey + 1, $start[0]); $i < $total; ++$i) {
            if (! is_string($parts[$i])) {
                continue;
            }

            $offset = strpos($parts[$i], $closer, $i === $start[0] ? $start[1] : 0);
            if ($offset !== false) {
                $next = [$i, $offset];
                if ($start[0] === $openKey) {
                    $this->nextNestedCloserPosition[$closer] = $next;
                }

                return $next;
            }
        }

        if ($start[0] === $openKey) {
            $this->nextNestedCloserPosition[$closer] = null;
        }

        return null;
    }

    private static function stripOpener(string $part, string $opener): string
    {
        // Keep mbstring semantics for subclasses overriding the opener regex.
        return str_starts_with($part, $opener)
            && mb_check_encoding($opener, 'UTF-8')
            && mb_check_encoding($part, 'UTF-8')
            ? substr($part, strlen($opener))
            : mb_substr($part, mb_strlen($opener, 'UTF-8'), null, 'UTF-8');
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

        // Asymmetric closers may carry punctuation; symmetric quotes must end the token.
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
     * Cache the last closer per quote to keep repeated unmatched openers linear.
     *
     * @param  PartArray  $parts
     */
    private function symmetricCloserAppears(array $parts, int $openKey, string $stripped, string $closer): bool
    {
        $closerLength = mb_strlen($closer, 'UTF-8');

        if ($stripped !== '' && self::endsWithCloser($stripped, $closer, $closerLength)) {
            return true;
        }

        if (! array_key_exists($closer, $this->lastCloserIndex)) {
            $last = null;
            foreach ($parts as $k => $part) {
                if (! is_string($part)
                    || ! self::endsWithCloser($part, $closer, $closerLength)) {
                    continue;
                }

                // Self-balanced quoted tokens cannot close an earlier orphan quote.
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

    private static function endsWithCloser(string $part, string $closer, int $closerLength): bool
    {
        // Valid UTF-8 needs only a byte suffix check; retain mbstring substitution
        // semantics for malformed strings passed directly to the mapper.
        return mb_check_encoding($part, 'UTF-8')
            ? str_ends_with($part, $closer)
            : mb_substr($part, -$closerLength, null, 'UTF-8') === $closer;
    }

    protected function buildRegexp(): string
    {
        if (empty($this->delimiters)) {
            return '';
        }

        $keys = array_keys($this->delimiters);

        // Longest opener wins, so << takes precedence over <.
        usort($keys, static fn(string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        $alternation = implode('|', array_map(
            static fn(string $key): string => preg_quote($key, '/'),
            $keys
        ));

        return '/^(' . $alternation . ')/u';
    }

    private function openerBytes(): string
    {
        return implode('', array_keys($this->delimiters));
    }
}
