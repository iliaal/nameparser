<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Suffix;
use PHPUnit\Framework\Attributes\DataProvider;

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

        $mapped = $mapper->map(['LAc']);
        $this->assertCount(1, $mapped);
        $this->assertInstanceOf(Suffix::class, $mapped[0]);
        $this->assertSame('LAc', $mapped[0]->getValue());
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function symmetricDelimiterProvider(): array
    {
        return [
            'default parentheses' => [['(' => ')']],
            'custom symmetric delimiter' => [['%%' => '%%']],
        ];
    }

    /**
     * @param  array<string, string>  $delimiters
     */
    #[DataProvider('symmetricDelimiterProvider')]
    public function testInternalApostropheDoesNotOpenSymmetricNicknameSpan(array $delimiters): void
    {
        $mapper = new SuffixMapper((new English())->getSuffixes(), false, 2, $delimiters);

        $mapped = $mapper->map(['John', "D'Angelo", "MD'"]);

        $this->assertSame('John', $mapped[0]);
        $this->assertSame("D'Angelo", $mapped[1]);
        $this->assertInstanceOf(Suffix::class, $mapped[2]);
        $this->assertSame("MD'", $mapped[2]->getValue());
    }

    public function testStandaloneSymmetricDelimiterDoesNotHideLaterNicknameTail(): void
    {
        $mapper = new SuffixMapper(
            (new English())->getSuffixes(),
            false,
            2,
            ["'" => "'"],
        );

        $this->assertSame(
            ['John', "'", "MD'"],
            $mapper->map(['John', "'", "MD'"]),
        );
    }

    public function testBalancedStandaloneDelimiterRunDoesNotOpenNicknameSpan(): void
    {
        $mapper = new SuffixMapper(
            (new English())->getSuffixes(),
            false,
            2,
            ["'" => "'"],
        );

        $mapped = $mapper->map(['John', "''", "MD'"]);

        $this->assertSame('John', $mapped[0]);
        $this->assertSame("''", $mapped[1]);
        $this->assertInstanceOf(Suffix::class, $mapped[2]);
        $this->assertSame("MD'", $mapped[2]->getValue());
    }

    public function testOverlappingSymmetricDelimiterRunsDoNotConsumeNicknameTail(): void
    {
        $mapper = new SuffixMapper(
            (new English())->getSuffixes(),
            false,
            2,
            ['"' => '"'],
        );

        $this->assertSame(
            ['John', '"""', 'MD""'],
            $mapper->map(['John', '"""', 'MD""']),
        );
    }
}
