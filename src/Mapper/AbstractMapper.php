<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;

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
        return strtolower(str_replace('.', '', $word));
    }
}
