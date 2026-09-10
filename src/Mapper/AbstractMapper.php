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

    protected function getKey(string $word): string
    {
        return Text::key($word);
    }

    /**
     * Mapped parts may have normalized casing, so only unmapped tokens carry
     * the input signal. A comma-pipeline override supplies whole-input casing.
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
     * Reset whole-input casing overrides before reusing memoized parser mappers.
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
     * Construction is shared; callers retain their decoration-mapping order.
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
