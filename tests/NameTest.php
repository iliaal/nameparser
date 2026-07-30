<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\LastnamePrefix;
use Iliaal\NameParser\Part\Middlename;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\SalutationConnector;
use Iliaal\NameParser\Part\Suffix;
use PHPUnit\Framework\TestCase;

class NameTest extends TestCase
{
    public function testToString(): void
    {
        $parts = [
            new Salutation('Mr', 'Mr.'),
            new Firstname('James'),
            new Middlename('Morgan'),
            new Nickname('Jim'),
            new Initial('T.'),
            new Lastname('Smith'),
            new Suffix('I', 'I'),
        ];

        $name = new Name($parts);

        $this->assertSame($parts, $name->getParts());
        $this->assertSame('Mr. James (Jim) Morgan T. Smith I', (string) $name);
    }

    public function testGetNickname(): void
    {
        $name = new Name([
            new Nickname('Jim'),
        ]);

        $this->assertSame('Jim', $name->getNickname());
        $this->assertSame('(Jim)', $name->getNickname(true));
    }

    public function testGettingLastnameAndLastnamePrefixSeparately(): void
    {
        $name = new Name([
            new Firstname('Frank'),
            new LastnamePrefix('van'),
            new Lastname('Delft'),
        ]);

        $this->assertSame('Frank', $name->getFirstname());
        $this->assertSame('van', $name->getLastnamePrefix());
        $this->assertSame('Delft', $name->getLastname(true));
        $this->assertSame('van Delft', $name->getLastname());
    }

    public function testPureLastnameIncludesConsumerSubclass(): void
    {
        $lastname = new class ('Smith') extends Lastname {};
        $name = new Name([$lastname]);

        $this->assertSame('Smith', $name->getLastname(true));
    }

    public function testGetAllRetainsPartNormalizingToStringZero(): void
    {
        $name = new Name([
            new Firstname('John'),
            new Lastname('0'),
        ]);

        $all = $name->getAll();

        $this->assertArrayHasKey('lastname', $all);
        $this->assertSame('0', $all['lastname']);
        $this->assertSame('John 0', (string) $name);
    }

    public function testGetSourceReturnsNullWhenNoSourceRecorded(): void
    {
        $name = new Name([new Firstname('John')]);

        $this->assertNull($name->getSource());
    }

    public function testGetSourceReturnsRecordedSource(): void
    {
        $name = (new Name([new Firstname('John')]))->setSource('john doe');

        $this->assertSame('john doe', $name->getSource());
    }

    public function testUnmatchedSalutationConnectorDoesNotReportJointName(): void
    {
        $name = new Name([
            new SalutationConnector('and', 'and'),
            new Salutation('Mrs', 'Mrs.'),
            new Lastname('Smith'),
        ]);

        $this->assertFalse($name->isJoint());
        $this->assertSame(['Mrs.'], $name->getSalutations());
        $this->assertNull($name->getPartner());
    }

    public function testGetGivenNameShouldReturnGivenNameInGivenOrder(): void
    {
        $parser = new Parser();
        $name = $parser->parse('Schuler, J. Peter M.');
        $this->assertSame('J. Peter M.', $name->getGivenName());
    }

    public function testGetFullNameShouldReturnTheFullNameInGivenOrder(): void
    {
        $parser = new Parser();
        $name = $parser->parse('Schuler, J. Peter M.');
        $this->assertSame('J. Peter M. Schuler', $name->getFullName());
    }

    public function testSubclassIsTypeOverrideIsHonoredByGetters(): void
    {
        $name = new class ([new Firstname('John'), new Lastname('Smith')]) extends Name {
            #[\Override]
            protected function isType(AbstractPart $part, string $type, bool $strict = false): bool
            {
                if ($type === 'Lastname') {
                    return false;
                }

                return parent::isType($part, $type, $strict);
            }
        };

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('', $name->getLastname());
    }
}
