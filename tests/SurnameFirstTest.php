<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Ignored;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * setSurnameFirst(true) reads a space-separated, comma-less name in CJK order
 * (surname first): the first token is the surname, the rest is the given-name
 * segment, routed through the same split path as the comma form. It is an
 * opt-in mode the caller asserts for the batch, since romanized order cannot be
 * auto-detected; the default parser stays Western-ordered.
 */
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

    /**
     * a leading salutation must not be shifted away as the surname: it is peeled
     * off and the first real token becomes the surname
     */
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

    /**
     * a credential-only comma tail leaves an empty given segment; surname-first
     * order must be preserved for the surname portion rather than falling back
     * to Western order
     */
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
     * a comma-less space-form name with a trailing credential: the credential is
     * peeled to the suffix and the surname-first order is preserved for the rest
     */
    public function testSpaceFormCredentialTailIsPeeled(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Kim Jong Un MD');

        $this->assertSame('Kim', $name->getLastname());
        $this->assertSame('Jong', $name->getFirstname());
        $this->assertSame('Un', $name->getMiddlename());
        $this->assertSame('MD', $name->getSuffix());
    }

    /**
     * surname-first takes the first token as the surname verbatim, so a Western
     * particle-led name misparses by design: 'van' becomes the surname ('Van'),
     * not a prefix. This locks the documented first-token limitation.
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
        // the peel rebuild must re-emit an unattributed connector so it stays
        // visible in getParts(), matching the salutation-less form
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
