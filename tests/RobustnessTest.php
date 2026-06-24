<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Edge-case regressions: Unicode handling, multi-segment comma input, empty
 * nickname rendering, custom whitespace, and salutation-index overflow.
 */
class RobustnessTest extends TestCase
{
    public function testUnicodeInitialIsNotCorrupted(): void
    {
        $name = (new Parser())->parse("\u{00C9} Durand");

        $this->assertSame('Durand', $name->getLastname());
        $combined = $name->getFirstname() . $name->getInitials();
        $this->assertStringContainsString("\u{00C9}", $combined);
        $this->assertStringNotContainsString("\u{FFFD}", $combined, 'no replacement char');
    }

    public function testTrailingCommaCredentialsAreNotDropped(): void
    {
        $name = (new Parser())->parse('Smith, John, MD, PhD');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertStringContainsString('MD', $name->getSuffix());
        $this->assertStringContainsString('PhD', $name->getSuffix());
    }

    public function testToStringOmitsEmptyNicknameParentheses(): void
    {
        $this->assertSame('John Smith', (string) (new Parser())->parse('John Smith'));
        $this->assertSame('Bob', (new Parser())->parse('John (Bob) Smith')->getNickname());
    }

    public function testCustomWhitespaceTrimsEdges(): void
    {
        $parser = new Parser();
        $parser->setWhitespace('_');
        $name = $parser->parse('_John_Smith_');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testMaxSalutationIndexBeyondPartsDoesNotWarn(): void
    {
        // phpunit.xml sets failOnWarning, so an undefined-array-key warning here
        // fails the test rather than passing silently.
        $parser = new Parser();
        $parser->setMaxSalutationIndex(10);
        $name = $parser->parse('Mr');

        $this->assertSame('Mr.', $name->getSalutation());
    }

    public function testUnclosedDelimiterDoesNotLeakIntoName(): void
    {
        $this->assertSame('Jones', (new Parser())->parse('Bob Jones (')->getLastname());
        $this->assertSame('Smith', (new Parser())->parse('John (Bob Smith')->getLastname());
    }

    /**
     * A lone nickname delimiter is stripped to nothing, leaving no parts. The
     * parser must return an empty Name rather than throw, so one malformed cell
     * does not abort a batch import. failOnWarning also catches the undefined
     * array-key warning that preceded the TypeError.
     */
    #[DataProvider('loneDelimiterProvider')]
    public function testLoneDelimiterTokenDoesNotCrash(string $input): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('', $name->getFirstname());
        $this->assertSame('', $name->getLastname());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loneDelimiterProvider(): array
    {
        return [
            'open paren'   => ['('],
            'open brace'   => ['{'],
            'open bracket' => ['['],
            'open angle'   => ['<'],
            'double quote' => ['"'],
            'single quote' => ["'"],
        ];
    }

    public function testCommaSegmentWithLoneDelimiterKeepsSurname(): void
    {
        $name = (new Parser())->parse('Smith, (');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getFirstname());
    }
}
