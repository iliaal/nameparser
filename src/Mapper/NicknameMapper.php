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
     * @param  array<string, string>  $delimiters
     */
    public function __construct(array $delimiters = [])
    {
        if ($delimiters !== []) {
            $this->delimiters = $delimiters;
        }

        // Reject delimiter keys that would make the Unicode regex invalid or match every token.
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

            if (! $isEncapsulated && $openerBytes !== '' && strpbrk($part, $openerBytes) === false) {
                continue;
            }

            if (preg_match($this->regexp, $part, $matches)) {
                $opener = $matches[1];
                $closer = $this->delimiters[$opener] ?? '';
                $stripped = mb_substr($part, mb_strlen($opener, 'UTF-8'), null, 'UTF-8');
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

            $value = trim($part, '"\'');

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
