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
            ],            [
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
        ];
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
