<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Mapper\FirstnameMapper;
use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Mapper\MiddlenameMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Text;
use Iliaal\NameParser\TokenCredentialClass;
use PHPUnit\Framework\TestCase;

class ParserIntegrityRemediationTest extends TestCase
{
    public function testSpaceSeparatedRowHitsTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('AB ', 70000) . 'Smith');
    }

    // NBSP escapes the ASCII prefilter; capped Unicode splitting must bound the work.
    public function testNbspSeparatedRowStaysBounded(): void
    {
        $name = (new Parser())->parse(str_repeat("AB\xC2\xA0", 200000) . ', Smith');

        $this->assertSame('Smith', $name->getFirstname());
        $this->assertStringStartsWith('Ab', $name->getLastname());
    }

    // Normalization removes VT before the token budget runs.
    public function testVerticalTabIsStrippedByNormalize(): void
    {
        $name = (new Parser())->parse("AB\x0BAB");

        $this->assertSame('Abab', $name->getFirstname());
    }

    public function testInvalidUtf8WhitespaceKeepsBytewiseContract(): void
    {
        $name = (new Parser())->setWhitespace("\xFF")->parse("John\xFFSmith");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testMaskingShieldsNicknameCommas(): void
    {
        $name = (new Parser())->parse('John (Bob, Jr) Doe');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Bob, Jr', $name->getNickname());
        $this->assertSame('Doe', $name->getLastname());

        $quoted = (new Parser())->parse("John 'Bob, Boy' Doe");

        $this->assertSame('John', $quoted->getFirstname());
        $this->assertSame('Doe', $quoted->getLastname());
    }

    public function testHostileDelimiterPairsAreIgnored(): void
    {
        $parser = (new Parser())->setNicknameDelimiters([
            ',' => ',',
            "a\x00b" => ')',
            'a b' => ')',
            "a\tb" => ')',
            '(' => ')',
        ]);

        $this->assertSame(['(' => ')'], Text::sanitizeNicknameDelimiters([
            ',' => ',',
            "a\x00b" => ')',
            'a b' => ')',
            '(' => ')',
        ]));

        $name = $parser->parse('Smith, John');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testInvalidUtf8IsScrubbedDeterministically(): void
    {
        $name = (new Parser())->parse("John\xFFSmith");

        $this->assertSame('John?Smith', $name->getFirstname());
        $this->assertTrue(mb_check_encoding($name->getFirstname(), 'UTF-8'));
    }

    public function testCommaCredentialNoiseDropsAreAccounted(): void
    {
        $name = (new Parser())->parse('Smith, Jane, -, MD');

        $this->assertSame('Jane', $name->getFirstname());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());

        foreach ($name->getParts() as $part) {
            $this->assertNotSame('-', is_string($part) ? $part : $part->getValue());
        }
        $this->assertTrue(Text::isCredentialPlaceholder('Unknown'));
        $this->assertFalse(Text::isCredentialPlaceholder('-'));
        $this->assertFalse(Text::isCredentialPlaceholder('John'));
    }

    public function testSurnameUnknownSurvivesCommaCredential(): void
    {
        $name = (new Parser())->parse('John Unknown, MD');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Unknown', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testKeyCacheStaysExactPastEviction(): void
    {
        for ($i = 0; $i < 5000; $i++) {
            Text::key('token-' . $i . '.');
        }

        $this->assertSame('john', Text::key('John.'));
        $this->assertSame('token-0', Text::key('token-0.'));
        $this->assertSame('token-4999', Text::key('token-4999.'));

        $long = str_repeat('A', 100);

        $this->assertSame(Text::key($long), Text::key($long));

        Text::clearCache();

        $this->assertSame('john', Text::key('John.'));
    }

    public function testLongTokenClassifiesIdentically(): void
    {
        $name = (new Parser())->parse(str_repeat('A', 2000));

        $this->assertSame(2000, strlen($name->getFirstname()));
        $this->assertSame('', $name->getLastname());
    }

    public function testGenerationalAbbreviationsPromote(): void
    {
        foreach (['Smith, Jr', 'Smith, Sr'] as $input) {
            $name = (new Parser())->parse($input);

            $this->assertSame('Smith', $name->getLastname(), "last for '$input'");
            $this->assertSame('', $name->getSuffix(), "suffix for '$input'");
            $this->assertNotSame('', $name->getFirstname(), "first for '$input'");
        }

        $this->assertSame('Jr', (new Parser())->parse('Smith, Jr')->getFirstname());
        $this->assertSame('Sr', (new Parser())->parse('Smith, Sr')->getFirstname());
        $this->assertSame('Junior', (new Parser())->parse('Smith, Junior')->getFirstname());

        $withGiven = (new Parser())->parse('Smith, John Jr');

        $this->assertSame('John', $withGiven->getFirstname());
        $this->assertSame('Jr', $withGiven->getSuffix());
    }

    public function testTokenCredentialClassValues(): void
    {
        $this->assertSame(0, TokenCredentialClass::Name->value);
        $this->assertSame(1, TokenCredentialClass::DictionaryCredential->value);
        $this->assertSame(2, TokenCredentialClass::UnknownCandidate->value);
    }

    public function testInvalidUtf8CommaRowSplitsDeterministically(): void
    {
        $name = (new Parser())->setWhitespace("\xFF")->parse("John\xFF(Bob, Jr");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Bob', $name->getLastname());
        $this->assertSame('Jr', $name->getSuffix());
    }

    public function testNulBytesAreStripped(): void
    {
        $name = (new Parser())->parse("Jo\x00hn Smith");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testNoiseAccountingInParts(): void
    {
        $anchored = (new Parser())->parse('Smith, Jane, MD, -');
        $values = array_map(
            static fn($part): string => is_string($part) ? $part : $part->getValue(),
            $anchored->getParts(),
        );

        $this->assertNotContains('-', $values);
        $this->assertSame('MD', $anchored->getSuffix());

        $unanchored = (new Parser())->parse('Smith, Jane, -');
        $unanchoredValues = array_map(
            static fn($part): string => is_string($part) ? $part : $part->getValue(),
            $unanchored->getParts(),
        );

        $this->assertContains('-', $unanchoredValues);
    }

    public function testDefaultPipelineStageSequence(): void
    {
        $classes = array_map(
            static fn($mapper): string => $mapper::class,
            (new Parser())->getMappers(),
        );

        $this->assertSame(
            [
                SalutationMapper::class,
                SuffixMapper::class,
                NicknameMapper::class,
                SuffixMapper::class,
                InitialMapper::class,
                LastnameMapper::class,
                FirstnameMapper::class,
                MiddlenameMapper::class,
            ],
            $classes,
        );
    }

    public function testCustomMappersDoNotAffectCommaPath(): void
    {
        $parser = (new Parser())->setMappers([
            new FirstnameMapper(),
            new LastnameMapper([]),
        ]);

        $comma = $parser->parse('Smith, John MD');

        $this->assertSame('MD', $comma->getSuffix());
        $this->assertSame('John', $comma->getFirstname());
        $this->assertSame('Smith', $comma->getLastname());

        $plain = $parser->parse('John Smith MD');

        $this->assertSame('', $plain->getSuffix());
    }

    public function testLongRowKeepsNicknameShielding(): void
    {
        $name = (new Parser())->parse('Smith, John ' . str_repeat('x', 5000) . ' (Bob, Jr)');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Bob, Jr', $name->getNickname());
        $this->assertSame('', $name->getSuffix());
        $this->assertStringStartsWith('John', $name->getFirstname());
    }

    public function testIsUniformUpperTokens(): void
    {
        $this->assertTrue(Text::isUniformUpperTokens(['JOHN', 'DOE-2']));
        $this->assertTrue(Text::isUniformUpperTokens(['JOHN', '中文']));
        $this->assertTrue(Text::isUniformUpperTokens(['DOE', '123']));
        $this->assertFalse(Text::isUniformUpperTokens(['John', 'DOE']));
        $this->assertFalse(Text::isUniformUpperTokens(['123', '---']));
        $this->assertFalse(Text::isUniformUpperTokens([]));
        $this->assertFalse(Text::isUniformUpperTokens(['中文']));
    }

    public function testUniformUpperGateAtParseLevel(): void
    {
        $upper = (new Parser())->parse('JOHN SMITH');
        $mixed = (new Parser())->parse('John Smith');

        $this->assertSame($upper->getFirstname(), $mixed->getFirstname());
        $this->assertSame($upper->getLastname(), $mixed->getLastname());
    }
}
