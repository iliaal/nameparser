<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\LastnamePrefix;
use Iliaal\NameParser\Part\Salutation;

class LastnameMapperTest extends AbstractMapperTestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function provider(): array
    {
        return [
            [
                'input' => [
                    'Peter',
                    'Pan',
                ],
                'expectation' => [
                    'Peter',
                    new Lastname('Pan'),
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Peter',
                    'Pan',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Peter',
                    new Lastname('Pan'),
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    new Firstname('Peter'),
                    'Pan',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    new Firstname('Peter'),
                    new Lastname('Pan'),
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Lars',
                    'van',
                    'Trier',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Lars',
                    new LastnamePrefix('van'),
                    new Lastname('Trier'),
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Dan',
                    'Huong',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Dan',
                    new Lastname('Huong'),
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Von',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    new Lastname('Von'),
                ],
            ],
            [
                'input' => [
                    'Mr',
                    'Von',
                ],
                'expectation' => [
                    'Mr',
                    new Lastname('Von'),
                ],
            ],
            [
                'input' => [
                    'Kirk',
                ],
                'expectation' => [
                    'Kirk',
                ],
            ],
            [
                'input' => [
                    'Kirk',
                ],
                'expectation' => [
                    new Lastname('Kirk'),
                ],
                'arguments' => [
                    true,
                ],
            ],
        ];
    }

    protected function getMapper(bool $matchSingle = false): LastnameMapper
    {
        $english = new English();

        return new LastnameMapper($english->getLastnamePrefixes(), $matchSingle);
    }
}
