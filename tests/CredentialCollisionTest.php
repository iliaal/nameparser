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
            'space DO'                            => ['Jane Doe DO', 'Jane', 'Doe', 'DO'],
            'PsyD'                                => ['Alice Green PsyD', 'Alice', 'Green', 'PsyD'],
            'comma LCSW'                          => ['Alice Green, LCSW', 'Alice', 'Green', 'LCSW'],
            'canonical LAc'                       => ['John Testsurname LAc', 'John', 'Testsurname', 'LAc'],
            'canonical punctuated LAc'            => ['John Testsurname L.Ac.', 'John', 'Testsurname', 'LAc'],
            'comma canonical LAc'                 => ['John Testsurname, LAc', 'John', 'Testsurname', 'LAc'],
            'MSW'                                 => ['Tom White MSW', 'Tom', 'White', 'MSW'],
            'MBA'                                 => ['Greg Adams MBA', 'Greg', 'Adams', 'MBA'],
            'Esq'                                 => ['Paul Stone Esq', 'Paul', 'Stone', 'Esq'],
            'middle name + credential'            => ['John Paul Smith DDS', 'John', 'Smith', 'DDS'],
            'roman numeral VIII'                  => ['John Smith VIII', 'John', 'Smith', 'VIII'],
            'roman numeral IX'                    => ['Henry Ford IX', 'Henry', 'Ford', 'IX'],
            'salutation Hon.'                     => ['Hon. Patricia Reed', 'Patricia', 'Reed', ''],
            'comma MD'                            => ['John Smith, MD', 'John', 'Smith', 'MD'],
            'first initial + MD without lastname' => ['John A. MD', 'John', '', 'MD'],
            'first initial + RN without lastname' => ['Mary J. RN', 'Mary', '', 'RN'],
            'first initial + PhD without lastname' => ['John A PhD', 'John', '', 'PhD'],
            'first + credential without lastname' => ['Jane DDS', 'Jane', '', 'DDS'],
            'first + Jr without lastname'         => ['John Jr', 'John', '', 'Jr'],
            'first + roman without lastname'      => ['John III', 'John', '', 'III'],

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

            // a bare single-letter roman numeral after the first name is a
            // surname/initial, not a suffix ("Malcolm X")
            'single-letter X stays lastname'      => ['Malcolm X', 'Malcolm', 'X', ''],
            'single-letter V stays lastname'      => ['John V', 'John', 'V', ''],
            'single-letter I stays lastname'      => ['Mary I', 'Mary', 'I', ''],

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
            'all-caps two-letter given with PhD'  => ['JO ANDERSON PhD', 'Jo', 'Anderson', 'PhD'],
            'all-caps two-letter given with salutation' => ['Dr. JO ANDERSON', 'Jo', 'Anderson', ''],
            'all-caps DO strips as suffix'        => ['ANH TRAN DO', 'Anh', 'Tran', 'DO'],

            // legal credential
            'comma JD'                            => ['King, Michelle JD', 'Michelle', 'King', 'JD'],
            'comma JD and LPC'                    => ['King, Michelle JD, LPC', 'Michelle', 'King', 'JD LPC'],
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

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function parentheticalCredentialProvider(): array
    {
        return [
            'space form' => ['Jane Doe (MD)', 'Jane', 'Doe', 'MD'],
            'comma form' => ['Smith, John (MD)', 'John', 'Smith', 'MD'],
        ];
    }

    #[DataProvider('parentheticalCredentialProvider')]
    public function testParentheticalCredentialsAreSuffixes(string $input, string $first, string $last, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
        $this->assertSame('', $name->getNickname(), "nickname for '$input'");
    }

    public function testInterruptedCredentialTailDoesNotLeakCredentialsIntoNameFields(): void
    {
        $name = (new Parser())->parse('Jane Doe MD Unknown PhD');

        $this->assertSame('Jane', $name->getFirstname());
        $this->assertSame('', $name->getInitials());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('Doe', $name->getLastname());
        $this->assertSame('MD PhD', $name->getSuffix());
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function interruptedCredentialTailProvider(): array
    {
        return [
            'placeholder between credentials is stripped' => ['Jane Doe MD Unknown PhD', 'Jane', '', 'Doe', 'MD PhD'],
            'name between credentials is preserved'      => ['Jane Doe MD Robert PhD', 'Jane', 'Doe', 'Robert', 'MD PhD'],
            'surname between credentials is preserved'   => ['Jane MD Doe PhD', 'Jane', '', 'Doe', 'MD PhD'],
            'comma given between credentials is preserved' => ['Smith, MD John PhD', 'John', '', 'Smith', 'MD PhD'],
            'roman suffix before a name is preserved'    => ['John Smith III Robert PhD', 'John', 'Smith', 'Robert', 'III PhD'],
            'placeholder after credentials is stripped'  => ['Jane Doe MD PhD Unknown', 'Jane', '', 'Doe', 'MD PhD'],
            'punctuation between credentials is stripped' => ['Jane Doe MD - PhD', 'Jane', '', 'Doe', 'MD PhD'],
            'placeholder before credentials is stripped'  => ['Jane Doe Unknown MD', 'Jane', '', 'Doe', 'MD'],
            'punctuation before credentials is stripped'  => ['Jane Doe - MD', 'Jane', '', 'Doe', 'MD'],
            'comma placeholder before credentials is stripped' => ['Smith, Jane Unknown MD', 'Jane', '', 'Smith', 'MD'],
        ];
    }

    #[DataProvider('interruptedCredentialTailProvider')]
    public function testInterruptedCredentialTailKeepsNameTokensAndDropsNoise(
        string $input,
        string $first,
        string $middle,
        string $last,
        string $suffix,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    /**
     * An unknown all-caps credential ("FACS", "CCRN") next to a real dictionary
     * credential no longer breaks the tail scan: the whole run is stripped to
     * the suffix, leaving no phantom initials or middle name. The casing is the
     * signal, so this only fires when the input is not uniform-uppercase.
     *
     * @return array<string, array{string, string, string, string, string, string}>
     */
    public static function unknownCredentialProvider(): array
    {
        return [
            // input, first, middle, last, initials, suffix
            'space unknown after known'        => ['John Smith MD FACS', 'John', '', 'Smith', '', 'MD FACS'],
            'space nursing credential run'     => ['Jane Doe RN BSN CCRN', 'Jane', '', 'Doe', '', 'RN BSN CCRN'],
            'comma unknown after known'        => ['Garcia, Maria, MD, FACS', 'Maria', '', 'Garcia', '', 'MD FACS'],
            'comma credential-only unknown run' => ['John Smith, MD, FACS', 'John', '', 'Smith', '', 'MD FACS'],
            'comma credential-only space run' => ['John Smith, MD FACS', 'John', '', 'Smith', '', 'MD FACS'],
            // the phantom-initials bug: a credential-only segment before the
            // given name no longer leaks into the initials field
            'comma credential before given'    => ['Smith, MD, John', 'John', '', 'Smith', '', 'MD'],
            'comma ambiguous credential keeps middle' => ['Smith, John, DO, Robert', 'John', 'Robert', 'Smith', '', 'DO'],
            'leading credential run in given'  => ['Smith, MD John', 'John', '', 'Smith', '', 'MD'],
            'leading credential does not anchor remote uppercase name' => [
                'Smith, MD John PAUL',
                'John',
                'Paul',
                'Smith',
                '',
                'MD',
            ],
            'leading credential does not anchor multiple uppercase names' => [
                'Smith, MD John PAUL GEORGE',
                'John',
                'Paul George',
                'Smith',
                '',
                'MD',
            ],
            'trailing credential anchors its contiguous unknown run' => [
                'Smith, MD John PAUL RN',
                'John',
                '',
                'Smith',
                '',
                'MD PAUL RN',
            ],
            'leading credential anchors only its contiguous unknown run' => [
                'Smith, MD FACS John PAUL',
                'John',
                'Paul',
                'Smith',
                '',
                'MD FACS',
            ],
            'leading credential run may alternate known and unknown tokens' => [
                'Smith, MD FACS RN John',
                'John',
                '',
                'Smith',
                '',
                'MD FACS RN',
            ],
            // combined initials behind a preserved name token are not a
            // stray credential; only the contiguous tail run qualifies
            'all-caps initials behind a name token stay initials' => ['John Paul JM Smith MD', 'John', 'Paul', 'Smith', 'J M', 'MD'],
        ];
    }

    #[DataProvider('unknownCredentialProvider')]
    public function testUnknownCredentialsAreStrippedWithoutLeakingIntoNameFields(
        string $input,
        string $first,
        string $middle,
        string $last,
        string $initials,
        string $suffix,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($initials, $name->getInitials(), "initials for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    /**
     * Boundary cases where the unknown-credential heuristic deliberately does
     * NOT fire, locked to their current output so the conservative behavior is
     * intentional, not accidental:
     *  - uniform-uppercase input carries no casing signal, so an unknown token
     *    breaks the scan exactly as before;
     *  - an unknown credential with no dictionary credential to anchor it is
     *    left untouched (it becomes a surname), a documented limitation.
     *
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function unknownCredentialSuppressedProvider(): array
    {
        return [
            // input, first, middle, last, suffix
            'uniform caps breaks the scan'   => ['JANE DOE RN BSN CCRN', 'Jane', 'Doe Rn Bsn', 'Ccrn', ''],
            'no dictionary anchor to ride on' => ['Jane Doe FACS', 'Jane', 'Doe', 'Facs', ''],
            'trailing plain name not stripped' => ['Jane Doe Robert', 'Jane', 'Doe', 'Robert', ''],
        ];
    }

    #[DataProvider('unknownCredentialSuppressedProvider')]
    public function testUnknownCredentialHeuristicStaysConservative(
        string $input,
        string $first,
        string $middle,
        string $last,
        string $suffix,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    /**
     * an all-caps "MS" next to another credential reads as a credential, not the
     * "Ms." salutation
     */
    public function testAllCapsAmbiguousCredentialIsNotSalutation(): void
    {
        $name = (new Parser())->parse('Doe, MS RN');

        $this->assertSame('', $name->getSalutation(), 'no salutation');
        $this->assertSame('MS RN', $name->getSuffix());
        $this->assertSame('Doe', $name->getLastname());
    }

    /**
     * title-case "Ms" before a given name is still the salutation
     */
    public function testTitleCaseSalutationBeforeGivenNamePreserved(): void
    {
        $name = (new Parser())->parse('Smith, Ms John');

        $this->assertSame('Ms.', $name->getSalutation());
        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function credentialBeforeNicknameProvider(): array
    {
        return [
            'space form' => ['Jane Doe MD (Jenny)'],
            'comma form' => ['Doe, Jane MD (Jenny)'],
        ];
    }

    #[DataProvider('credentialBeforeNicknameProvider')]
    public function testCredentialBeforeNicknameKeepsBothDecorations(string $input): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('Jane', $name->getFirstname());
        $this->assertSame('', $name->getInitials());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('Doe', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
        $this->assertSame('Jenny', $name->getNickname());
    }
}
