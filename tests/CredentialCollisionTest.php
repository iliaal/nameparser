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
