<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Suffix;

class SuffixMapperTest extends AbstractMapperTestCase
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
                    'James',
                    'Blueberg',
                    'PhD',
                ],
                'expectation' => [
                    'Mr.',
                    'James',
                    'Blueberg',
                    new Suffix('PhD'),
                ],
                'arguments' => [
                    'matchSinglePart' => false,
                    'reservedParts' => 2,
                ],
            ],
            [
                'input' => [
                    'Prince',
                    'Alfred',
                    'III',
                ],
                'expectation' => [
                    'Prince',
                    'Alfred',
                    new Suffix('III'),
                ],
                'arguments' => [
                    'matchSinglePart' => false,
                    'reservedParts' => 2,
                ],
            ],
            [
                'input' => [
                    new Firstname('Paul'),
                    new Lastname('Smith'),
                    'Senior',
                ],
                'expectation' => [
                    new Firstname('Paul'),
                    new Lastname('Smith'),
                    new Suffix('Senior'),
                ],
                'arguments' => [
                    'matchSinglePart' => false,
                    'reservedParts' => 2,
                ],
            ],
            [
                'input' => [
                    'Senior',
                    new Firstname('James'),
                    'Norrington',
                ],
                'expectation' => [
                    'Senior',
                    new Firstname('James'),
                    'Norrington',
                ],
                'arguments' => [
                    'matchSinglePart' => false,
                    'reservedParts' => 2,
                ],
            ],
            [
                'input' => [
                    'Senior',
                    new Firstname('James'),
                    new Lastname('Norrington'),
                ],
                'expectation' => [
                    'Senior',
                    new Firstname('James'),
                    new Lastname('Norrington'),
                ],
                'arguments' => [
                    'matchSinglePart' => false,
                    'reservedParts' => 2,
                ],
            ],
            [
                'input' => [
                    'James',
                    'Norrington',
                    'Senior',
                ],
                'expectation' => [
                    'James',
                    'Norrington',
                    new Suffix('Senior'),
                ],
                'arguments' => [
                    false,
                    2,
                ],
            ],
            [
                'input' => [
                    'Norrington',
                    'Senior',
                ],
                'expectation' => [
                    'Norrington',
                    'Senior',
                ],
                'arguments' => [
                    false,
                    2,
                ],
            ],
            [
                'input' => [
                    new Lastname('Norrington'),
                    'Senior',
                ],
                'expectation' => [
                    new Lastname('Norrington'),
                    new Suffix('Senior'),
                ],
                'arguments' => [
                    false,
                    1,
                ],
            ],
            [
                'input' => [
                    'Senior',
                ],
                'expectation' => [
                    new Suffix('Senior'),
                ],
                'arguments' => [
                    true,
                ],
            ],
        ];
    }

    protected function getMapper(bool $matchSinglePart = false, int $reservedParts = 2): SuffixMapper
    {
        $english = new English();

        return new SuffixMapper($english->getSuffixes(), $matchSinglePart, $reservedParts);
    }

    public function testAmbiguousCredentialDecisionUsesProtectedUppercaseHook(): void
    {
        $mapper = new class (['do' => 'Doctor of Osteopathy'], true, 0) extends SuffixMapper {
            public bool $uppercaseChecked = false;

            protected function isUpperCase(string $part): bool
            {
                $this->uppercaseChecked = true;

                return false;
            }
        };

        $this->assertSame(['DO'], $mapper->map(['DO']));
        $this->assertTrue($mapper->uppercaseChecked);
    }

    public function testCanonicalMixedCaseCredentialDoesNotDependOnUppercaseHook(): void
    {
        $mapper = new class (['lac' => 'LAc'], true, 0) extends SuffixMapper {
            protected function isUpperCase(string $part): bool
            {
                return false;
            }
        };

        // canonical class + value descriptor: assertEquals on part objects
        // hides type drift behind loose comparison.
        $mapped = $mapper->map(['LAc']);
        $this->assertCount(1, $mapped);
        $this->assertInstanceOf(Suffix::class, $mapped[0]);
        $this->assertSame('LAc', $mapped[0]->getValue());
    }
}
