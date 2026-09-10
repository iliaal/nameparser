<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Ignored;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SurnameFirstTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, first, middle, last
            'two-token chinese'   => ['Mao Zedong', 'Zedong', '', 'Mao'],
            'two-token chinese 2' => ['Xi Jinping', 'Jinping', '', 'Xi'],
            'three-token korean'  => ['Kim Jong Un', 'Jong', 'Un', 'Kim'],
            'hyphenated given'    => ['Park Geun-hye', 'Geun-Hye', '', 'Park'],
            'three-token chinese' => ['Lee Kuan Yew', 'Kuan', 'Yew', 'Lee'],
        ];
    }

    #[DataProvider('provider')]
    public function testSurnameFirstOrder(string $input, string $first, string $middle, string $last): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    public function testSingleTokenIsLeftAsGivenName(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Kim');

        $this->assertSame('Kim', $name->getFirstname());
        $this->assertSame('', $name->getLastname());
    }

    public function testCommaFormTakesPrecedence(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Smith, John');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testDefaultParserStaysWesternOrdered(): void
    {
        $name = (new Parser())->parse('Mao Zedong');

        $this->assertSame('Mao', $name->getFirstname());
        $this->assertSame('Zedong', $name->getLastname());
    }

    public function testLeadingSalutationIsNotSurname(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Dr. Kim Jong Un');

        $this->assertSame('Dr.', $name->getSalutation());
        $this->assertSame('Kim', $name->getLastname());
        $this->assertSame('Jong', $name->getFirstname());
        $this->assertSame('Un', $name->getMiddlename());
    }

    public function testLeadingMultiWordSalutationIsPeeled(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('His Honour Kim Jong Un');

        $this->assertSame('His Honour', $name->getSalutation());
        $this->assertSame('Kim', $name->getLastname());
        $this->assertSame('Jong', $name->getFirstname());
        $this->assertSame('Un', $name->getMiddlename());
    }

    #[DataProvider('compoundLeadingSalutationProvider')]
    public function testCompoundLeadingSalutationIsPeeled(string $input, string $salutation, bool $joint): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse($input);

        $this->assertSame($salutation, $name->getSalutation());
        $this->assertSame($joint, $name->isJoint());
        $this->assertSame('Kim', $name->getLastname());
        $this->assertSame('Jong', $name->getFirstname());
        $this->assertSame('Un', $name->getMiddlename());
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function compoundLeadingSalutationProvider(): array
    {
        return [
            'leading article' => ['The Rev. Kim Jong Un', 'Rev.', false],
            'stacked titles'  => ['Rev. Dr. Kim Jong Un', 'Rev. Dr.', false],
            'joint titles'    => ['Mr. and Mrs. Kim Jong Un', 'Mr. and Mrs.', true],
        ];
    }

    public function testCredentialOnlyTailKeepsSurnameFirstOrder(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Kim Jong Un, MD');

        $this->assertSame('Kim', $name->getLastname());
        $this->assertSame('Jong', $name->getFirstname());
        $this->assertSame('Un', $name->getMiddlename());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testExplicitCommaGivenStillWinsUnderSurnameFirst(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Kim, Jong');

        $this->assertSame('Jong', $name->getFirstname());
        $this->assertSame('Kim', $name->getLastname());
    }

    /**
     * @return array<string, array{string, string, string, string, string, string}>
     */
    public static function singleTokenCredentialTailProvider(): array
    {
        // input, salutation, firstname, middlename, lastname, suffix
        return [
            'comma single-token credential tail' => ['Kim, MD', '', '', '', 'Kim', 'MD'],
            'space single-token credential tail' => ['Kim MD', '', '', '', 'Kim', 'MD'],
            'salutation with single-token credential tail' => ['Dr. Kim, MD', 'Dr.', '', '', 'Kim', 'MD'],
            'salutation with space credential tail' => ['Dr. Kim MD', 'Dr.', '', '', 'Kim', 'MD'],
            'interrupted tail keeps surname-first order' => ['Kim MD John', '', 'John', '', 'Kim', 'MD'],
            'comma interrupted tail keeps surname-first order' => ['Kim, MD, John', '', 'John', '', 'Kim', 'MD'],
        ];
    }

    #[DataProvider('singleTokenCredentialTailProvider')]
    public function testSingleTokenSurnameFirstCredentialTails(
        string $input,
        string $salutation,
        string $first,
        string $middle,
        string $last,
        string $suffix,
    ): void {
        $name = (new Parser())->setSurnameFirst(true)->parse($input);

        $this->assertSame($salutation, $name->getSalutation(), "salutation for '$input'");
        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    public function testSpaceFormCredentialTailIsPeeled(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Kim Jong Un MD');

        $this->assertSame('Kim', $name->getLastname());
        $this->assertSame('Jong', $name->getFirstname());
        $this->assertSame('Un', $name->getMiddlename());
        $this->assertSame('MD', $name->getSuffix());
    }

    /**
     * The caller asserted first-token surname order, so Western particles may misparse.
     */
    public function testParticleLeadingInputHitsFirstTokenLimitation(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('van Gogh Vincent');

        $this->assertSame('Van', $name->getLastname());
        $this->assertSame('Gogh', $name->getFirstname());
        $this->assertSame('Vincent', $name->getMiddlename());
    }

    public function testIsSurnameFirstGetterRoundTrips(): void
    {
        $parser = new Parser();
        $this->assertFalse($parser->isSurnameFirst());

        $parser->setSurnameFirst(true);
        $this->assertTrue($parser->isSurnameFirst());

        $parser->setSurnameFirst(false);
        $this->assertFalse($parser->isSurnameFirst());
    }

    public function testLeadingSalutationWithCredentialTailKeepsSurname(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Dr. Kim Jong Un, MD');

        $this->assertSame('Dr.', $name->getSalutation());
        $this->assertSame('Kim', $name->getLastname());
        $this->assertSame('Jong', $name->getFirstname());
        $this->assertSame('Un', $name->getMiddlename());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testPeeledSalutationKeepsIgnoredConnectorVisible(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Dr. Kim and Jong Un');

        $this->assertSame('Dr.', $name->getSalutation());
        $this->assertSame('Kim', $name->getLastname());
        $this->assertSame('Jong', $name->getFirstname());

        $ignored = [];
        foreach ($name->getParts() as $part) {
            if ($part instanceof Ignored) {
                $ignored[] = $part->getValue();
            }
        }

        $this->assertSame(['and'], $ignored);
    }
}
