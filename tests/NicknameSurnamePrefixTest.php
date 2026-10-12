<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Nickname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NicknameSurnamePrefixTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function surnameRows(): iterable
    {
        yield 'bare surname' => ['van (Bob) der Berg', '', 'Bob', ''];
        yield 'multiword nickname' => ['van (Bobby Joe) der Berg', '', 'Bobby Joe', ''];
        yield 'multiple nicknames' => ['van (Bob) (Rob) der Berg', '', 'Bob Rob', ''];
        yield 'salutation' => ['Mr. van (Bob) der Berg', '', 'Bob', ''];
        yield 'credential' => ['van (Bob) der Berg MD', '', 'Bob', 'MD'];
        yield 'empty comma tail' => ['van (Bob) der Berg,', '', 'Bob', ''];
        yield 'credential comma tail' => ['van (Bob) der Berg, MD', '', 'Bob', 'MD'];
        yield 'unknown comma tail' => ['van (Bob) der Berg, XYZ', 'Xyz', 'Bob', ''];
        yield 'generational comma tail' => ['van (Bob) der Berg, Jr', 'Jr', 'Bob', ''];
        yield 'given name' => ['John van (Bob) der Berg', 'John', 'Bob', ''];
        yield 'explicit surname segment' => ['van (Bob) der Berg, John', 'John', 'Bob', ''];
        yield 'no nickname' => ['van der Berg', '', '', ''];
    }

    #[DataProvider('surnameRows')]
    public function testSurnameParticlesBindAcrossNicknames(string $input, string $first, string $nickname, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname());
        $this->assertSame('van der Berg', $name->getLastname());
        $this->assertSame('van der', $name->getLastnamePrefix());
        $this->assertSame($nickname, $name->getNickname());
        $this->assertSame($suffix, $name->getSuffix());
        $this->assertSame('', $name->getMiddlename());
    }

    public function testDirectMapperPreservesNicknameObjects(): void
    {
        $nickname = new Nickname('Bob');
        $mapper = new LastnameMapper((new English())->getLastnamePrefixes());
        $parts = $mapper->map(['van', $nickname, 'der', 'Berg']);
        $name = new Name($parts);

        $this->assertSame('van der Berg', $name->getLastname());
        $this->assertSame($nickname, $parts[1]);
    }

    public function testPromotedMapperPipelineKeepsNicknameAwareBinding(): void
    {
        $parser = new Parser();
        $parser->setMappers($parser->getMappers())->setNicknameDelimiters(['<<' => '>>']);
        $name = $parser->parse('van <<Bob>> der Berg');

        $this->assertSame('', $name->getFirstname());
        $this->assertSame('van der Berg', $name->getLastname());
        $this->assertSame('Bob', $name->getNickname());
    }

    public function testJointNamePartnerKeepsWholeSurname(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. van (Bob) der Berg');
        $partner = $name->getPartner();

        $this->assertNotNull($partner);
        $this->assertSame('van der Berg', $partner->getLastname());
        $this->assertSame('', $name->getFirstname());
        $this->assertSame('Mrs.', $partner->getSalutation());
    }

    public function testSingleParticleBeforeNicknameStillAllowsGivenName(): void
    {
        $name = (new Parser())->parse('Della (Bob) Smith');

        $this->assertSame('Della', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getLastnamePrefix());
        $this->assertSame('Bob', $name->getNickname());
    }
}
