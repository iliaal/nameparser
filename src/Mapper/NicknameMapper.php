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
     * @var array<string, array{int, int}|null> Next future token and byte offset for each closer and boundary-search mode.
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

        /** @var list<array{open: string, close: string, symmetric: bool, adjacent?: bool, closerPosition?: array{int, int}, embedded?: bool}> $delimiterStack */
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

            $original = $part;
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

            $embeddedCount = $this->matchingEmbeddedCloserCount($original, $k, $delimiterStack);
            $closeCount = max($embeddedCount, $this->matchingCloserCount($part, $delimiterStack));
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
     * @param  list<array{open: string, close: string, symmetric: bool, adjacent?: bool, closerPosition?: array{int, int}, embedded?: bool}>  $delimiterStack
     * @param  PartArray  $parts
     */
    private function appendNestedOpeners(string $remaining, array &$delimiterStack, array $parts, int $openKey): void
    {
        $prefix = $remaining;
        $offset = 0;
        $outerOpenerLength = strlen($delimiterStack[count($delimiterStack) - 1]['open']);
        /** @var list<array{open: string, close: string, symmetric: bool, offset: int, closerPosition?: array{int, int}, embedded?: bool}> $nested */
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
        $ignoreOwned = $nested !== [] && count($delimiterStack) + count($nested) < self::MAX_NESTING_DEPTH
            && $this->hasCompleteClosingSequence(array_merge($delimiterStack, $nested), $parts, $openKey, $remaining, $offset);
        $after = null;
        for ($i = count($nested) - 1; $i >= 0; --$i) {
            $candidate = $nested[$i];
            $start = $after ?? [$openKey, $candidate['offset']];
            $innerPosition = $this->nextNestedCloser($parts, $openKey, $remaining, $candidate['close'], $start, false);

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
                    false,
                );
            }

            if ($innerPosition !== null) {
                foreach (array_merge($delimiterStack, array_slice($nested, 0, $i)) as $enclosing) {
                    if ($enclosing['close'] === $candidate['close']) {
                        continue;
                    }

                    $outerPosition = $this->nextNestedCloser($parts, $openKey, $remaining, $enclosing['close'], $start, $ignoreOwned);
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
                $candidate['closerPosition'] = [
                    $innerPosition[0],
                    $innerPosition[1] + ($innerPosition[0] === $openKey ? $outerOpenerLength : 0),
                ];
                $nested[$i] = $candidate;
                $end = [$innerPosition[0], $innerPosition[1] + strlen($candidate['close'])];
                $after = $after === null || $end > $after ? $end : $after;
            }
        }

        foreach (array_slice($nested, 0, $accepted) as $candidate) {
            unset($candidate['offset']);
            $candidate['adjacent'] = true;
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
    private function nextNestedCloser(array $parts, int $openKey, string $remaining, string $closer, array $start, bool $ignoreOwned): ?array
    {
        $cacheKey = ($ignoreOwned ? 'owned:' : 'raw:') . $closer;
        if ($start[0] === $openKey) {
            $offset = strpos($remaining, $closer, $start[1]);
            if ($offset !== false) {
                return [$openKey, $offset];
            }

            if (array_key_exists($cacheKey, $this->nextNestedCloserPosition)) {
                $next = $this->nextNestedCloserPosition[$cacheKey];
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
            if ($offset !== false && $ignoreOwned && $this->isOwnedStandaloneCloser($parts[$i], $closer, $offset)) {
                continue;
            }
            if ($offset !== false) {
                $next = [$i, $offset];
                if ($start[0] === $openKey) {
                    $this->nextNestedCloserPosition[$cacheKey] = $next;
                }

                return $next;
            }
        }

        if ($start[0] === $openKey) {
            $this->nextNestedCloserPosition[$cacheKey] = null;
        }

        return null;
    }

    /**
     * Relax an owned boundary only with a complete closing token for the
     * tracked stack. Intervening openers must be self-contained single frames;
     * a later independent nickname cannot donate a missing enclosing closer.
     * One token is scanned at most once per frame, without recursive parsing.
     *
     * @param list<array{open: string, close: string, symmetric: bool, adjacent?: bool, closerPosition?: array{int, int}, embedded?: bool, offset?: int}> $frames
     * @param PartArray $parts
     */
    private function hasCompleteClosingSequence(array $frames, array $parts, int $openKey, string $remaining, int $offset): bool
    {
        $inner = $frames[count($frames) - 1];
        $position = $this->nextNestedCloser($parts, $openKey, $remaining, $inner['close'], [$openKey, $offset], false);
        if ($position === null) {
            return false;
        }
        [$key, $offset] = $position;
        $closing = $key === $openKey ? $remaining : $parts[$key];
        if (! is_string($closing) || ($key !== $openKey && preg_match($this->regexp, $closing))) {
            return false;
        }
        $ordinarySuffix = false;
        foreach (array_reverse($frames) as $frame) {
            $next = strpos($closing, $frame['close'], $offset);
            if ($next === false || ($ordinarySuffix && $next !== $offset)) {
                return false;
            }
            $offset = $next + strlen($frame['close']);
            // Only inferred frames may close before the consumable suffix.
            $ordinarySuffix = $ordinarySuffix || (! ($frame['adjacent'] ?? false) && ! isset($frame['offset']));
        }
        if ($offset !== strlen($closing)
            && ($frames[0]['symmetric'] || $offset !== strlen(rtrim($closing, '.,;:')))) {
            return false;
        }
        for ($i = $openKey + 1; $i < $key; ++$i) {
            $part = $parts[$i];
            if (! is_string($part) || ! preg_match($this->regexp, $part, $matches)) {
                continue;
            }
            $close = $this->delimiters[$matches[1]] ?? '';
            if ($close === '') {
                return false;
            }
            $owned = strpos($part, $close);
            if ($owned === false || ! $this->isOwnedStandaloneCloser($part, $close, $owned)) {
                return false;
            }
        }
        return true;
    }

    private function isOwnedStandaloneCloser(string $part, string $closer, int $offset): bool
    {
        if (! preg_match($this->regexp, $part, $matches)) {
            return false;
        }
        $opener = $matches[1];
        if ($opener === '' || $opener === $closer || ($this->delimiters[$opener] ?? null) !== $closer
            || ! str_starts_with($part, $opener) || $offset < strlen($opener)
            || strpos($part, $closer, strlen($opener)) !== $offset
            || strpos($part, $closer, $offset + 1) !== false) {
            return false;
        }
        $end = $offset + strlen($closer);
        if ($end !== strlen($part) && $end !== strlen(rtrim($part, '.,;:'))) {
            return false;
        }
        // A single ordinary opener with its sole closer at the suffix owns it.
        // Leave adjacent prefixes and surplus closing occurrences to streaming.
        $body = substr($part, strlen($opener), $offset - strlen($opener));
        return ! preg_match($this->regexp, $body);
    }

    /**
     * Remember validated embedded closers without dropping their frames: a
     * later suffix may still need them to match around an ordinary opener.
     * Ordinary frames cannot use embedded closers. Distinct byte positions
     * prevent identical/overlapping closers from satisfying two frames.
     *
     * @param list<array{open: string, close: string, symmetric: bool, adjacent?: bool, closerPosition?: array{int, int}, embedded?: bool}> $stack
     */
    private function matchingEmbeddedCloserCount(string $part, int $key, array &$stack): int
    {
        $consumed = 0;
        $length = strlen(rtrim($part, '.,;:'));
        for ($i = count($stack) - 1; $i >= 0; --$i) {
            $top = $stack[$i];
            if ($top['embedded'] ?? false) {
                continue;
            }
            $position = $top['closerPosition'] ?? null;
            if (! ($top['adjacent'] ?? false) || $position === null || $position[0] > $key) {
                break;
            }
            if ($position[0] < $key || $position[1] < $consumed) {
                // An ordinary frame may have owned the lookahead occurrence.
                // Advance locally, never scan future tokens or cross a distinct
                // enclosing boundary while trying another embedded closer.
                $offset = strpos($part, $top['close'], $consumed);
                if ($offset === false) {
                    break;
                }
                foreach (array_slice($stack, 0, $i) as $enclosing) {
                    if ($enclosing['close'] === $top['close']) {
                        continue;
                    }
                    $boundary = strpos($part, $enclosing['close'], $consumed);
                    if ($boundary !== false && $offset + strlen($top['close']) > $boundary) {
                        break 2;
                    }
                }
                $position = [$key, $offset];
            }
            $end = $position[1] + strlen($top['close']);
            if ($end >= $length) {
                break;
            }
            $consumed = $end;
            $top['embedded'] = true;
            $stack[$i] = $top;
        }

        $remaining = [];
        $indices = [];
        foreach ($stack as $i => $frame) {
            if (! ($frame['embedded'] ?? false)) {
                $indices[] = $i;
                $remaining[] = $frame;
            }
        }
        if (count($remaining) === count($stack)) {
            return 0;
        }
        $suffix = substr($part, $consumed);
        $matches = $this->closerCountFor($suffix, $remaining, false);
        if ($matches === 0) {
            $trimmed = rtrim($suffix, '.,;:');
            if ($trimmed !== $suffix && $trimmed !== '') {
                $matches = $this->closerCountFor($trimmed, $remaining, true);
            }
        }
        return $matches === 0 ? 0 : count($stack) - $indices[count($remaining) - $matches];
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
     * @param  list<array{open: string, close: string, symmetric: bool, adjacent?: bool, closerPosition?: array{int, int}, embedded?: bool}>  $delimiterStack
     */
    private function matchingCloserCount(string $part, array $delimiterStack): int
    {
        $matches = $this->closerCountFor($part, $delimiterStack, false);
        if ($matches > 0) {
            return $matches;
        }

        // Asymmetric closers may carry punctuation; symmetric quotes must end the token.
        $trimmed = rtrim($part, '.,;:');
        if ($trimmed !== $part && $trimmed !== '') {
            $matches = $this->closerCountFor($trimmed, $delimiterStack, true);
            if ($matches > 0) {
                return $matches;
            }
        }

        // A raw closer occurrence can make an adjacent prefix look nested even
        // when no token suffix can consume it. Prefer ordinary closing above,
        // then keep unsupported adjacent prefixes as literal nickname text.
        $matches = $this->closerCountSkippingAdjacent($part, $delimiterStack, false);
        if ($matches > 0 || $trimmed === $part || $trimmed === '') {
            return $matches;
        }
        return $this->closerCountSkippingAdjacent($trimmed, $delimiterStack, true);
    }

    /**
     * Match a closing suffix while allowing only inferred adjacent frames to
     * stay literal. Ordinary token-initial openers are mandatory, even when an
     * inferred frame lies below them, as in ([Bob [Rob]). Offset sets avoid
     * enumerating subsets of the at-most-64-frame stack. With sanitized
     * closers of at most 64 bytes, there are at most 4,097 offsets.
     *
     * @param list<array{open: string, close: string, symmetric: bool, adjacent?: bool, closerPosition?: array{int, int}, embedded?: bool}> $stack
     */
    private function closerCountSkippingAdjacent(string $part, array $stack, bool $asymmetricOnly): int
    {
        $depth = count($stack);
        if ($depth < 2 || array_column($stack, 'adjacent') === []) {
            return 0;
        }
        $top = $stack[$depth - 1];
        if (! ($top['adjacent'] ?? false) && ! str_contains($part, $top['close'])) {
            return 0;
        }
        $length = strlen($part);
        // Prefer fewer discarded prefixes, then the outermost closing frame.
        // Equal offsets have identical remaining work, so only their lowest
        // score is needed. One discard outweighs every possible frame index.
        $discardCost = self::MAX_NESTING_DEPTH + 1;
        $positions = [];
        foreach ($stack as $i => $delimiter) {
            $next = [];
            if ($delimiter['adjacent'] ?? false) {
                foreach ($positions as $end => $score) {
                    $next[$end] = $score + $discardCost;
                }
            }
            $closer = $delimiter['close'];
            $closerLength = strlen($closer);
            if ($closerLength !== 0 && (! $asymmetricOnly || ! $delimiter['symmetric'])) {
                foreach ($positions as $end => $score) {
                    if ($end >= $closerLength
                        && substr_compare($part, $closer, $end - $closerLength, $closerLength) === 0) {
                        $offset = $end - $closerLength;
                        $next[$offset] = min($next[$offset] ?? $score, $score);
                    }
                }
                // A new candidate consumes its enclosing closer, never skips it.
                if ($i < $depth - 1 && str_ends_with($part, $closer)) {
                    $offset = $length - $closerLength;
                    $next[$offset] = min($next[$offset] ?? $i, $i);
                }
            }
            $positions = $next;
        }
        return $positions === [] ? 0 : $depth - (min($positions) % $discardCost);
    }

    /**
     * @param  list<array{open: string, close: string, symmetric: bool, adjacent?: bool, closerPosition?: array{int, int}, embedded?: bool}>  $delimiterStack
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
