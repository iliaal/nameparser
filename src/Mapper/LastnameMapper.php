<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Ignored;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\LastnamePrefix;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\Suffix;
use Iliaal\NameParser\Text;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class LastnameMapper extends AbstractMapper
{
    /**
     * @param  array<int|string, string>  $prefixes
     */
    public function __construct(
        protected array $prefixes,
        protected bool $matchSinglePart = false,
        protected bool $surnameOnly = false,
    ) {}

    public function matchesSinglePart(): bool
    {
        return $this->matchSinglePart;
    }

    public function isSurnameOnly(): bool
    {
        return $this->surnameOnly;
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $parts = $this->normalizeParts($parts);

        if (! $this->matchSinglePart && count($parts) < 2) {
            return $parts;
        }

        return $this->mapParts($parts);
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    protected function mapParts(array $parts): array
    {
        $k = $this->skipIgnoredParts($parts);
        if (! $this->matchSinglePart && $k < 1) {
            return $parts;
        }

        $k++;
        $remapIgnored = true;

        while (--$k >= 0) {
            $part = $parts[$k];

            // Bind across nicknames inside compound surnames.
            if ($part instanceof Nickname) {
                continue;
            }

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

    private function isCombinedWithPrefix(string $part): bool
    {
        $pos = strpos($part, '-');

        if ($pos === false) {
            return false;
        }

        return $this->isPrefix(substr($part, $pos + 1));
    }

    /**
     * @param  PartArray  $parts
     */
    protected function skipIgnoredParts(array $parts): int
    {
        $k = count($parts);

        while (--$k >= 0) {
            $part = $parts[$k];

            if ($this->isIgnoredPart($part)) {
                continue;
            }

            // Skip trailing punctuation placeholders; ordinary ASCII names avoid the regex.
            if (is_string($part)
                && ($part === '' || ! ctype_alnum($part[0]))
                && preg_match('/[\p{L}\p{N}]/u', $part) !== 1) {
                continue;
            }

            break;
        }

        return $k;
    }

    /**
     * Check whether an already-started surname should absorb more parts.
     *
     * @param  PartArray  $parts
     */
    protected function shouldStopMapping(array $parts, int $k): bool
    {
        if ($this->surnameOnly) {
            return false;
        }

        if ($k < 1) {
            return true;
        }

        $lastPart = $parts[$this->skipNicknameParts($parts, $k + 1)];

        if ($lastPart instanceof LastnamePrefix) {
            return true;
        }

        if (! $lastPart instanceof AbstractPart) {
            return false;
        }

        $length = Text::graphemeLengthUpTo($lastPart->getValue(), 3);

        return $length === 1 || $length >= 3;
    }

    protected function isIgnoredPart(AbstractPart|string $part): bool
    {
        return $part instanceof Suffix
            || $part instanceof Nickname
            || $part instanceof Salutation
            || $part instanceof Ignored;
    }

    /**
     * If no surname was found, reconsider the skipped parts.
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

            // Titles, credentials, and connectors cannot become a missing surname.
            if ($part instanceof Suffix || $part instanceof Salutation || $part instanceof Ignored) {
                continue;
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
     * Prefer a firstname over a lone prefix unless surname-only context or an
     * adjacent prefix resolves the ambiguity. Indexes use the original order.
     *
     * @param  PartArray  $parts
     */
    protected function isApplicablePrefix(array $parts, int $index): bool
    {
        $part = $parts[$index];

        if (! is_string($part) || ! $this->isPrefix($part)) {
            return false;
        }

        // Adjacent particles establish a surname even without a firstname.
        if (($parts[$index + 1] ?? null) instanceof LastnamePrefix) {
            return true;
        }

        // The comma already identifies this segment as surname-only.
        if ($this->surnameOnly) {
            return true;
        }

        return $this->hasUnmappedPartsBefore($parts, $index);
    }

    protected function isPrefix(string $word): bool
    {
        return array_key_exists($this->getKey($word), $this->prefixes);
    }

    /**
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
