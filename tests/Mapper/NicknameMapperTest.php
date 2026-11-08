<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;
use PHPUnit\Framework\Attributes\DataProvider;

class NicknameMapperTest extends AbstractMapperTestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function provider(): array
    {
        return [
            [
                'input' => [
                    'James',
                    '(Jim)',
                    'T.',
                    'Kirk',
                ],
                'expectation' => [
                    'James',
                    new Nickname('Jim'),
                    'T.',
                    'Kirk',
                ],
            ],
            [
                'input' => [
                    'James',
                    '(\'Jim\')',
                    'T.',
                    'Kirk',
                ],
                'expectation' => [
                    'James',
                    new Nickname('Jim'),
                    'T.',
                    'Kirk',
                ],
            ],
            [
                'input' => [
                    'William',
                    '"Will"',
                    'Shatner',
                ],
                'expectation' => [
                    'William',
                    new Nickname('Will'),
                    'Shatner',
                ],
            ],
            [
                'input' => [
                    'John',
                    '(O\'Brien)',
                    'Smith',
                ],
                'expectation' => [
                    'John',
                    new Nickname('O\'Brien'),
                    'Smith',
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Andre',
                    '(The',
                    'Giant)',
                    'Rene',
                    'Roussimoff',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Andre',
                    new Nickname('The'),
                    new Nickname('Giant'),
                    'Rene',
                    'Roussimoff',
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Andre',
                    '["The',
                    'Giant"]',
                    'Rene',
                    'Roussimoff',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Andre',
                    new Nickname('The'),
                    new Nickname('Giant'),
                    'Rene',
                    'Roussimoff',
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Andre',
                    '"The',
                    'Giant"',
                    'Rene',
                    'Roussimoff',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Andre',
                    new Nickname('The'),
                    new Nickname('Giant'),
                    'Rene',
                    'Roussimoff',
                ],
            ],
            // An unmatched leading quote may be an elided particle.
            [
                'input' => [
                    'Gerard',
                    '\'t',
                    'Hooft',
                ],
                'expectation' => [
                    'Gerard',
                    '\'t',
                    'Hooft',
                ],
            ],
            [
                'input' => [
                    'John',
                    '\'Bob\'',
                    'Smith',
                ],
                'expectation' => [
                    'John',
                    new Nickname('Bob'),
                    'Smith',
                ],
            ],
            [
                'input' => [
                    'John',
                    '(',
                    'Bob',
                    ')',
                    'Smith',
                ],
                'expectation' => [
                    'John',
                    new Nickname('Bob'),
                    'Smith',
                ],
            ],
            [
                'input' => [
                    'John',
                    '(Bob',
                    '[X,',
                    'Y]',
                    'Z)',
                    'Doe',
                ],
                'expectation' => [
                    'John',
                    new Nickname('Bob'),
                    new Nickname('[X,'),
                    new Nickname('Y]'),
                    new Nickname('Z'),
                    'Doe',
                ],
            ],
            [
                'input' => [
                    'John',
                    '(Bob',
                    '[X)',
                    'Y]',
                    'Z)',
                ],
                'expectation' => [
                    'John',
                    new Nickname('Bob'),
                    new Nickname('[X)'),
                    new Nickname('Y]'),
                    new Nickname('Z'),
                ],
            ],
            [
                'input' => [
                    'John',
                    '(Bob',
                    '"X)',
                    'Y"',
                    'Z)',
                    'Doe',
                ],
                'expectation' => [
                    'John',
                    new Nickname('Bob'),
                    new Nickname('X)'),
                    new Nickname('Y'),
                    new Nickname('Z'),
                    'Doe',
                ],
            ],
            [
                'input' => [
                    'John',
                    '(Bob',
                    '[X])',
                    'Doe',
                ],
                'expectation' => [
                    'John',
                    new Nickname('Bob'),
                    new Nickname('[X]'),
                    'Doe',
                ],
            ],
        ];
    }

    public function testUnclosedNestedDelimiterRestoresTheWholeNicknameSpan(): void
    {
        $mapper = $this->getMapper();

        $this->assertSame(
            ['John', 'Bob', '[X', 'Y)', 'Doe'],
            $mapper->map(['John', '(Bob', '[X', 'Y)', 'Doe']),
        );
    }

    public function testCustomMultiCharacterDelimiterCanNestInsideAnotherPair(): void
    {
        $mapper = new NicknameMapper([
            '(' => ')',
            '<<' => '>>',
        ]);

        $this->assertSame(
            self::canonicalParts([
                'John',
                new Nickname('Bob'),
                new Nickname('<<X)'),
                new Nickname('Y>>'),
                new Nickname('Z'),
            ]),
            self::canonicalParts($mapper->map(['John', '(Bob', '<<X)', 'Y>>', 'Z)'])),
        );
    }

    public function testUnclosedCustomOpenerIsRemovedExactly(): void
    {
        $mapper = new NicknameMapper(['a..z' => ']']);

        $this->assertSame(
            ['John', 'Bob', 'Smith'],
            $mapper->map(['John', 'a..zBob', 'Smith']),
        );
    }

    public function testCustomOpenerDoesNotBecomeAnLtrimMask(): void
    {
        $mapper = new NicknameMapper(['z..a' => ']']);

        $this->assertSame(
            ['John', 'Bob', 'Smith'],
            $mapper->map(['John', 'z..aBob', 'Smith']),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function asymmetricDelimiterProvider(): iterable
    {
        yield 'ASCII' => ['(', ')'];
        yield 'multi-character ASCII' => ['<<', '>>'];
        yield 'multibyte' => ['«', '»'];
        yield 'multi-character Unicode' => ['«界', '界»'];
        yield 'combining mark' => ["e\u{0301}", 'x'];
        yield 'numeric key' => ['1', '2'];
    }

    #[DataProvider('asymmetricDelimiterProvider')]
    public function testOpeningDelimitersAreRemovedExactly(string $open, string $close): void
    {
        $mapper = new NicknameMapper([$open => $close]);

        $this->assertSame(
            self::canonicalParts(['John', new Nickname('Bób'), 'Smith']),
            self::canonicalParts($mapper->map(['John', $open . 'Bób' . $close, 'Smith'])),
        );
        $this->assertSame(
            ['John', 'Bób', 'Smith'],
            $mapper->map(['John', $open . 'Bób', 'Smith']),
        );
        $this->assertSame(
            ['John', 'Smith'],
            $mapper->map(['John', $open, 'Smith']),
        );

        // A malformed token cannot match the Unicode opener regex.
        $malformed = ['John', $open . "B\xFFb", 'Smith'];
        $this->assertSame($malformed, $mapper->map($malformed));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function customOpenerRegexProvider(): iterable
    {
        yield 'malformed closed token without Unicode flag' => ['/^(«)/', "«B\xFFb»", 'B?b', true];
        yield 'malformed unclosed token without Unicode flag' => ['/^(«)/', "«B\xFFb", 'B?b', false];
        yield 'capture after a prefix' => ['/^x(«)/u', 'x«Bób»', '«Bób', true];
        yield 'capture splits a UTF-8 character' => ['/^(\xC2)/', '«Bob', 'Bob', false];
    }

    #[DataProvider('customOpenerRegexProvider')]
    public function testCustomOpenerRegexKeepsCharacterSlicing(string $pattern, string $input, string $expected, bool $closed): void
    {
        $mapper = new class ($pattern) extends NicknameMapper {
            public function __construct(private string $pattern)
            {
                parent::__construct(['«' => '»']);
            }

            #[\Override]
            protected function buildRegexp(): string
            {
                return $this->pattern;
            }
        };
        $substitute = mb_substitute_character();
        mb_substitute_character(63);

        try {
            $this->assertSame(
                self::canonicalParts([$closed ? new Nickname($expected) : $expected]),
                self::canonicalParts($mapper->map([$input])),
            );
        } finally {
            mb_substitute_character($substitute);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function replacementDelimiterQuoteProvider(): array
    {
        return [
            'leading literal quote' => ["['Twas]", "'Twas"],
            'trailing literal quote' => ["['Twas']", "'Twas'"],
        ];
    }

    #[DataProvider('replacementDelimiterQuoteProvider')]
    public function testReplacementDelimitersPreserveLiteralQuoteContent(string $input, string $expected): void
    {
        $mapper = new NicknameMapper(['[' => ']']);

        $this->assertSame(
            self::canonicalParts([new Nickname($expected)]),
            self::canonicalParts($mapper->map([$input])),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function symmetricDelimiterProvider(): iterable
    {
        yield 'ASCII quote' => ['"'];
        yield 'multi-character ASCII' => ['||'];
        yield 'multibyte' => ['§'];
        yield 'multi-character Unicode' => ['§界'];
        yield 'combining mark' => ["e\u{0301}"];
    }

    #[DataProvider('symmetricDelimiterProvider')]
    public function testSymmetricClosersPreserveTokenBoundaries(string $delimiter): void
    {
        $mapper = new NicknameMapper([$delimiter => $delimiter]);

        $this->assertSame(
            self::canonicalParts(['John', new Nickname('Bób'), new Nickname('李'), 'Smith']),
            self::canonicalParts($mapper->map(['John', $delimiter . 'Bób', '李' . $delimiter, 'Smith'])),
        );
        $this->assertSame(
            self::canonicalParts(['John', new Nickname('Bób'), 'Smith']),
            self::canonicalParts($mapper->map(['John', $delimiter . 'Bób' . $delimiter, 'Smith'])),
        );
        $this->assertSame(
            ['John', $delimiter . 'Bób', '李' . $delimiter . ';', 'Smith'],
            $mapper->map(['John', $delimiter . 'Bób', '李' . $delimiter . ';', 'Smith']),
        );
        $this->assertSame(
            self::canonicalParts(['John', $delimiter . 'orphan', new Nickname('Bób'), 'Smith']),
            self::canonicalParts($mapper->map(['John', $delimiter . 'orphan', $delimiter . 'Bób' . $delimiter, 'Smith'])),
        );
    }

    public function testMalformedUtf8PreservesMbstringSubstitutionForClosers(): void
    {
        $substitute = mb_substitute_character();
        mb_substitute_character(63);

        try {
            $mapper = new NicknameMapper(['?' => '?']);
            $this->assertSame(
                ['John', 'Bob', "Bad\xFF", 'Smith'],
                $mapper->map(['John', '?Bob', "Bad\xFF", 'Smith']),
            );

            mb_substitute_character(39);
            $mapper = new NicknameMapper();
            $this->assertSame(
                ['John', 'Bob', "Bad\xFF", 'Smith'],
                $mapper->map(['John', "'Bob", "Bad\xFF", 'Smith']),
            );
        } finally {
            mb_substitute_character($substitute);
        }
    }

    protected function getMapper(): NicknameMapper
    {
        return new NicknameMapper([
            '[' => ']',
            '{' => '}',
            '(' => ')',
            '<' => '>',
            '"' => '"',
            '\'' => '\'',
        ]);
    }
}
