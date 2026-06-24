<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\LastnamePrefix;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\Suffix;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class LastnameMapper extends AbstractMapper
{
    /**
     * @param  array<string, string>  $prefixes
     */
    public function __construct(
        protected array $prefixes,
        protected bool $matchSinglePart = false,
    ) {}

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        if (! $this->matchSinglePart && count($parts) < 2) {
            return $parts;
        }

        return $this->mapParts($parts);
    }

    /**
     * we map the parts in reverse order because it makes more
     * sense to parse for the lastname starting from the end
     *
     * @param  PartArray  $parts
     * @return PartArray
     */
    protected function mapParts(array $parts): array
    {
        $k = $this->skipIgnoredParts($parts) + 1;
        $remapIgnored = true;

        while (--$k >= 0) {
            $part = $parts[$k];

            if ($part instanceof AbstractPart) {
                break;
            }

            if ($this->isFollowedByLastnamePart($parts, $k)) {
                if ($mapped = $this->mapAsPrefixIfPossible($parts, $k)) {
                    $parts[$k] = $mapped;

                    continue;
                }

                if ($this->shouldStopMapping($parts, $k)) {
                    break;
                }
            }

            $parts[$k] = new Lastname($part);
            $remapIgnored = false;
        }

        if ($remapIgnored) {
            $parts = $this->remapIgnored($parts);
        }

        return $parts;
    }

    /**
     * try to map this part as a lastname prefix or as a combined
     * lastname part containing a prefix
     *
     * @param  PartArray  $parts
     */
    private function mapAsPrefixIfPossible(array $parts, int $k): ?Lastname
    {
        $part = $parts[$k];

        if (! is_string($part)) {
            return null;
        }

        if ($this->isApplicablePrefix($parts, $k)) {
            return new LastnamePrefix($part, $this->prefixes[$this->getKey($part)]);
        }

        if ($this->isCombinedWithPrefix($part)) {
            return new Lastname($part);
        }

        return null;
    }

    /**
     * check if the given part is a combined lastname part
     * that ends in a lastname prefix
     */
    private function isCombinedWithPrefix(string $part): bool
    {
        $pos = strpos($part, '-');

        if ($pos === false) {
            return false;
        }

        return $this->isPrefix(substr($part, $pos + 1));
    }

    /**
     * skip through the parts we want to ignore and return the start index
     *
     * @param  PartArray  $parts
     */
    protected function skipIgnoredParts(array $parts): int
    {
        $k = count($parts);

        while (--$k >= 0) {
            if (! $this->isIgnoredPart($parts[$k])) {
                break;
            }
        }

        return $k;
    }

    /**
     * indicates if we should stop mapping at the given index $k
     *
     * the assumption is that lastname parts have already been found
     * but we want to see if we should add more parts
     *
     * @param  PartArray  $parts
     */
    protected function shouldStopMapping(array $parts, int $k): bool
    {
        if ($k < 1) {
            return true;
        }

        $lastPart = $parts[$k + 1];

        if ($lastPart instanceof LastnamePrefix) {
            return true;
        }

        return $lastPart instanceof AbstractPart && strlen($lastPart->getValue()) >= 3;
    }

    /**
     * indicates if the given part should be ignored (skipped) during mapping
     */
    protected function isIgnoredPart(AbstractPart|string $part): bool
    {
        return $part instanceof Suffix || $part instanceof Nickname || $part instanceof Salutation;
    }

    /**
     * remap ignored parts as lastname
     *
     * if the mapping did not derive any lastname this is called to transform
     * any previously ignored parts into lastname parts
     *
     * @param  PartArray  $parts
     * @return PartArray
     */
    protected function remapIgnored(array $parts): array
    {
        $k = count($parts);

        while (--$k >= 0) {
            $part = $parts[$k];

            if (! $this->isIgnoredPart($part)) {
                break;
            }

            $parts[$k] = new Lastname($part);
        }

        return $parts;
    }

    /**
     * @param  PartArray  $parts
     */
    protected function isFollowedByLastnamePart(array $parts, int $index): bool
    {
        $next = $this->skipNicknameParts($parts, $index + 1);

        return isset($parts[$next]) && $parts[$next] instanceof Lastname;
    }

    /**
     * Assuming that the part at the given index is matched as a prefix,
     * determines if the prefix should be applied to the lastname.
     *
     * We only apply it to the lastname if we already have at least one
     * lastname part and there are other parts left in
     * the name (this effectively prioritises firstname over prefix matching).
     *
     * This expects the parts array and index to be in the original order.
     *
     * @param  PartArray  $parts
     */
    protected function isApplicablePrefix(array $parts, int $index): bool
    {
        $part = $parts[$index];

        if (! is_string($part) || ! $this->isPrefix($part)) {
            return false;
        }

        // in a surname-only segment (the part before the comma in "Last, First")
        // there is no firstname to prioritise, so a leading prefix with nothing
        // before it still belongs to the lastname rather than becoming a firstname
        if ($this->matchSinglePart) {
            return true;
        }

        return $this->hasUnmappedPartsBefore($parts, $index);
    }

    /**
     * check if the given word is a lastname prefix
     */
    protected function isPrefix(string $word): bool
    {
        return array_key_exists($this->getKey($word), $this->prefixes);
    }

    /**
     * find the next non-nickname index in parts
     *
     * @param  PartArray  $parts
     */
    protected function skipNicknameParts(array $parts, int $startIndex): int
    {
        $total = count($parts);

        for ($i = $startIndex; $i < $total; $i++) {
            if (! ($parts[$i] instanceof Nickname)) {
                return $i;
            }
        }

        return $total - 1;
    }
}
