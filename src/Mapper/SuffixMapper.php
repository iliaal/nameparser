<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Suffix;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class SuffixMapper extends AbstractMapper
{
    /**
     * Suffix keys that also occur as real given names / surnames (Vietnamese
     * "Do"/"Vi", Chinese "Ma", roman numerals, short allied-health creds).
     * These get casing + position disambiguation; everything else keeps the
     * original always-strip behavior.
     */
    public const array AMBIGUOUS_KEYS = [
        'do' => true, 'vi' => true, 'vii' => true, 'viii' => true,
        'ix' => true, 'x' => true, 'ma' => true, 'ms' => true,
        'pe' => true, 'dc' => true, 'pa' => true,
        // multi-char roman numerals + creds that are also real US surnames
        // (Census: Ii, Iv, Mba); casing still strips the genuine credential.
        'ii' => true, 'iii' => true, 'iv' => true, 'mba' => true,
        // short allied-health creds that are also real names ("Ba", "Lac",
        // initials "Rn"/"Pt"); casing still strips the uppercase credential.
        'ba' => true, 'bs' => true, 'lac' => true, 'np' => true,
        'od' => true, 'pt' => true, 'rd' => true, 'rn' => true,
    ];

    /**
     * The subset of AMBIGUOUS_KEYS that lean toward being a real name rather
     * than a credential. Used by Confidence to decide whether an uppercase
     * token in uniform-case input is genuinely undecidable: an uppercase "DO"
     * could be the surname Do, but an uppercase "RN" is almost always a cred.
     */
    public const array NAME_LEANING_KEYS = [
        'do' => true, 'vi' => true, 'ma' => true, 'ba' => true, 'lac' => true,
    ];

    /**
     * @param  array<string, string>  $suffixes
     */
    public function __construct(
        protected array $suffixes,
        protected bool $matchSinglePart = false,
        protected int $reservedParts = 2,
    ) {}

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    public function map(array $parts): array
    {
        if ($this->isMatchingSinglePart($parts)) {
            $first = $parts[0];
            if (is_string($first)) {
                $parts[0] = new Suffix($first, $this->suffixes[$this->getKey($first)]);
            }

            return $parts;
        }

        $start = count($parts) - 1;

        for ($k = $start; $k > $this->reservedParts - 1; $k--) {
            $part = $parts[$k];

            if (! $this->isSuffix($part)) {
                break;
            }

            $parts[$k] = new Suffix($part, $this->suffixes[$this->getKey($part)]);
        }

        return $parts;
    }

    /**
     * @param  PartArray  $parts
     */
    protected function isMatchingSinglePart(array $parts): bool
    {
        if (! $this->matchSinglePart) {
            return false;
        }

        if (count($parts) !== 1 || ! is_string($parts[0])) {
            return false;
        }

        // terminal-token guard: a lone token that collides with a name is kept
        // as a name unless its casing reads as a credential (all-caps "DO"),
        // so "Smith, Do" keeps the given name but "Brown, DO" strips the cred.
        if ($this->isAmbiguous($parts[0]) && ! $this->isUpperCase($parts[0])) {
            return false;
        }

        return $this->isSuffix($parts[0]);
    }

    /**
     * @phpstan-assert-if-true string $part
     */
    protected function isSuffix(AbstractPart|string $part): bool
    {
        if ($part instanceof AbstractPart) {
            return false;
        }

        if (! array_key_exists($this->getKey($part), $this->suffixes)) {
            return false;
        }

        if ($this->isAmbiguous($part)) {
            // casing as signal: ALL-CAPS reads as a credential ("DO", "VI"),
            // Title/lower case reads as a name token ("Do", "Vi").
            return $this->isUpperCase($part);
        }

        return true;
    }

    protected function isAmbiguous(string $part): bool
    {
        return isset(self::AMBIGUOUS_KEYS[$this->getKey($part)]);
    }

    protected function isUpperCase(string $part): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $part) ?? '';

        if ($letters === '') {
            return false;
        }

        return $letters === mb_strtoupper($letters, 'UTF-8')
            && $letters !== mb_strtolower($letters, 'UTF-8');
    }
}
