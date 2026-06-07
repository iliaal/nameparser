<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Salutation;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class SalutationMapper extends AbstractMapper
{
    /**
     * @param  array<string, string>  $salutations
     */
    public function __construct(
        protected array $salutations,
        protected int $maxIndex = 0,
    ) {}

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $max = ($this->maxIndex > 0) ? min($this->maxIndex, count($parts)) : intdiv(count($parts), 2);

        for ($k = 0; $k < $max; $k++) {
            if ($parts[$k] instanceof AbstractPart) {
                break;
            }

            $parts = $this->substituteWithSalutation($parts, $k);
        }

        return $parts;
    }

    /**
     * We pass the full parts array and the current position to allow
     * not only single-word matches but also combined matches with
     * subsequent words (parts).
     *
     * @param  PartArray  $parts
     * @return PartArray
     */
    protected function substituteWithSalutation(array $parts, int $start): array
    {
        $current = $parts[$start];

        if (is_string($current) && $this->isSalutation($current)) {
            $parts[$start] = new Salutation($current, $this->salutations[$this->getKey($current)]);

            return $parts;
        }

        foreach ($this->salutations as $key => $salutation) {
            $keys = explode(' ', $key);
            $length = count($keys);

            $subset = array_slice($parts, $start, $length);

            if ($this->isMatchingSubset($keys, $subset)) {
                array_splice($parts, $start, $length, [new Salutation(implode(' ', $subset), $salutation)]);

                return $parts;
            }
        }

        return $parts;
    }

    /**
     * check if the given subset matches the given keys entry by entry,
     * which means word by word, except that we first need to key-ify
     * the subset words
     *
     * @param  array<int, string>  $keys
     * @param  PartArray  $subset
     *
     * @phpstan-assert-if-true array<int, string> $subset
     */
    private function isMatchingSubset(array $keys, array $subset): bool
    {
        for ($i = 0; $i < count($subset); $i++) {
            $part = $subset[$i];
            if (! is_string($part) || $this->getKey($part) !== $keys[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * check if the given word is a viable salutation
     */
    protected function isSalutation(string $word): bool
    {
        return array_key_exists($this->getKey($word), $this->salutations);
    }
}
