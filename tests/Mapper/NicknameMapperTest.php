<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;

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
            // a leading quote with no closing quote later is an elided particle,
            // not a nickname opener: leave the token verbatim
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
            // lone delimiter tokens clean to empty and must not emit empty Nicknames
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
