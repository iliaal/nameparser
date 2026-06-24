<?php

namespace Iliaal\NameParser\Language;

use Iliaal\NameParser\LanguageInterface;

class English implements LanguageInterface
{
    public const array SUFFIXES = [
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
        // Nursing / allied-health credentials, by descending frequency in the
        // public NPPES/NPI registry. Without these, a trailing credential like
        // "Jane Doe, RN" leaks into the parsed first name.
        'aprn' => 'APRN',
        'arnp' => 'ARNP',
        'atc' => 'ATC',
        'ba' => 'BA',
        'bcba' => 'BCBA',
        'bs' => 'BS',
        'ccc-slp' => 'CCC-SLP',
        'crna' => 'CRNA',
        'crnp' => 'CRNP',
        'dpm' => 'DPM',
        'dpt' => 'DPT',
        'fnp' => 'FNP',
        'fnp-bc' => 'FNP-BC',
        'fnp-c' => 'FNP-C',
        'lac' => 'LAc',
        'licsw' => 'LICSW',
        'lmft' => 'LMFT',
        'lmhc' => 'LMHC',
        'lmsw' => 'LMSW',
        'lmt' => 'LMT',
        'lpc' => 'LPC',
        'lpn' => 'LPN',
        'lsw' => 'LSW',
        'msn' => 'MSN',
        'ncc' => 'NCC',
        'np' => 'NP',
        'od' => 'OD',
        'otr' => 'OTR',
        'otr/l' => 'OTR/L',
        'pa' => 'PA',
        'pa-c' => 'PA-C',
        'pharmd' => 'PharmD',
        'pt' => 'PT',
        'pta' => 'PTA',
        'rbt' => 'RBT',
        'rd' => 'RD',
        'rn' => 'RN',
        'slp' => 'SLP',
    ];

    public const array SALUTATIONS = [
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

    public const array LASTNAME_PREFIXES = [
        'da' => 'da',
        'de' => 'de',
        'del' => 'del',
        'della' => 'della',
        'den' => 'den',
        'der' => 'der',
        'des' => 'des',
        'di' => 'di',
        'du' => 'du',
        'la' => 'la',
        'las' => 'las',
        'le' => 'le',
        'los' => 'los',
        'pietro' => 'pietro',
        'st' => 'st.',
        'ten' => 'ten',
        'ter' => 'ter',
        'van' => 'van',
        'vanden' => 'vanden',
        'vere' => 'vere',
        'vom' => 'vom',
        'von' => 'von',
        'zu' => 'zu',
        'zum' => 'zum',
        'zur' => 'zur',
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
