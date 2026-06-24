<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Initial;

/**
 * single letter, possibly followed by a period
 *
 * @phpstan-import-type PartArray from AbstractMapper
 */
class InitialMapper extends AbstractMapper
{
    public function __construct(
        private int $combinedMax = 2,
        protected bool $matchLastPart = false,
    ) {}

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $last = count($parts) - 1;

        // Splitting an all-uppercase token into separate initials ("JM" -> J M)
        // reads the caps as "these are initials". Under uniform-uppercase input
        // (legacy/registry data) caps carry no signal, so the same heuristic
        // shreds two-letter given names ("JO" -> J O). Suppress the split there
        // and keep the token as a name, mirroring the casing-as-signal policy of
        // SuffixMapper.
        $splitCombined = ! $this->isUniformUpperContext($parts);

        for ($k = 0; $k < count($parts); $k++) {
            $part = $parts[$k];

            if ($part instanceof AbstractPart) {
                continue;
            }

            if (! $this->matchLastPart && $k === $last) {
                continue;
            }

            if ($splitCombined && mb_strtoupper($part, 'UTF-8') === $part) {
                $stripped = str_replace('.', '', $part);
                $length = mb_strlen($stripped, 'UTF-8');

                if ($length > 1 && $length <= $this->combinedMax) {
                    array_splice($parts, $k, 1, mb_str_split($stripped));
                    $last = count($parts) - 1;
                    $part = $parts[$k];
                }
            }

            if (is_string($part) && $this->isInitial($part)) {
                $parts[$k] = new Initial($part);
            }
        }

        return $parts;
    }

    protected function isInitial(string $part): bool
    {
        $length = mb_strlen($part, 'UTF-8');

        if ($length === 1) {
            return true;
        }

        return $length === 2 && str_ends_with($part, '.');
    }

    /**
     * true when every cased token is uppercase and none carries a lowercase
     * letter, i.e. the input casing gives no signal (all-caps registry data).
     *
     * @param  PartArray  $parts
     */
    private function isUniformUpperContext(array $parts): bool
    {
        $hasUpper = false;

        foreach ($parts as $part) {
            $value = $part instanceof AbstractPart ? $part->getValue() : $part;
            $letters = preg_replace('/[^\p{L}]/u', '', $value) ?? '';

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
}
