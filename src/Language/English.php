<?php

namespace Iliaal\NameParser\Language;

use Iliaal\NameParser\LanguageInterface;

class English implements LanguageInterface
{
    public const SUFFIXES = [
        '1st' => '1st',
        '2nd' => '2nd',
        '3rd' => '3rd',
        '4th' => '4th',
        '5th' => '5th',
        '6th' => '6th',
        '7th' => '7th',
        '8th' => '8th',
        '9th' => '9th',
        '10th' => '10th',
        'i' => 'I',
        'ii' => 'II',
        'iii' => 'III',
        'iv' => 'IV',
        'v' => 'V',
        'vi' => 'VI',
        'vii' => 'VII',
        'viii' => 'VIII',
        'ix' => 'IX',
        'x' => 'X',
        'apr' => 'APR',
        'cme' => 'CME',
        'dc' => 'DC',
        'dds' => 'DDS',
        'dmd' => 'DMD',
        'do' => 'DO',
        'dsw' => 'DSW',
        'dvm' => 'DVM',
        'emba' => 'EMBA',
        'esq' => 'Esq',
        'esquire' => 'Esquire',
        'jr' => 'Jr',
        'junior' => 'Junior',
        'lcsw' => 'LCSW',
        'ma' => 'MA',
        'mba' => 'MBA',
        'md' => 'MD',
        'ms' => 'MS',
        'msw' => 'MSW',
        'pe' => 'PE',
        'phd' => 'PhD',
        'psyd' => 'PsyD',
        'rph' => 'RPh',
        'senior' => 'Senior',
        'sr' => 'Sr',
    ];

    public const SALUTATIONS = [
        'dr' => 'Dr.',
        'fr' => 'Fr.',
        'hon' => 'Hon.',
        'honorable' => 'Hon.',
        'the honorable' => 'Hon.',
        'madam' => 'Madam',
        'master' => 'Mr.',
        'miss' => 'Miss',
        'missus' => 'Mrs.',
        'mister' => 'Mr.',
        'mr' => 'Mr.',
        'mrs' => 'Mrs.',
        'ms' => 'Ms.',
        'mx' => 'Mx.',
        'rev' => 'Rev.',
        'sir' => 'Sir',
        'prof' => 'Prof.',
        'his honour' => 'His Honour',
        'her honour' => 'Her Honour',
    ];

    public const LASTNAME_PREFIXES = [
        'da' => 'da',
        'de' => 'de',
        'del' => 'del',
        'della' => 'della',
        'der' => 'der',
        'di' => 'di',
        'du' => 'du',
        'la' => 'la',
        'pietro' => 'pietro',
        'st' => 'st.',
        'ter' => 'ter',
        'van' => 'van',
        'vanden' => 'vanden',
        'vere' => 'vere',
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
