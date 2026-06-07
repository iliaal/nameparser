<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Initial;

/**
 * single letter, possibly followed by a period
 */
/**
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

        for ($k = 0; $k < count($parts); $k++) {
            $part = $parts[$k];

            if ($part instanceof AbstractPart) {
                continue;
            }

            if (! $this->matchLastPart && $k === $last) {
                continue;
            }

            if (mb_strtoupper($part, 'UTF-8') === $part) {
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
}
