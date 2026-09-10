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
        $parts = $this->normalizeParts($parts);

        // Earlier mappers may remove every token.
        if ($parts === []) {
            return $parts;
        }

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
     * Skip leading honorifics, but preserve real name tokens before mid-stream
     * salutations from custom pipelines.
     *
     * @param  PartArray  $parts
     */
    protected function getStartIndex(array $parts): int
    {
        $firstSal = $this->findFirstMapped(Salutation::class, $parts);

        if ($firstSal === false) {
            return 0;
        }

        for ($i = 0; $i < $firstSal; $i++) {
            $part = $parts[$i];

            if ($part instanceof AbstractPart) {
                continue;
            }

            // only a leading article before an honorific is skipped ("The Rev.")
            if ($this->getKey($part) !== 'the') {
                return 0;
            }
        }

        if ($firstSal === count($parts) - 1) {
            return 0;
        }

        return $firstSal + 1;
    }
}
