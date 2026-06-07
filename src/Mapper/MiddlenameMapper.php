<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Middlename;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class MiddlenameMapper extends AbstractMapper
{
    public function __construct(
        protected bool $mapWithoutLastname = false,
    ) {}

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        // If we don't expect a lastname, match a mimimum of 2 parts
        $minumumParts = ($this->mapWithoutLastname ? 2 : 3);

        if (count($parts) < $minumumParts) {
            return $parts;
        }

        $start = $this->findFirstMapped(Firstname::class, $parts);

        if ($start === false) {
            return $parts;
        }

        return $this->mapFrom($start, $parts);
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    protected function mapFrom(int $start, array $parts): array
    {
        // If we don't expect a lastname, include the last part,
        // otherwise skip the last (-1) because it should be a lastname
        $length = count($parts) - ($this->mapWithoutLastname ? 0 : 1);

        for ($k = $start; $k < $length; $k++) {
            $part = $parts[$k];

            if ($part instanceof Lastname) {
                break;
            }

            if ($part instanceof AbstractPart) {
                continue;
            }

            $parts[$k] = new Middlename($part);
        }

        return $parts;
    }
}
