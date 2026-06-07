<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Salutation;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class FirstnameMapper extends AbstractMapper
{
    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        if (count($parts) < 2) {
            return [$this->handleSinglePart($parts[0])];
        }

        $pos = $this->findFirstnamePosition($parts);

        if ($pos !== null) {
            $parts[$pos] = new Firstname($parts[$pos]);
        }

        return $parts;
    }

    protected function handleSinglePart(string|AbstractPart $part): AbstractPart
    {
        if ($part instanceof AbstractPart) {
            return $part;
        }

        return new Firstname($part);
    }

    /**
     * @param  PartArray  $parts
     */
    protected function findFirstnamePosition(array $parts): ?int
    {
        $pos = null;

        $length = count($parts);
        $start = $this->getStartIndex($parts);

        for ($k = $start; $k < $length; $k++) {
            $part = $parts[$k];

            if ($part instanceof Lastname) {
                break;
            }

            if ($part instanceof Initial && $pos === null) {
                $pos = $k;
            }

            if ($part instanceof AbstractPart) {
                continue;
            }

            return $k;
        }

        return $pos;
    }

    /**
     * @param  PartArray  $parts
     */
    protected function getStartIndex(array $parts): int
    {
        $index = $this->findFirstMapped(Salutation::class, $parts);

        if ($index === false) {
            return 0;
        }

        if ($index === count($parts) - 1) {
            return 0;
        }

        return $index + 1;
    }
}
