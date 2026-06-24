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
}
