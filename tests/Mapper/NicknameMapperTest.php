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
            ['John', new Nickname('[Bob'), 'Robert]', 'Doe']];
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

    /**
     * @return iterable<string, array{array<string, string>, list<string>, list<string|Nickname>}>
     */
    public static function enclosingBoundaryProvider(): iterable
    {
        $pairs = [
            'parenthesis' => ['(', ')'],
            'bracket' => ['[', ']'],
            'brace' => ['{', '}'],
            'angle' => ['<', '>'],
            ...iterator_to_array(self::asymmetricDelimiterProvider()),
        ];
        foreach ($pairs as $label => [$open, $close]) {
            $outerOpen = $open === '(' ? '{' : '(';
            $outerClose = $open === '(' ? '}' : ')';
            $delimiters = [$outerOpen => $outerClose, $open => $close];
            $literal = $open . 'Bób';
            yield $label . ' closer after span' => [$delimiters,
                ['John', $outerOpen . $literal . $outerClose, 'Doe', $open . 'Bobby' . $close],
                ['John', new Nickname($literal), 'Doe', new Nickname('Bobby')]];
            yield $label . ' closer after multi-token span' => [$delimiters,
                ['John', $outerOpen . $literal, 'Robert' . $outerClose, 'Doe', $open . 'Bobby' . $close],
                ['John', new Nickname($literal), new Nickname('Robert'), 'Doe', new Nickname('Bobby')]];
            yield $label . ' closer before and after span' => [$delimiters,
                ['Earlier' . $close, 'John', $outerOpen . $literal . $outerClose, 'Doe', 'Later' . $close],
                ['Earlier' . $close, 'John', new Nickname($literal), 'Doe', 'Later' . $close]];
            yield $label . ' inner before outer in same token' => [$delimiters,
                ['John', $outerOpen . $literal . $close . $outerClose, 'Doe'],
                ['John', new Nickname($literal . $close), 'Doe']];
            yield $label . ' outer before inner in same token' => [$delimiters,
                ['John', $outerOpen . $literal . $outerClose . $close . 'tail' . $outerClose, 'Doe'],
                ['John', new Nickname($literal . $outerClose . $close . 'tail'), 'Doe']];
            yield $label . ' future same-token order' => [$delimiters,
                ['John', $outerOpen . $literal, 'Robert' . $outerClose . $close . 'tail' . $outerClose, 'Doe'],
                ['John', new Nickname($literal), new Nickname('Robert' . $outerClose . $close . 'tail'), 'Doe']];
            yield $label . ' descendant consumes enclosing closer' => [$delimiters,
                ['John', $outerOpen . $open . $outerOpen . 'Bób' . $outerClose, 'Robert' . $close, 'Z' . $outerClose, 'Doe'],
                ['John', new Nickname($open . $outerOpen . 'Bób' . $outerClose), new Nickname('Robert' . $close), new Nickname('Z'), 'Doe']];
            yield $label . ' balanced same-closer nesting' => [[$open => $close],
                ['John', $open . $literal . $close, 'Robert' . $close, 'Doe'],
                ['John', new Nickname($literal . $close), new Nickname('Robert'), 'Doe']];
        }
        yield 'parent-like literal within word' => [[],
            ['John', '([Smith)son', 'Bob]', 'Robert)', 'Doe'],
            ['John', new Nickname('[Smith)son'), new Nickname('Bob]'), new Nickname('Robert'), 'Doe']];
        yield 'parent-like punctuation within word' => [[],
            ['John', '([Smith);son', 'Bob]', 'Robert)', 'Doe'],
            ['John', new Nickname('[Smith);son'), new Nickname('Bob]'), new Nickname('Robert'), 'Doe']];
        yield 'unclosed identical triple nesting' => [[],
            ['John', '(((Bob)', 'Doe'], ['John', '((Bob)', 'Doe']];
        yield 'all enclosing spans bound a candidate' => [[],
            ['John', '([<Bob)', 'Robert>]', 'Doe'],
            ['John', new Nickname('[<Bob'), 'Robert>]', 'Doe']];
        yield 'ambiguous mixed nesting uses first distinct boundary' => [[],
            ['John', '([Bob)', 'Robert]', 'Z)', 'Doe'],
            ['John', new Nickname('[Bob'), 'Robert]', 'Z)', 'Doe']];
        yield 'distinct overlapping closers stay literal' => [['(' => '>>', '[' => '>'],
            ['John', '([Bob>>>', 'Doe'],
            ['John', new Nickname('[Bob>'), 'Doe']];
        yield 'multibyte distinct overlapping closers stay literal' => [['«' => '界»', '‹' => '界'],
            ['John', '«‹Bób界»', 'Doe'],
            ['John', new Nickname('‹Bób'), 'Doe']];
        yield 'closer within consumed opener is not future' => [['(' => ')', 'ab' => 'a'],
            ['John', '(abBob)', 'Doe'],
            ['John', new Nickname('abBob'), 'Doe']];
        yield 'symmetric enclosing boundary' => [['"' => '"', '(' => ')'],
            ['John', '"(Bob"', 'Doe', '(Bobby)'],
            ['John', new Nickname('(Bob'), 'Doe', new Nickname('Bobby')]];
    }

    /**
     * @param  array<string, string>  $delimiters
     * @param  list<string>  $input
     * @param  list<string|Nickname>  $expected
     */
    #[DataProvider('enclosingBoundaryProvider')]
    public function testAdjacentOpenersStayInsideEnclosingBoundary(array $delimiters, array $input, array $expected): void
    {
        $this->assertSame(
            self::canonicalParts($expected),
            self::canonicalParts((new NicknameMapper($delimiters))->map($input)),
        );
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>, list<string|Nickname>}>
     */
    public static function matchableAdjacentCloserProvider(): iterable
    {
        $pairs = [
            'parenthesis' => ['(', ')'],
            'bracket' => ['[', ']'],
            'brace' => ['{', '}'],
            'angle' => ['<', '>'],
            ...iterator_to_array(self::asymmetricDelimiterProvider()),
        ];
        foreach ($pairs as $label => [$open, $close]) {
            foreach (['asymmetric', 'symmetric'] as $enclosing) {
                $outerOpen = $enclosing === 'symmetric' ? '"' : ($open === '(' ? '{' : '(');
                $outerClose = $enclosing === 'symmetric' ? '"' : ($open === '(' ? '}' : ')');
                $delimiters = [$outerOpen => $outerClose, $open => $close];
                $prefix = $open . 'Bob';
                $cases = [
                    'embedded current closer' => [$prefix . $close . 'X', 'Robert'],
                    'embedded future closer' => [$prefix, 'Rob' . $close . 'X', 'Robert'],
                    'closer starts token' => [$prefix, $close . 'Robert'],
                    'closer followed by punctuation before outer' => [$prefix . $close . ';'],
                    'closer followed by period before outer' => [$prefix . $close . '.'],
                    'owned closer' => [$prefix, $open . 'Rob' . $close, 'Robert'],
                    'owned closer before outer on same token' => [$prefix, $open . 'Rob' . $close],
                    'owned closer across tokens' => [$prefix, $open . 'Rob', 'Robert' . $close, 'Z'],
                    'surplus owned closer' => [$prefix, $open . 'Rob' . $close . $close, 'Robert'],
                    'later usable closer' => [$prefix . $close . 'X', 'Robert' . $close, 'Z'],
                    'punctuated usable closer' => [$prefix . $close . ';', 'Robert'],
                    'punctuated surplus closer' => [$prefix, $open . 'Rob' . $close . $close . ';', 'Robert'],
                ];
                foreach ($cases as $case => $values) {
                    $tokens = $values;
                    $tokens[0] = $outerOpen . $tokens[0];
                    $tokens[count($tokens) - 1] .= $outerClose;
                    yield $label . ' ' . $enclosing . ' ' . $case => [
                        $delimiters,
                        ['John', ...$tokens, 'Doe'],
                        ['John', ...array_map(static fn(string $value): Nickname => new Nickname($value), $values), 'Doe'],
                    ];
                }
            }
        }
        yield 'later candidate needs its own matchable lookahead' => [[],
            ['John', '([Bob', '{[Rob]X', 'R}', 'R]', 'Z)', 'Doe'],
            ['John', new Nickname('[Bob'), new Nickname('{[Rob]X'), new Nickname('R}'), new Nickname('R]'), new Nickname('Z'), 'Doe']];
        yield 'unusable duplicate closer can recover a different enclosing pair' => [[],
            ['John', '([(Bob)X', 'Rob]', 'Z)', 'Doe'],
            ['John', new Nickname('[(Bob)X'), new Nickname('Rob]'), new Nickname('Z'), 'Doe']];
        yield 'already consumed descendant survives later recovery' => [[],
            ['John', '([(Bob)', '[Rob]', 'X)', 'Doe'],
            ['John', new Nickname('[(Bob)'), new Nickname('[Rob]'), new Nickname('X'), 'Doe']];
        yield 'recover future literal before protected ancestor closer' => [[],
            ['John', '([(Bob', '{<X>X', 'Y}', 'Rob)', 'Z]', 'Q)', 'Doe'],
            ['John', new Nickname('[(Bob'), new Nickname('{<X>X'), new Nickname('Y}'), new Nickname('Rob)'), new Nickname('Z]'), new Nickname('Q'), 'Doe']];
        yield 'skip outer inferred but consume inner inferred' => [[],
            ['John', '([{Bob}X]X', '<Rob>})', 'Doe'],
            ['John', new Nickname('[{Bob}X]X'), new Nickname('<Rob>}'), 'Doe']];
        yield 'skip inner inferred but consume outer inferred' => [[],
            ['John', '([{Bob}X]X', '<Rob>])', 'Doe'],
            ['John', new Nickname('[{Bob}X]X'), new Nickname('<Rob>]'), 'Doe']];
        yield 'punctuation after recovered outer closer' => [[],
            ['John', '([Bob]X', 'Robert);', 'Doe'],
            ['John', new Nickname('[Bob]X'), new Nickname('Robert'), 'Doe']];
        yield 'later balanced mixed descendants' => [[],
            ['John', '([Bob', '[(Rob)', 'Z]]', 'Robert)', 'Doe'],
            ['John', new Nickname('[Bob'), new Nickname('[(Rob)'), new Nickname('Z]]'), new Nickname('Robert'), 'Doe']];
    }

    /**
     * @param array<string, string> $delimiters
     * @param list<string> $input
     * @param list<string|Nickname> $expected
     */
    #[DataProvider('matchableAdjacentCloserProvider')]
    public function testAdjacentOpenersRequireConsumableClosers(array $delimiters, array $input, array $expected): void
    {
        $this->assertSame(
            self::canonicalParts($expected),
            self::canonicalParts((new NicknameMapper($delimiters))->map($input)),
        );
    }

    /**
     * Regression cases: each embedded closer must leave the later suffix for
     * its own enclosing frame, including a later independent nickname.
     *
     * @return iterable<string, array{array<string, string>, list<string>, list<string|Nickname>}>
     */
    public static function embeddedAdjacentCloserProvider(): iterable
    {
        $pairs = [
            'bracket' => ['[', ']'],
            'brace' => ['{', '}'],
            'angle' => ['<', '>'],
            ...iterator_to_array(self::asymmetricDelimiterProvider()),
        ];
        foreach ($pairs as $label => [$open, $close]) {
            foreach ([
                'text between closers' => [$open . 'Bób' . $close . 'X'],
                'comma between closers' => [$open . 'Bób' . $close . ','],
                'future embedded closer' => [$open . 'Bób', 'Rob' . $close . 'X'],
                'token-start closer' => [$open . 'Bób', $close . 'Robert'],
                'outer closer on later token' => [$open . 'Bób' . $close . 'X', 'Robert'],
                'earlier closer owned by an ordinary frame' => [$open . 'Bób', $open . 'Extra' . $close, 'X' . $close . 'Y'],
                'owned closer before a later enclosing token' => [$open . 'Bób', $open . 'Extra' . $close, 'X' . $close . 'Y', 'Robert'],
                'multiple inferred frames advance past owned closer' => [$open . $open . 'Bób', $open . 'Extra' . $close, 'X' . $close . 'Y' . $close . 'Z'],
            ] as $case => $values) {
                $tokens = $values;
                $tokens[0] = $open . $tokens[0];
                $tokens[count($tokens) - 1] .= $close;
                yield $label . ' ' . $case => [
                    [$open => $close],
                    ['John', ...$tokens, $open . 'Rob' . $close, 'Doe'],
                    ['John', ...array_map(static fn(string $value): Nickname => new Nickname($value), $values), new Nickname('Rob'), 'Doe'],
                ];
            }
        }
        yield 'embedded closer under an ordinary enclosing frame' => [[],
            ['John', '[Nick', '((Bob)X)', 'Z]', 'Doe'],
            ['John', new Nickname('Nick'), new Nickname('((Bob)X)'), new Nickname('Z'), 'Doe']];
        yield 'multiple embedded descendants sharing the outer closer' => [[],
            ['John', '([(Bob)X]Y)', '(Rob)', 'Doe'],
            ['John', new Nickname('[(Bob)X]Y'), new Nickname('Rob'), 'Doe']];
        yield 'different custom openers with a shared closer' => [['«' => '界»', '‹' => '界»'],
            ['John', '«‹Bób界»X界»', '«Rob界»', 'Doe'],
            ['John', new Nickname('‹Bób界»X'), new Nickname('Rob'), 'Doe']];
        yield 'punctuation after the outer closer' => [[],
            ['John', '((Bob)X);', '(Rob)', 'Doe'],
            ['John', new Nickname('(Bob)X'), new Nickname('Rob'), 'Doe']];
    }

    /**
     * @param array<string, string> $delimiters
     * @param list<string> $input
     * @param list<string|Nickname> $expected
     */
    #[DataProvider('embeddedAdjacentCloserProvider')]
    public function testEmbeddedAdjacentClosersLeaveTheEnclosingSuffix(array $delimiters, array $input, array $expected): void
    {
        $this->assertSame(
            self::canonicalParts($expected),
            self::canonicalParts((new NicknameMapper($delimiters))->map($input)),
        );
    }

    /**
     * The ordinary inner token owns its closer; it must not reject an earlier
     * adjacent frame that closes after it but before the actual boundary.
     *
     * @return iterable<string, array{array<string, string>, list<string>, list<string|Nickname>}>
     */
    public static function ownedBoundaryCloserProvider(): iterable
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];
        $configurations = [];
        foreach ($pairs as $a => $aClose) {
            foreach ($pairs as $b => $bClose) {
                if ($a !== $b) {
                    $configurations[$a . $b] = [[$a, $aClose], [$b, $bClose]];
                }
            }
        }
        yield 'existing ordinary frame with consumable suffix' => [[],
            ['John', '(Root', '(([(Bob', '[E]', 'X)])X))', '(Rob)', 'Doe'],
            ['John', new Nickname('Root'), new Nickname('(([(Bob'), new Nickname('[E]'), new Nickname('X)])X)'), new Nickname('Rob'), 'Doe']];
        $configurations['multi-character'] = [['<<', '>>'], ['[[', ']]']];
        $configurations['multibyte'] = [['«', '界»'], ['‹', '葉›']];
        $configurations['combining'] = [["e\u{0301}", '⌟'], ['«', '»']];
        foreach ($configurations as $label => [[$a, $aClose], [$b, $bClose]]) {
            $delimiters = [$a => $aClose, $b => $bClose];
            foreach (['joined', 'embedded', 'punctuated owned token'] as $layout) {
                $separator = $layout === 'embedded' ? 'X' : '';
                $owned = $b . 'Extra' . $bClose . ($layout === 'punctuated owned token' ? ';' : '');
                $ending = 'X' . $aClose . $separator . $bClose . $separator . $aClose . 'X';
                yield $label . ' ' . $layout => [$delimiters,
                    ['John', $a . $a . $b . $a . 'Bób', $owned, $ending . $aClose, $a . 'Rob' . $aClose, 'Doe'],
                    ['John', new Nickname($a . $b . $a . 'Bób'), new Nickname($owned), new Nickname($ending), new Nickname('Rob'), 'Doe'],
                ];
            }
        }
    }

    /**
     * @param array<string, string> $delimiters
     * @param list<string> $input
     * @param list<string|Nickname> $expected
     */
    #[DataProvider('ownedBoundaryCloserProvider')]
    public function testAnOwnedCloserDoesNotRejectAnEarlierAdjacentFrame(array $delimiters, array $input, array $expected): void
    {
        $this->assertSame(
            self::canonicalParts($expected),
            self::canonicalParts((new NicknameMapper($delimiters))->map($input)),
        );
    }

    public function testClosingWitnessRequiresConsumableOrdinaryClosers(): void
    {
        $mapper = new NicknameMapper();
        $method = new \ReflectionMethod($mapper, 'hasCompleteClosingSequence');
        $frames = [
            ['open' => '(', 'close' => ')', 'symmetric' => false],
            ['open' => '(', 'close' => ')', 'symmetric' => false],
            ['open' => '[', 'close' => ']', 'symmetric' => false, 'offset' => 1],
        ];
        $this->assertFalse($method->invoke($mapper, $frames, ['(Root', '([Bob', 'X]Y)Z)'], 1, '[Bob', 1));
        $this->assertTrue($method->invoke($mapper, $frames, ['(Root', '([Bob', 'X]Y))'], 1, '[Bob', 1));
    }

    public function testOwnedBoundaryAndRawCloserSearchesHaveSeparateCaches(): void
    {
        $mapper = new NicknameMapper();
        $method = new \ReflectionMethod($mapper, 'nextNestedCloser');
        $parts = ['([Bob', '[Extra]', 'X])'];
        foreach ([false, true, false] as $ignoreOwned) {
            $this->assertSame(
                $ignoreOwned ? [2, 1] : [1, 6],
                $method->invoke($mapper, $parts, 0, '[Bob', ']', [0, 1], $ignoreOwned),
            );
        }
    }

    public function testStandaloneCloserOwnershipRequiresASoleOrdinarySuffix(): void
    {
        $mapper = new NicknameMapper(['[' => ']', '"' => '"', '«' => '界»', '<' => '..']);
        $method = new \ReflectionMethod($mapper, 'isOwnedStandaloneCloser');
        foreach ([
            ['[E]', ']', 2, true],
            ['[E];', ']', 2, true],
            ['[E]X', ']', 2, false],
            ['[[E]]', ']', 3, false],
            ['[[E]', ']', 3, false],
            ['[E]]', ']', 3, false],
            ['"E"', '"', 2, false],
            ['«E界»', '界»', 3, true],
            ['<E...', '..', 2, false],
            ['<E..;', '..', 2, false],
        ] as [$part, $closer, $offset, $expected]) {
            $this->assertSame($expected, $method->invoke($mapper, $part, $closer, $offset), $part);
        }
    }

    /**
     * Compatibility guards for byte offsets, ordinary frames and incomplete
     * identical/shared nesting. Base parity alone is not their oracle.
     *
     * @return iterable<string, array{array<string, string>, list<string>, list<string|Nickname>}>
     */
    public static function embeddedCloserPreservationProvider(): iterable
    {
        yield 'embedded different pair under a symmetric boundary' => [[],
            ['John', '"[Bób]X"', '(Rob)', 'Doe'],
            ['John', new Nickname('[Bób]X'), new Nickname('Rob'), 'Doe']];
        yield 'ordinary frame owns its embedded closer' => [[],
            ['John', '((Bob', '(Rob)X)', 'Doe'],
            ['John', '(Bob', '(Rob)X)', 'Doe']];
        yield 'embedded closer never completes the outer span alone' => [[],
            ['John', '((Bob)X', 'Doe'], ['John', '(Bob)X', 'Doe']];
        yield 'same byte cannot close two inferred frames' => [[],
            ['John', '(((Bob)X)', 'Doe'], ['John', '((Bob)X)', 'Doe']];
        yield 'overlapping multibyte closer cannot also close its parent' => [['«' => '界界'],
            ['John', '««Bób界界界', 'Doe'], ['John', '«Bób界界界', 'Doe']];
        yield 'embedded byte offsets do not consume an overlapping suffix' => [['«' => '界界'],
            ['John', '«««Bób界界X界界界', 'Doe'], ['John', '««Bób界界X界界界', 'Doe']];
        yield 'embedded closer plus later owned closer remains incomplete' => [[],
            ['John', '((Bob)X', 'Doe', '(Bobby)'], ['John', '(Bob)X', 'Doe', '(Bobby)']];
        yield 'owned closer does not complete incomplete triple nesting' => [[],
            ['John', '(((Bob', '(Extra)', 'X)Y)', 'Doe'], ['John', '((Bob', '(Extra)', 'X)Y)', 'Doe']];
        yield 'later nickname cannot complete an earlier mixed span' => [[],
            ['John', '([(Bob', '[E]', 'X)', ']', 'Doe', '(Rob)'],
            ['John', new Nickname('[(Bob'), new Nickname('[E]'), new Nickname('X'), ']', 'Doe', new Nickname('Rob')]];
        yield 'multiple later nicknames cannot donate enclosing closers' => [[],
            ['John', '([(Bob', '[E]', 'X)', ']', 'Doe', '(Rob)', '(Bobby)'],
            ['John', new Nickname('[(Bob'), new Nickname('[E]'), new Nickname('X'), ']', 'Doe', new Nickname('Rob'), new Nickname('Bobby')]];
        yield 'multi-token later nickname cannot donate an enclosing closer' => [[],
            ['John', '([(Bob', '[E]', 'X)', ']', 'Doe', '(Rob', 'Junior)'],
            ['John', new Nickname('[(Bob'), new Nickname('[E]'), new Nickname('X'), ']', 'Doe', new Nickname('Rob'), new Nickname('Junior')]];
        yield 'closing witness rejects an intervening unclosed ordinary frame' => [[],
            ['John', '(([(Bob', '[E]', '(Junior', 'X)])X)', '(Rob)', 'Doe'],
            ['John', '([(Bob', '[E]', '(Junior', 'X)])X)', '(Rob)', 'Doe']];
        yield 'embedded ordinary closer cannot certify a complete witness' => [[],
            ['John', '(Root', '(([(Bob', '[E]', 'X)])X)Y)', '(Rob)', 'Doe'],
            ['John', 'Root', '(([(Bob', '[E]', 'X)])X)Y)', '(Rob)', 'Doe']];
        yield 'existing duplicate closer recovery keeps its depth' => [[],
            ['John', '(([Bob]X', 'Robert)', 'Doe'], ['John', '([Bob]X', 'Robert)', 'Doe']];
    }

    /**
     * @param array<string, string> $delimiters
     * @param list<string> $input
     * @param list<string|Nickname> $expected
     */
    #[DataProvider('embeddedCloserPreservationProvider')]
    public function testEmbeddedCloserPreservation(array $delimiters, array $input, array $expected): void
    {
        $mapper = new NicknameMapper($delimiters);
        $this->assertSame(self::canonicalParts($expected), self::canonicalParts($mapper->map($input)));
        // Index normalization and reuse must not retain positions from a prior call.
        $sparse = array_combine(array_map(static fn(int $i): int => 3 * $i + 2, array_keys($input)), $input);
        $this->assertSame(self::canonicalParts($expected), self::canonicalParts($mapper->map($sparse)));
    }

    public function testRecoveryRetainsConsumableSamePairNesting(): void
    {
        $this->assertSame(
            ['John', '([Bob]X', 'Robert)', 'Doe'],
            (new NicknameMapper())->map(['John', '(([Bob]X', 'Robert)', 'Doe']),
        );
        $this->assertSame(
            self::canonicalParts(['John', new Nickname('([Bob]X'), new Nickname('Robert)'), new Nickname('Z'), 'Doe']),
            self::canonicalParts((new NicknameMapper())->map(['John', '(([Bob]X', 'Robert)', 'Z)', 'Doe'])),
        );
    }

    public function testRecoveryPreservesCustomSharedCloserDepth(): void
    {
        foreach ([
            ['(' => ')', '[' => ')', '{' => '}'],
            ['«' => '界»', '‹' => '界»', '[' => ']'],
        ] as $delimiters) {
            $openers = array_keys($delimiters);
            $closers = array_values($delimiters);
            $prefix = implode('', $openers) . 'Bob' . $closers[2] . 'X';
            $inner = $openers[1] . $openers[2] . 'Bob' . $closers[2] . 'X';
            $this->assertSame(
                ['John', $inner, 'Robert' . $closers[1], 'Doe'],
                (new NicknameMapper($delimiters))->map(['John', $prefix, 'Robert' . $closers[1], 'Doe']),
            );
            $this->assertSame(
                self::canonicalParts(['John', new Nickname($inner), new Nickname('Robert' . $closers[1]), new Nickname('Z'), 'Doe']),
                self::canonicalParts((new NicknameMapper($delimiters))->map(['John', $prefix, 'Robert' . $closers[1], 'Z' . $closers[0], 'Doe'])),
            );
        }
    }

    public function testRecoveryDoesNotTrimPunctuationAfterASymmetricCloser(): void
    {
        $mapper = new NicknameMapper();
        $method = new \ReflectionMethod($mapper, 'closerCountSkippingAdjacent');
        $stack = [
            ['open' => '"', 'close' => '"', 'symmetric' => true],
            ['open' => '(', 'close' => ')', 'symmetric' => false, 'adjacent' => true],
            ['open' => '[', 'close' => ']', 'symmetric' => false, 'adjacent' => true],
        ];
        $this->assertSame(0, $method->invoke($mapper, 'Bob"', $stack, true));
        $this->assertSame(3, $method->invoke($mapper, 'Bob"', $stack, false));
    }

    public function testRecoveryDeduplicatesOverlappingLongCloserOffsets(): void
    {
        $stack = [];
        $suffix = '';
        for ($i = 0; $i < 63; ++$i) {
            // 31 distinct inferred pairs plus one mandatory pair fit the
            // configured-pair limit, including a maximum-length closer.
            $length = $i % 31 === 30 ? 64 : 2 * ($i % 31 + 1);
            $closer = str_repeat('X', $length);
            $stack[] = ['open' => 'o' . ($i % 31), 'close' => $closer, 'symmetric' => false, 'adjacent' => true];
            $suffix .= $closer;
        }
        $stack[] = ['open' => 'z', 'close' => str_repeat('Y', 64), 'symmetric' => false];
        $mapper = new NicknameMapper();
        $method = new \ReflectionMethod($mapper, 'closerCountSkippingAdjacent');
        $this->assertSame(0, $method->invoke($mapper, $suffix, $stack, false));
        $this->assertSame(64, $method->invoke($mapper, str_repeat('Y', 64) . $suffix, $stack, false));
    }

    public function testRecoveryCannotDiscardAnOrdinaryTokenOpener(): void
    {
        $this->assertSame(
            ['John', '[Bob]X', '{Rob', 'Robert}X)', 'Doe'],
            (new NicknameMapper())->map(['John', '([Bob]X', '{Rob', 'Robert}X)', 'Doe']),
        );
        $this->assertSame(
            ['John', '[Bob', '[Rob', 'Robert)', 'Doe'],
            (new NicknameMapper())->map(['John', '([Bob', '[Rob', 'Robert)', 'Doe']),
        );
        $this->assertSame(
            ['John', '[Bob]X', '{Rob', 'Robert)', 'Doe'],
            (new NicknameMapper())->map(['John', '([Bob]X', '{Rob', 'Robert)', 'Doe']),
        );
    }

    public function testCloserRecoveryDoesNotRetainPriorCallsOrSparseKeys(): void
    {
        $mapper = new NicknameMapper();
        foreach ([
            [2 => 'John', 8 => '([Bob', 12 => '[Rob]', 20 => 'Robert)', 100 => 'Doe'],
            [1 => 'John', 9 => '([Bob', 11 => '[Rob]]', 25 => 'Robert)', 30 => 'Doe'],
            [2 => 'John', 8 => '([Bob', 12 => '[Rob]', 20 => 'Robert)', 100 => 'Doe'],
        ] as $parts) {
            $this->assertSame(
                self::canonicalParts((new NicknameMapper())->map($parts)),
                self::canonicalParts($mapper->map($parts)),
            );
        }
    }

    public function testEnclosingBoundaryAcceptsSparsePartKeys(): void
    {
        $this->assertSame(
            self::canonicalParts(['John', new Nickname('[Bob'), 'Doe', new Nickname('Bobby')]),
            self::canonicalParts((new NicknameMapper())->map([10 => 'John', 40 => '([Bob)', 70 => 'Doe', 100 => '[Bobby]'])),
        );
    }

    public function testForwardCloserCacheAdvancesAcrossSpansAndResetsBetweenCalls(): void
    {
        $mapper = new NicknameMapper();
        $this->assertSame(['John', '(Bob', 'Smith'], $mapper->map(['John', '((Bob', 'Smith']));
        $this->assertSame(
            self::canonicalParts(['John', new Nickname('(Bob'), new Nickname('Robert)'), new Nickname('Z'), 'Doe']),
            self::canonicalParts($mapper->map(['John', '((Bob', 'Robert)', 'Z)', 'Doe'])),
        );
        foreach ([
            [
                ['John', '([Bob', 'Robert])', '([Rob)', 'Doe', '[Robert]'],
                ['John', new Nickname('[Bob'), new Nickname('Robert]'), new Nickname('[Rob'), 'Doe', new Nickname('Robert')],
            ],
            [['John', '((Bob', 'Smith'], ['John', '(Bob', 'Smith']],
            [
                ['John', '((Bob', 'Robert)', 'Z)', 'Doe'],
                ['John', new Nickname('(Bob'), new Nickname('Robert)'), new Nickname('Z'), 'Doe'],
            ],
        ] as [$input, $expected]) {
            $this->assertSame(self::canonicalParts($expected), self::canonicalParts($mapper->map($input)));
        }
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
