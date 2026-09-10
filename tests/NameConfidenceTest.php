<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use Iliaal\NameParser\Language\German;
use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameConfidenceTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function inputProvider(): array
    {
        return [
            'ambiguous all caps'   => ['ANH TRAN DO'],
            'ambiguous all lower'  => ['anh tran do'],
            'decidable title case' => ['Anh Tran Do'],
            'decidable credential' => ['Jane Doe DDS'],
            'plain name'           => ['John Doe'],
            'comma form'           => ['NGUYEN, VI'],
        ];
    }

    #[DataProvider('inputProvider')]
    public function testGetConfidenceMatchesAssessOnSameInput(string $input): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame(Confidence::assess($input), $name->getConfidence());
    }

    public function testFlaggedParseExposesAmbiguity(): void
    {
        $result = (new Parser())->parse('NGUYEN, VI')->getConfidence();

        $this->assertTrue($result['ambiguous']);
        $this->assertNotEmpty($result['notes']);
    }

    public function testDecidableParseIsNotFlagged(): void
    {
        $result = (new Parser())->parse('John Doe')->getConfidence();

        $this->assertFalse($result['ambiguous']);
        $this->assertSame([], $result['notes']);
    }

    public function testManuallyConstructedNameFallsBackToReconstruction(): void
    {
        $name = new Name();
        $result = $name->getConfidence();

        $this->assertArrayHasKey('ambiguous', $result);
        $this->assertArrayHasKey('notes', $result);
    }

    public function testParsedConfidenceUsesConfiguredLanguages(): void
    {
        $name = (new Parser([new German()]))->parse('JOHN MBA');

        $this->assertSame('Mba', $name->getLastname());
        $this->assertFalse($name->getConfidence()['ambiguous']);
    }

    public function testParsedConfidenceUsesConfiguredWhitespace(): void
    {
        $name = (new Parser())
            ->setWhitespace('')
            ->parse("ANH\tTRAN\tDO");

        $this->assertFalse($name->getConfidence()['ambiguous']);
    }

    public function testParsedConfidenceUsesConfiguredSalutations(): void
    {
        $name = (new Parser([new German()]))->parse('Lord Ashcroft');

        $this->assertFalse($name->getConfidence()['ambiguous']);
    }
}
