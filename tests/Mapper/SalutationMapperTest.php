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
                ],
                'expectation' => [
                    new Salutation('Mr', 'Mr.'),
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

    public function testOverlappingCustomSalutationsMatchLongestFirst(): void
    {
        $mapper = new SalutationMapper([
            'chief' => 'Chief',
            'chief medical' => 'Chief Medical',
            'chief medical officer' => 'Chief Medical Officer',
        ]);

        $this->assertEquals(
            [
                new Salutation('Chief Medical Officer', 'Chief Medical Officer'),
                'Jane',
                'Doe',
            ],
            $mapper->map(['Chief', 'Medical', 'Officer', 'Jane', 'Doe']),
        );
        $this->assertEquals(
            [
                new Salutation('Chief Medical', 'Chief Medical'),
                'Jane',
                'Doe',
            ],
            $mapper->map(['Chief', 'Medical', 'Jane', 'Doe']),
        );
        $this->assertEquals(
            [new Salutation('Chief', 'Chief'), 'Jane', 'Doe'],
            $mapper->map(['Chief', 'Jane', 'Doe']),
        );
    }
}
