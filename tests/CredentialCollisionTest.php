<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the fork's casing- and credential-aware behavior:
 *  - trailing professional credentials are stripped to the suffix, keeping the
 *    real surname (upstream lost it);
 *  - name tokens that collide with a credential (Vietnamese "Do"/"Vi", "Ma",
 *    roman numerals) are kept as names when their casing is not ALL-CAPS.
 */
class CredentialCollisionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, expected first, expected last, expected suffix
            'space credential keeps surname'      => ['Jane Doe DDS', 'Jane', 'Doe', 'DDS'],
            'comma credential keeps surname'      => ['Jane Doe, DDS', 'Jane', 'Doe', 'DDS'],
            'DVM'                                 => ['Robert Brown DVM', 'Robert', 'Brown', 'DVM'],
            'comma DO'                            => ['Robert Brown, DO', 'Robert', 'Brown', 'DO'],
            'PsyD'                                => ['Alice Green PsyD', 'Alice', 'Green', 'PsyD'],
            'comma LCSW'                          => ['Alice Green, LCSW', 'Alice', 'Green', 'LCSW'],
            'MSW'                                 => ['Tom White MSW', 'Tom', 'White', 'MSW'],
            'MBA'                                 => ['Greg Adams MBA', 'Greg', 'Adams', 'MBA'],
            'Esq'                                 => ['Paul Stone Esq', 'Paul', 'Stone', 'Esq'],
            'middle name + credential'            => ['John Paul Smith DDS', 'John', 'Smith', 'DDS'],
            'roman numeral VIII'                  => ['John Smith VIII', 'John', 'Smith', 'VIII'],
            'roman numeral IX'                    => ['Henry Ford IX', 'Henry', 'Ford', 'IX'],
            'salutation Hon.'                     => ['Hon. Patricia Reed', 'Patricia', 'Reed', ''],
            'comma MD'                            => ['John Smith, MD', 'John', 'Smith', 'MD'],

            // name/credential collisions — must stay names, no suffix
            'surname Do, two tokens'              => ['Anh Do', 'Anh', 'Do', ''],
            'surname Do, comma'                   => ['Do, Anh', 'Anh', 'Do', ''],
            'surname Do, three tokens'            => ['Anh Tran Do', 'Anh', 'Tran Do', ''],
            'given Do in comma segment'           => ['Smith, Do', 'Do', 'Smith', ''],
            'given Vi, two tokens'                => ['Vi Nguyen', 'Vi', 'Nguyen', ''],
            'given Vi in comma segment'           => ['Nguyen, Vi', 'Vi', 'Nguyen', ''],
            'given Vi, three tokens'              => ['An Tran Vi', 'An', 'Tran Vi', ''],
            'surname Ma, comma'                   => ['Ma, Wei', 'Wei', 'Ma', ''],
            'surname Ma, two tokens'              => ['Wei Ma', 'Wei', 'Ma', ''],

            // Census surnames colliding with roman-numeral / MBA suffixes
            'surname Ii in comma segment'         => ['Brown, Ii', 'Ii', 'Brown', ''],
            'surname Iv in comma segment'         => ['Brown, Iv', 'Iv', 'Brown', ''],
            'surname Mba, three tokens'           => ['John Adam Mba', 'John', 'Mba', ''],
            // uppercase roman numeral is still a credential, not a name
            'uppercase II is a suffix'            => ['John Smith II', 'John', 'Smith', 'II'],

            // nursing / allied-health credentials (NPI-derived)
            'comma RN'                            => ['Jane Doe, RN', 'Jane', 'Doe', 'RN'],
            'comma PharmD'                        => ['Donna Barrett, PHARMD', 'Donna', 'Barrett', 'PharmD'],
            'comma APRN'                          => ['Karen Hill, APRN', 'Karen', 'Hill', 'APRN'],
            'space PA-C'                          => ['Tom White PA-C', 'Tom', 'White', 'PA-C'],
            'comma FNP-C'                         => ['Robert Smith, FNP-C', 'Robert', 'Smith', 'FNP-C'],
            'comma OTR/L'                         => ['Amy Lee, OTR/L', 'Amy', 'Lee', 'OTR/L'],
            // surnames colliding with short creds stay names (casing-gated)
            'surname Ba in comma segment'         => ['Brown, Ba', 'Ba', 'Brown', ''],
            'surname Lac in comma segment'        => ['Brown, Lac', 'Lac', 'Brown', ''],
            'surname Ba, two tokens'              => ['Wei Ba', 'Wei', 'Ba', ''],
            // uppercase BA is the degree, not a name
            'uppercase BA is a suffix'            => ['Jane Doe, BA', 'Jane', 'Doe', 'BA'],

            // uniform-uppercase input: a two-letter given name must not be
            // shredded into initials ("JO" -> J O). Casing carries no signal, so
            // the token is kept as a name rather than split.
            'all-caps two-letter given'           => ['JO ANDERSON', 'Jo', 'Anderson', ''],
            'all-caps given Bo'                   => ['BO JACKSON', 'Bo', 'Jackson', ''],
            'all-caps given Vi stays a name'      => ['VI NGUYEN', 'Vi', 'Nguyen', ''],
            'all-caps comma two-letter given'     => ['NGUYEN, JO', 'Jo', 'Nguyen', ''],
        ];
    }

    #[DataProvider('provider')]
    public function testParse(string $input, string $first, string $last, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }
}
