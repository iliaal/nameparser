<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Middlename;
use Iliaal\NameParser\Part\MiddlenamePrefix;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class MiddlenameMapper extends AbstractMapper
{
    /**
     * @param  array<int|string, string>  $prefixes
     */
    public function __construct(
        protected bool $mapWithoutLastname = false,
        protected array $prefixes = [],
    ) {}

    public function mapsWithoutLastname(): bool
    {
        return $this->mapWithoutLastname;
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $parts = $this->normalizeParts($parts);

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
        $length = count($parts) - ($this->mapWithoutLastname ? 0 : 1);

        for ($k = $start; $k < $length; $k++) {
            $part = $parts[$k];

            if ($part instanceof Lastname) {
                break;
            }

            if ($part instanceof AbstractPart) {
                continue;
            }

            $parts[$k] = $this->makeMiddlename($part);
        }

        return $parts;
    }

    /**
     * Compound given-name particles keep the dictionary casing used for surnames.
     */
    private function makeMiddlename(string $part): Middlename
    {
        $key = $this->getKey($part);

        if (array_key_exists($key, $this->prefixes)) {
            return new MiddlenamePrefix($part, $this->prefixes[$key]);
        }

        return new Middlename($part);
    }
}
