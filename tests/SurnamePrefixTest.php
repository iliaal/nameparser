<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Surname-prefix handling in two paths:
 *
 *  1. comma form ("Last, First"): the surname segment is parsed as a pure
 *     surname, so a leading prefix ("van der Berg", "de Vries") stays in the
 *     lastname instead of leaking its first token into the firstname.
 *  2. main pipeline: the Dutch ("van den", "ten") and Spanish ("de los")
 *     multi-particle prefixes resolve token by token onto the lastname.
 *
 * The compound-given-name case ("Maria de los Angeles ...") is locked as a
 * non-regression: mapping stops at the surname before the particles are
 * re-evaluated, so adding los/las does not pull the given name into the
 * lastname.
 */
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
     * a particle in a compound given name renders in the same lowercase form as
     * a surname particle, instead of being title-cased like a plain middle name
     *
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
     * A multi-particle surname with no firstname (bare or salutation-led) keeps
     * the whole surname instead of leaking the leading particle into the first
     * name. The discriminator is that the leading particle is followed by another
     * prefix particle, so it is unambiguously mid-surname.
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
     * A single prefix word with no further particle stays the firstname: it is
     * genuinely ambiguous (particle vs. given name like "Della"), so the no-
     * firstname relaxation must not fire and pull it into the lastname.
     */
    public function testSinglePrefixWordAfterSalutationStaysFirstname(): void
    {
        $name = (new Parser())->parse('Mr. Della Smith');

        $this->assertSame('Della', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * German particles and contractions, and French articles, resolve under the
     * default (English) parser without opting into a language class.
     *
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
     * Portuguese/Brazilian contractions (do/dos/das), Filipino joined particles
     * (dela/delos/delas), and the Italian article (lo) resolve onto the lastname
     * under the default parser rather than orphaning the particle into the middle
     * name. "do" collides with the DO credential but casing decides: lowercase is
     * a particle, ALL-CAPS is the credential (covered separately below).
     *
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
     * The new particles double as standalone surnames (Vietnamese Do, Chinese Lo,
     * Indian Das). With no token after them they must not be consumed as a prefix:
     * a prefix only binds when followed by a lastname part. The DO credential still
     * strips because ALL-CAPS reads as a credential, lowercase as a particle.
     *
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
}
