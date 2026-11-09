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

    /**
     * @return iterable<string, array{array<string, string>, list<string>, list<string|Nickname>}>
     */
    public static function adjacentNestedOpenerProvider(): iterable
    {
        yield 'same pair across tokens' => [[], ['John', '((Bob)', 'Robert)', 'Doe'],
            ['John', new Nickname('(Bob)'), new Nickname('Robert'), 'Doe']];
        yield 'all closers on one token' => [[], ['John', '((Bob))', 'Doe'],
            ['John', new Nickname('(Bob)'), 'Doe']];
        yield 'mixed pairs' => [[], ['John', '([Bob]', 'Robert)', 'Doe'],
            ['John', new Nickname('[Bob]'), new Nickname('Robert'), 'Doe']];
        yield 'inside an existing span' => [[], ['John', '(Bob', '((Rob)', 'Robert)', 'Z)', 'Doe'],
            ['John', new Nickname('Bob'), new Nickname('((Rob)'), new Nickname('Robert)'), new Nickname('Z'), 'Doe']];
        yield 'unclosed outer span' => [[], ['John', '((Bob)', 'Smith'],
            ['John', '(Bob)', 'Smith']];
        yield 'mismatched inner closer' => [[], ['John', '([Bob)', 'Robert]', 'Doe'],
            ['John', '[Bob)', 'Robert]', 'Doe']];
        yield 'punctuation after inner closer' => [[], ['John', '((Bob);', 'Robert)', 'Doe'],
            ['John', new Nickname('(Bob);'), new Nickname('Robert'), 'Doe']];
        yield 'inner quote remains decoration' => [[], ['John', '("Bob', 'Robert")', 'Doe'],
            ['John', new Nickname('Bob'), new Nickname('Robert'), 'Doe']];
        yield 'inner unmatched quote is not a new span' => [[], ['John', '("Bob', 'Robert)', 'Doe'],
            ['John', new Nickname('Bob'), new Nickname('Robert'), 'Doe']];
        yield 'outer quote with asymmetric nesting' => [[], ['John', '"(Bob)', 'Robert"', 'Doe'],
            ['John', new Nickname('(Bob)'), new Nickname('Robert'), 'Doe']];
        yield 'unclosed asymmetric span inside an outer quote' => [[], ['John', '"(Bob', 'Robert"', 'Doe'],
            ['John', new Nickname('(Bob'), new Nickname('Robert'), 'Doe']];
        yield 'empty nested nickname' => [[], ['John', '(()', ')', 'Doe'],
            ['John', new Nickname('()'), 'Doe']];

        foreach (self::asymmetricDelimiterProvider() as $label => [$open, $close]) {
            yield $label . ' nested prefix' => [[$open => $close],
                ['John', $open . $open . 'Bób' . $close, 'Robert' . $close, 'Doe'],
                ['John', new Nickname($open . 'Bób' . $close), new Nickname('Robert'), 'Doe']];
        }
        foreach (self::asymmetricDelimiterProvider() as $label => [$open, $close]) {
            // Use a distinct outer pair so its closer cannot close the literal prefix.
            $delimiters = ['{' => '}', $open => $close];
            yield $label . ' literal prefix' => [$delimiters,
                ['John', '{' . $open . 'Bób}', 'Doe'],
                ['John', new Nickname($open . 'Bób'), 'Doe']];
            yield $label . ' literal prefix across tokens' => [$delimiters,
                ['John', '{' . $open . 'Bób', 'Robert}', 'Doe'],
                ['John', new Nickname($open . 'Bób'), new Nickname('Robert'), 'Doe']];
            yield $label . ' literal inside nested span' => [$delimiters,
                ['John', '{{' . $open . 'Bób}', 'Robert}', 'Doe'],
                ['John', new Nickname('{' . $open . 'Bób}'), new Nickname('Robert'), 'Doe']];
            yield $label . ' closer only before opener' => [$delimiters,
                ['Earlier' . $close, 'John', '{' . $open . 'Bób}', 'Doe'],
                ['Earlier' . $close, 'John', new Nickname($open . 'Bób'), 'Doe']];
        }
        foreach (['<' => '>', '[' => ']', '{' => '}'] as $open => $close) {
            yield $open . ' default literal prefix' => [[],
                ['John', '(' . $open . 'Bob)', 'Doe'],
                ['John', new Nickname($open . 'Bob'), 'Doe']];
        }
        yield 'closer also opens another pair' => [['(' => ')', ')' => ']'],
            ['John', '()', 'Doe'], ['John', 'Doe']];
        yield 'closer begins with its opener' => [['a' => 'aa'],
            ['John', 'aaa', 'Doe'], ['John', 'Doe']];
        yield 'overlapping pair inside a span' => [['(' => ')', ')' => ']'],
            ['John', '(Bob', '()', 'Robert)', 'Doe'],
            ['John', new Nickname('Bob'), new Nickname('()'), new Nickname('Robert'), 'Doe']];
        yield 'longest opener wins' => [['<' => '>', '<<' => '>>'],
            ['John', '<<<<Bob>>', 'Robert>>', 'Doe'],
            ['John', new Nickname('<<Bob>>'), new Nickname('Robert'), 'Doe']];
    }

    /**
     * @param  array<string, string>  $delimiters
     * @param  list<string>  $input
     * @param  list<string|Nickname>  $expected
     */
    #[DataProvider('adjacentNestedOpenerProvider')]
    public function testAdjacentNestedOpeners(array $delimiters, array $input, array $expected): void
    {
        $this->assertSame(
            self::canonicalParts($expected),
            self::canonicalParts((new NicknameMapper($delimiters))->map($input)),
        );
    }

    public function testAdjacentCloserCacheIsResetBetweenCalls(): void
    {
        $mapper = new NicknameMapper();
        $mapper->map(['John', '([Bob', 'Robert])', 'Doe']);
        $this->assertSame(
            self::canonicalParts(['John', new Nickname('[Bob'), 'Doe']),
            self::canonicalParts($mapper->map(['John', '([Bob)', 'Doe'])),
        );
        $this->assertSame(
            self::canonicalParts(['John', new Nickname('[Bob'), new Nickname('Robert]'), 'Doe']),
            self::canonicalParts($mapper->map(['John', '([Bob', 'Robert])', 'Doe'])),
        );
    }

    public function testAdjacentNestedOpenersRespectTheDepthLimit(): void
    {
        $mapper = new NicknameMapper();
        foreach ([63, 64] as $depth) {
            $token = str_repeat('(', $depth) . 'Bob' . str_repeat(')', $depth - 1);
            $this->assertSame(
                self::canonicalParts(['John', new Nickname(substr($token, 1)), new Nickname('Robert'), 'Doe']),
                self::canonicalParts($mapper->map(['John', $token, 'Robert)', 'Doe'])),
                'Still open at depth ' . $depth,
            );
        }
        foreach ([63, 64, 65] as $depth) {
            // The 65th opener stays literal, so 64 closers still close the tracked span.
            $token = str_repeat('(', $depth) . 'Bob' . str_repeat(')', min($depth, 64));
            $expected = substr($token, 1, -1);
            $this->assertSame(
                self::canonicalParts(['John', new Nickname($expected), 'Doe']),
                self::canonicalParts($mapper->map(['John', $token, 'Doe'])),
                'Depth ' . $depth,
            );
        }
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
        yield 'unanchored capture after a prefix' => ['/(«)/u', 'x««Bób»', '««Bób', true];
        yield 'malformed nested token without Unicode flag' => ['/^(«)/', "««B\xFFb»", '«B?b', true];
        yield 'empty alternative after the opener' => ['/^(«?)/u', '«Bób»', 'Bób', true];
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
