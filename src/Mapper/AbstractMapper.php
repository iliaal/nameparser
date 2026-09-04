<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Text;

/**
 * @phpstan-type PartArray array<int, AbstractPart|string>
 */
abstract class AbstractMapper
{
    /**
     * implements the mapping of parts
     *
     * @param  PartArray  $parts
     * @return PartArray
     */
    abstract public function map(array $parts): array;

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    protected function normalizeParts(array $parts): array
    {
        return array_is_list($parts) ? $parts : array_values($parts);
    }

    /**
     * checks if there are still unmapped parts left before the given position
     *
     * @param  PartArray  $parts
     */
    protected function hasUnmappedPartsBefore(array $parts, int $index): bool
    {
        foreach ($parts as $k => $part) {
            if ($k === $index) {
                break;
            }

            if (! ($part instanceof AbstractPart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  class-string  $type
     * @param  PartArray  $parts
     */
    protected function findFirstMapped(string $type, array $parts): int|false
    {
        $total = count($parts);

        for ($i = 0; $i < $total; $i++) {
            if ($parts[$i] instanceof $type) {
                return $i;
            }
        }

        return false;
    }

    /**
     * get the registry lookup key for the given word
     */
    protected function getKey(string $word): string
    {
        return Text::key($word);
    }

    /**
     * true when every unmapped cased token is uppercase, i.e. the input casing
     * gives no signal (all-caps registry data). Already-mapped parts are
     * ignored because their normalized values may differ from the original
     * token casing. When $override is non-null it is returned as-is (comma
     * pipeline whole-input signal). Single-sourced through
     * Text::isUniformUpperTokens(); caseless and digit-only tokens carry no
     * signal either way.
     *
     * @param  PartArray  $parts
     */
    protected function isUniformUpperContext(array $parts, ?bool $override = null): bool
    {
        if ($override !== null) {
            return $override;
        }

        $tokens = [];

        foreach ($parts as $part) {
            if ($part instanceof AbstractPart) {
                continue;
            }

            $tokens[] = $part;
        }

        return Text::isUniformUpperTokens($tokens);
    }

    /**
     * Centralized reset for the sticky @internal whole-input casing overrides
     * (CR-023 minimal path: no parse-context map() argument, so the temporal
     * coupling remains and is contained here). A map() signature change would
     * ripple through every Parser call site plus external callers; instead the
     * override stays setter-carried and Parser::parse() funnels its entry reset
     * through this one helper rather than an inline instanceof loop.
     * Confidence never sets overrides (fresh mappers per assess()), so only
     * the Parser pipelines need it.
     *
     * @param  iterable<int, AbstractMapper>  $mappers
     */
    public static function resetUniformUpperOverrides(iterable $mappers): void
    {
        foreach ($mappers as $mapper) {
            if ($mapper instanceof SuffixMapper || $mapper instanceof InitialMapper) {
                $mapper->setUniformUpperOverride(null);
            }
        }
    }

    /**
     * Shared decoration-analyzer construction (CR-023 minimal path: the
     * factory both Confidence and SalutationMapper route through instead of
     * each hand-building the pair). Ordering knowledge stays with the caller:
     * Confidence maps nickname-then-suffix, SalutationMapper::analyzeRemainder
     * maps suffix-nickname-suffix; only the construction (dictionaries,
     * matchSinglePart, reservedParts) is shared here.
     *
     * @param  array<int|string, string>  $suffixes
     * @param  array<string, string>  $delimiters
     * @return array{suffix: SuffixMapper, nickname: NicknameMapper}
     */
    public static function decorationAnalyzers(array $suffixes, array $delimiters): array
    {
        return [
            'suffix' => new SuffixMapper($suffixes, true, 0, $delimiters),
            'nickname' => new NicknameMapper($delimiters),
        ];
    }
}
