<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SurnamePrefixTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function commaProvider(): array
    {
        return [
            // input, first, last
            'multi prefix surname'  => ['van der Berg, Johan', 'Johan', 'van der Berg'],
            'spanish prefix surname' => ['de la Cruz, Juan', 'Juan', 'de la Cruz'],
            'single prefix von'     => ['von Trapp, Maria', 'Maria', 'von Trapp'],
            'single prefix de'      => ['de Vries, Jan', 'Jan', 'de Vries'],
            'dutch den surname'     => ['den Hartog, Piet', 'Piet', 'den Hartog'],
            'plain surname'         => ['Smith, John', 'John', 'Smith'],
        ];
    }

    #[DataProvider('commaProvider')]
    public function testCommaSurnamePrefixStaysInLastname(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function mainProvider(): array
    {
        return [
            // input, first, last
            'van den particle'   => ['Sanne van den Heuvel', 'Sanne', 'van den Heuvel'],
            'ten particle'       => ['Corrie ten Boom', 'Corrie', 'ten Boom'],
            'de los particle'    => ['Juan de los Santos', 'Juan', 'de los Santos'],
            'existing van der'   => ['Johan van der Berg', 'Johan', 'van der Berg'],
            'existing van'       => ['Vincent van Gogh', 'Vincent', 'van Gogh'],
        ];
    }

    #[DataProvider('mainProvider')]
    public function testMainPipelinePrefixesBindToLastname(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    public function testCompoundGivenNameDoesNotPullParticlesIntoLastname(): void
    {
        $name = (new Parser())->parse('Maria de los Angeles Ramirez');

        $this->assertSame('Maria', $name->getFirstname());
        $this->assertSame('Ramirez', $name->getLastname());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function middleParticleProvider(): array
    {
        return [
            // input, expected middle name
            'spanish del'   => ['Maria del Carmen Fernandez', 'del Carmen'],
            'spanish de los' => ['Maria de los Angeles Ramirez', 'de los Angeles'],
        ];
    }

    #[DataProvider('middleParticleProvider')]
    public function testMiddleNameParticleIsLowercased(string $input, string $middle): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
    }

    /**
     * Adjacent particles resolve surname ambiguity without a firstname.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function noFirstnameProvider(): array
    {
        return [
            // input, expected first, expected last
            'bare von der'      => ['von der Heide', '', 'von der Heide'],
            'bare de la'        => ['de la Cruz', '', 'de la Cruz'],
            'salutation von der' => ['Mr. von der Heide', '', 'von der Heide'],
            'salutation de la'  => ['Dr. de la Cruz', '', 'de la Cruz'],
            'salutation van der' => ['Mrs. van der Berg', '', 'van der Berg'],
        ];
    }

    #[DataProvider('noFirstnameProvider')]
    public function testNoFirstnameMultiParticleSurnameStaysWhole(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    /**
     * A lone particle is indistinguishable from a given name such as Della.
     */
    public function testSinglePrefixWordAfterSalutationStaysFirstname(): void
    {
        $name = (new Parser())->parse('Mr. Della Smith');

        $this->assertSame('Della', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function germanFrenchProvider(): array
    {
        return [
            // input, expected first, expected last
            'german vom'  => ['Klaus vom Bruch', 'Klaus', 'vom Bruch'],
            'german zur'  => ['Ursula zur Muhlen', 'Ursula', 'zur Muhlen'],
            'german zum'  => ['Karl zum Stein', 'Karl', 'zum Stein'],
            'german zu'   => ['Otto zu Guttenberg', 'Otto', 'zu Guttenberg'],
            'french le'   => ['Olivier le Brun', 'Olivier', 'le Brun'],
            'french des'  => ['Jean des Pres', 'Jean', 'des Pres'],
        ];
    }

    #[DataProvider('germanFrenchProvider')]
    public function testGermanAndFrenchParticlesUnderDefaultParser(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function lusoFilipinoItalianProvider(): array
    {
        return [
            // input, expected first, expected last
            'portuguese dos' => ['Joao dos Santos', 'Joao', 'dos Santos'],
            'portuguese do'  => ['Ana do Carmo', 'Ana', 'do Carmo'],
            'portuguese das' => ['Pedro das Neves', 'Pedro', 'das Neves'],
            'filipino dela'  => ['Maria dela Cruz', 'Maria', 'dela Cruz'],
            'filipino delos' => ['Jose delos Reyes', 'Jose', 'delos Reyes'],
            'filipino delas' => ['Ramon delas Alas', 'Ramon', 'delas Alas'],
            'italian lo'     => ['Giovanni lo Russo', 'Giovanni', 'lo Russo'],
        ];
    }

    #[DataProvider('lusoFilipinoItalianProvider')]
    public function testLusoFilipinoItalianParticlesBindToLastname(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function standaloneSurnameProvider(): array
    {
        return [
            // input, expected first, expected last, expected suffix
            'vietnamese do'    => ['Jane Do', 'Jane', 'Do', ''],
            'chinese lo'       => ['David Lo', 'David', 'Lo', ''],
            'comma form lo'    => ['Lo, David', 'David', 'Lo', ''],
            'do credential'    => ['Jane Doe, DO', 'Jane', 'Doe', 'DO'],
        ];
    }

    #[DataProvider('standaloneSurnameProvider')]
    public function testStandaloneSurnamesAreNotConsumedAsPrefix(string $input, string $first, string $last, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    /**
     * Ó is one grapheme but must reach LastnameMapper instead of becoming an initial.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function irishProvider(): array
    {
        return [
            // input, expected first, expected last
            'irish o'          => ['Éamon Ó Cuív', 'Éamon', 'Ó Cuív'],
            'irish o again'    => ['Seán Ó Riada', 'Seán', 'Ó Riada'],
            'irish ni'         => ['Mary Ní Mhaoileoin', 'Mary', 'Ní Mhaoileoin'],
            'irish nic'        => ['Mary Nic Aodha', 'Mary', 'Nic Aodha'],
            'irish ui'         => ['Bean Uí Bhriain', 'Bean', 'Uí Bhriain'],
            'irish ua'         => ['Brian Ua Ceallaigh', 'Brian', 'Ua Ceallaigh'],
            'irish mhic'       => ['Peig Mhic Gearailt', 'Peig', 'Mhic Gearailt'],
            'comma form'       => ['Ó Cuív, Éamon', 'Éamon', 'Ó Cuív'],
            'salutation led'   => ['Dr. Éamon Ó Cuív', 'Éamon', 'Ó Cuív'],
            'decomposed o'     => ["Éamon O\u{0301} Cuív", 'Éamon', 'Ó Cuív'],
            'decomposed ni'    => ["Mary Ni\u{0301} Mhaoileoin", 'Mary', 'Ní Mhaoileoin'],
            'decomposed ui'    => ["Bean Ui\u{0301} Bhriain", 'Bean', 'Uí Bhriain'],

            'uniform uppercase' => ['ÉAMON Ó CUÍV', 'Éamon', 'Ó Cuív'],

            'apostrophe form'  => ["Eamon O'Cuiv", 'Eamon', "O'Cuiv"],
        ];
    }

    #[DataProvider('irishProvider')]
    public function testIrishParticlesBindToLastname(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    /**
     * ASCII O has no fada to distinguish it from an initial.
     */
    public function testAnglicisedOStaysAnInitial(): void
    {
        $name = (new Parser())->parse('Eamon O Cuiv');

        $this->assertSame('Eamon', $name->getFirstname());
        $this->assertSame('O', $name->getInitials());
        $this->assertSame('Cuiv', $name->getLastname());
    }

    /**
     * Two-letter particles reach the combined-initial heuristic; longer ones do not.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function uppercaseParticleProvider(): array
    {
        return [
            // input, expected first, expected last
            'caps le'   => ['Mary LE Blanc', 'Mary', 'le Blanc'],
            'caps de'   => ['Jean DE Vries', 'Jean', 'de Vries'],
            'caps du'   => ['Mary DU Pont', 'Mary', 'du Pont'],
            'caps di'   => ['Marco DI Stefano', 'Marco', 'di Stefano'],
            'caps la'   => ['Pierre LA Roche', 'Pierre', 'la Roche'],

            'caps von'  => ['Hans VON Braun', 'Hans', 'von Braun'],
        ];
    }

    #[DataProvider('uppercaseParticleProvider')]
    public function testUppercaseParticleIsNotSplitIntoInitials(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame('', $name->getInitials(), "initials for '$input'");
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function surnameStemProvider(): array
    {
        return [
            // input, expected middle, expected last
            'Pietro is a given name' => ['John Pietro Smith', 'Pietro', 'Smith'],
            'Vere is a given name'   => ['John Vere Smith', 'Vere', 'Smith'],
            'Pietro surname after a real particle' => ['John di Pietro', '', 'di Pietro'],
            'Vere surname after a real particle'   => ['Edward de Vere', '', 'de Vere'],
        ];
    }

    #[DataProvider('surnameStemProvider')]
    public function testSurnameStemsAreNotRegisteredAsParticles(
        string $input,
        string $middle,
        string $last,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function initialProvider(): array
    {
        return [
            // input, expected first, expected initials, expected last
            'bare initial'     => ['John F Kennedy', 'John', 'F', 'Kennedy'],
            'dotted initial'   => ['John F. Kennedy', 'John', 'F.', 'Kennedy'],
            'combined initials' => ['JM Walker', 'J', 'M', 'Walker'],
            'two initials'     => ['J M Walker', 'J', 'M', 'Walker'],
        ];
    }

    #[DataProvider('initialProvider')]
    public function testInitialsSurviveThePrefixGuard(string $input, string $first, string $initials, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($initials, $name->getInitials(), "initials for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    public function testParticleBindsPastMidNameNickname(): void
    {
        $name = (new Parser())->parse('John van (Willy) Berg');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('van Berg', $name->getLastname());
        $this->assertSame('van', $name->getLastnamePrefix());
        $this->assertSame('Willy', $name->getNickname());
        $this->assertSame('', $name->getMiddlename());
    }
}
