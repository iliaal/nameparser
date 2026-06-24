<?php

namespace Iliaal\NameParser\Language;

use Iliaal\NameParser\LanguageInterface;

class German implements LanguageInterface
{
    public const array SUFFIXES = [
        '1.' => '1.',
        '2.' => '2.',
        '3.' => '3.',
        '4.' => '4.',
        '5.' => '5.',
        'i' => 'I',
        'ii' => 'II',
        'iii' => 'III',
        'iv' => 'IV',
        'v' => 'V',
    ];

    public const array SALUTATIONS = [
        'herr' => 'Herr',
        'hr' => 'Herr',
        'frau' => 'Frau',
        'fr' => 'Frau',
    ];

    public const array LASTNAME_PREFIXES = [
        'der' => 'der',
        'von' => 'von',
    ];

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function getSuffixes(): array
    {
        return self::SUFFIXES;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function getSalutations(): array
    {
        return self::SALUTATIONS;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function getLastnamePrefixes(): array
    {
        return self::LASTNAME_PREFIXES;
    }
}
