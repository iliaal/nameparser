<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Text;

/**
 * single letter, possibly followed by a period
 *
 * @phpstan-import-type PartArray from AbstractMapper
 */
class InitialMapper extends AbstractMapper
{
    public const int MAX_COMBINED = 64;

    private const int MAX_COMBINED_EXPANSION_PARTS = Text::MAX_INPUT_TOKENS * 2;

    private ?bool $uniformUpperOverride = null;

    /**
     * @param  array<int|string, string>  $prefixes  the lastname-prefix
     *   dictionary, so a surname particle short enough to read as an initial is
     *   left for LastnameMapper to bind
     */
    public function __construct(
        private int $combinedMax = 2,
        protected bool $matchLastPart = false,
        private array $prefixes = [],
    ) {
        if ($combinedMax < 0 || $combinedMax > self::MAX_COMBINED) {
            throw new \InvalidArgumentException(
                'Combined initials limit must be between 0 and ' . self::MAX_COMBINED,
            );
        }
    }

    public function getCombinedMax(): int
    {
        return $this->combinedMax;
    }

    public function matchesLastPart(): bool
    {
        return $this->matchLastPart;
    }

    /**
     * @internal Comma-pipeline whole-input casing signal. Always reset after
     * the parse; the mapper is memoized. Not part of the stable public API.
     */
    public function setUniformUpperOverride(?bool $override): void
    {
        $this->uniformUpperOverride = $override;
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $parts = $this->normalizeParts($parts);
        $last = count($parts) - 1;

        // Uniform-uppercase input cannot distinguish initials from names such as JO.
        $splitCombined = ! $this->isUniformUpperContext($parts, $this->uniformUpperOverride);

        $mapped = [];
        $expandedParts = 0;

        foreach ($parts as $k => $part) {
            if ($part instanceof AbstractPart) {
                $mapped[] = $part;

                continue;
            }

            if (! $this->matchLastPart && $k === $last) {
                $mapped[] = $part;

                continue;
            }

            // Leave particles such as Ó and DE for LastnameMapper, which runs later.
            if ($this->isPrefix($part)) {
                $mapped[] = $part;

                continue;
            }

            if ($splitCombined && Text::isUpperCase($part)) {
                $stripped = str_replace('.', '', $part);
                $length = Text::graphemeLengthUpTo($stripped, $this->combinedMax + 1);

                if (
                    $length > 1
                    && $length <= $this->combinedMax
                    && $stripped !== mb_strtolower($stripped, 'UTF-8')
                ) {
                    $expandedParts += $length;
                    if ($expandedParts > self::MAX_COMBINED_EXPANSION_PARTS) {
                        throw new \LengthException(
                            'Combined initial expansion exceeds the '
                            . self::MAX_COMBINED_EXPANSION_PARTS
                            . '-part limit.',
                        );
                    }

                    foreach (Text::graphemes($stripped) as $initial) {
                        $mapped[] = $this->isInitial($initial) ? new Initial($initial) : $initial;
                    }

                    continue;
                }
            }

            $mapped[] = $this->isInitial($part) ? new Initial($part) : $part;
        }

        return $mapped;
    }

    private function isPrefix(string $part): bool
    {
        return $this->prefixes !== []
            && array_key_exists($this->getKey($part), $this->prefixes);
    }

    protected function isInitial(string $part): bool
    {
        // A caseless character such as 李 is a whole name, not an initial.
        if (Text::graphemeLengthUpTo($part, 2) === 1) {
            return Text::isCased($part);
        }

        return str_ends_with($part, '.')
            && Text::graphemeLengthUpTo(substr($part, 0, -1), 2) === 1
            && Text::isCased($part);
    }
}
