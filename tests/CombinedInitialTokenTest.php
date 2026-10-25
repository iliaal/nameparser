<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CombinedInitialTokenTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function nonInitialTokens(): iterable
    {
        yield 'digit after letter' => ['A1', 2];
        yield 'digit before letter' => ['1A', 2];
        yield 'trailing hyphen' => ['A-', 2];
        yield 'caseless character' => ['A李', 2];
        yield 'hyphenated token' => ['J-P', 3];
        yield 'apostrophe token' => ["O'B", 3];
        yield 'mixed dotted token' => ['A.1.', 2];
    }

    #[DataProvider('nonInitialTokens')]
    public function testCombinedMappingPreservesWholeNonInitialTokens(string $token, int $limit): void
    {
        $parts = ['John', $token, 'Smith'];

        $this->assertSame($parts, (new InitialMapper($limit))->map($parts));
    }

    public function testWesternNameDoesNotSplitAlphanumericMiddleName(): void
    {
        $name = (new Parser())->parse('John A1 Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('A1', $name->getMiddlename());
        $this->assertSame('', $name->getInitials());
        $this->assertSame('John A1 Smith', $name->getFullName());
    }

    public function testCommaNameDoesNotPromoteDigitToFirstname(): void
    {
        $name = (new Parser())->parse('Smith, A1 John');

        $this->assertSame('A1', $name->getFirstname());
        $this->assertSame('John', $name->getMiddlename());
        $this->assertSame('', $name->getInitials());
        $this->assertSame('A1 John Smith', $name->getFullName());
    }

    public function testRealCombinedInitialsStillSplit(): void
    {
        foreach (['JM', 'J.M.', "JE\u{0301}"] as $token) {
            $name = (new Parser())->parse('John ' . $token . ' Smith');
            $expected = $token === "JE\u{0301}" ? "J E\u{0301}" : 'J M';

            $this->assertSame($expected, $name->getInitials());
            $this->assertSame('', $name->getMiddlename());
        }
    }
    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function dottedParticleInitials(): iterable
    {
        yield 'western de collision' => ['John D.E. Smith', false, 'D E'];
        yield 'western le collision' => ['John L.E. Smith', false, 'L E'];
        yield 'optional final period' => ['John D.E Smith', false, 'D E'];
        yield 'comma given segment' => ['Smith, John D.E.', false, 'D E'];
        yield 'surname-first given segment' => ['Smith John D.E.', true, 'D E'];
    }

    #[DataProvider('dottedParticleInitials')]
    public function testDottedInitialsDoNotBecomeSurnameParticles(string $input, bool $surnameFirst, string $initials): void
    {
        $parser = (new Parser())->setSurnameFirst($surnameFirst);
        $parser->parse('SMITH, JO, MD');
        $name = $parser->parse($input);

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame($initials, $name->getInitials());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getLastnamePrefix());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('John ' . $initials . ' Smith', $name->getFullName());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function preservedParticleTokens(): iterable
    {
        yield 'undotted uppercase' => ['DE', 2];
        yield 'lowercase' => ['de', 2];
        yield 'trailing period only' => ['DE.', 2];
        yield 'repeated interior periods' => ['D..E.', 2];
        yield 'lowercase dotted' => ['d.e.', 2];
        yield 'single letter particle' => ['Ó.', 2];
        yield 'combined initials disabled' => ['D.E.', 0];
        yield 'combined initials below length' => ['D.E.', 1];
    }

    #[DataProvider('preservedParticleTokens')]
    public function testParticleAndInitialLimitPoliciesRemainUnchanged(string $token, int $limit): void
    {
        $parts = ['John', $token, 'Smith'];
        $mapper = new InitialMapper($limit, false, ['de' => 'de', 'ó' => 'Ó']);

        $this->assertSame($parts, $mapper->map($parts));
    }

    public function testUniformUppercaseDottedParticleKeepsAmbiguityPolicy(): void
    {
        $parts = ['JOHN', 'D.E.', 'SMITH'];
        $mapper = new InitialMapper(2, false, ['de' => 'de']);

        $this->assertSame($parts, $mapper->map($parts));
    }

    public function testExplicitCustomDottedPrefixRemainsAPrefix(): void
    {
        $mapper = new InitialMapper(2, false, ['de' => 'D.E.']);
        foreach (['D.E.', 'D.E', 'd.e.'] as $token) {
            $parts = ['John', $token, 'Smith'];
            $this->assertSame($parts, $mapper->map($parts));
        }
    }

    public function testDottedUnicodeInitialsRetainCombiningMarks(): void
    {
        foreach (['U.Í.' => 'U Í', "U.I\u{0301}." => "U I\u{0301}"] as $token => $initials) {
            $name = (new Parser())->parse('John ' . $token . ' Smith');

            $this->assertSame($initials, $name->getInitials());
            $this->assertSame('Smith', $name->getLastname());
        }
    }

}
