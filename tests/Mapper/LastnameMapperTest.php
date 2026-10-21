<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\GivenNamePart;
use Iliaal\NameParser\Part\Ignored;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\LastnamePrefix;
use Iliaal\NameParser\Part\Middlename;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\Suffix;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /**
     * @return iterable<string, array{AbstractPart, bool}>
     */
    public static function precedingPartProvider(): iterable
    {
        yield 'initial' => [new Initial('J.'), true];
        yield 'firstname' => [new Firstname('John'), true];
        yield 'middlename' => [new Middlename('Robert'), true];
        yield 'custom given name' => [new class ('Jean') extends GivenNamePart {}, true];
        yield 'empty initial' => [new Initial(''), false];
        yield 'empty firstname' => [new Firstname(''), false];
        yield 'salutation' => [new Salutation('Dr.'), false];
        yield 'nickname' => [new Nickname('Jack'), false];
        yield 'suffix' => [new Suffix('MD'), false];
        yield 'ignored' => [new Ignored('and'), false];
    }

    #[DataProvider('precedingPartProvider')]
    public function testMappedGivenNamesAllowFollowingSurnameParticle(AbstractPart $previous, bool $binds): void
    {
        $parts = $this->getMapper()->map([$previous, 'van', 'Gogh']);

        $this->assertSame($previous, $parts[0]);
        $this->assertSame($binds, $parts[1] instanceof LastnamePrefix);
        $this->assertSame(
            self::canonicalParts([$previous, $binds ? new LastnamePrefix('van', 'van') : 'van', new Lastname('Gogh')]),
            self::canonicalParts($parts),
        );
    }

    protected function getMapper(bool $matchSingle = false): LastnameMapper
    {
        $english = new English();

        return new LastnameMapper($english->getLastnamePrefixes(), $matchSingle);
    }
}
