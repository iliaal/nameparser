<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Salutation;

class SalutationMapperTest extends AbstractMapperTestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function provider(): array
    {
        return [
            [
                'input' => [
                    'Mr.',
                    'Pan',
                ],
                'expectation' => [
                    new Salutation('Mr.', 'Mr.'),
                    'Pan',
                ],
            ],
            [
                'input' => [
                    'Mr',
                    'Peter',
                    'Pan',
                ],
                'expectation' => [
                    new Salutation('Mr', 'Mr.'),
                    'Peter',
                    'Pan',
                ],
            ],
            [
                'input' => [
                    'Mr',
                    new Firstname('James'),
                    'Miss',
                ],
                'expectation' => [
                    new Salutation('Mr', 'Mr.'),
                    new Firstname('James'),
                    'Miss',
                ],
            ],
        ];
    }

    protected function getMapper(): SalutationMapper
    {
        $english = new English();

        return new SalutationMapper($english->getSalutations());
    }
}
