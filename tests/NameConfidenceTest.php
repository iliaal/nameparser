<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
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
        // no source recorded: getConfidence() reconstructs from the parts and
        // still returns the documented shape without error
        $name = new Name();
        $result = $name->getConfidence();

        $this->assertArrayHasKey('ambiguous', $result);
        $this->assertArrayHasKey('notes', $result);
    }
}
