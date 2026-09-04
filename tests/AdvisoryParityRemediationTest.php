<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use Iliaal\NameParser\Mapper\AbstractMapper;
use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Mapper\MiddlenameMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Text;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Behavior pins for the advisory/parity remediation beads (Phase 1).
 */
class AdvisoryParityRemediationTest extends TestCase
{
    // np-cr-002: prefix opener-presence table keeps the span-tail scan linear
    public function testSuffixSpanTailScanRemainsLinearOnHostileRow(): void
    {
        $tokens = array_fill(0, 20000, 'MD)');

        $start = microtime(true);
        $mapped = (new SuffixMapper(['md' => 'MD'], false, 2, ['(' => ')']))->map($tokens);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(10.0, $elapsed);
        // every token keys as a suffix and passes the strpbrk prefilter, so
        // all but the reserved head still map
        $this->assertNotSame([], $mapped);
    }

    public function testSuffixSpanTailStillProtectsNicknameSpans(): void
    {
        $mapped = (new SuffixMapper(['jr' => 'Jr'], false, 2, ['(' => ')']))
            ->map(['John', '(Bob', 'Jr)', 'MD']);

        $this->assertSame('(Bob', $mapped[1]);
        $this->assertSame('Jr)', $mapped[2]);
    }

    public function testHostileSuffixRowParsesLinearly(): void
    {
        $input = trim(str_repeat('MD) ', 15000));

        $start = microtime(true);
        $name = (new Parser())->parse($input);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(15.0, $elapsed);
        $this->assertNotSame('', $name->getSuffix());
    }

    // np-cr-004: delimiters + whitespace stored on Name and threaded into Confidence
    public function testCustomDelimitersChangeConfidenceVerdict(): void
    {
        $withCustom = Confidence::assess('Lord «Doc» Smith', null, null, null, ['«' => '»']);
        $withDefault = Confidence::assess('Lord «Doc» Smith');

        $this->assertTrue($withCustom['ambiguous']);
        $this->assertFalse($withDefault['ambiguous']);
    }

    public function testCustomWhitespaceChangesConfidenceVerdict(): void
    {
        $withCustom = Confidence::assess('Lord_Smith', null, null, null, null, '_');
        $withDefault = Confidence::assess('Lord_Smith');

        $this->assertTrue($withCustom['ambiguous']);
        $this->assertFalse($withDefault['ambiguous']);
    }

    public function testNameForwardsStoredConfigToConfidence(): void
    {
        $expected = Confidence::assess('Lord «Doc» Smith', null, null, null, ['«' => '»']);

        $name = new Name(null, null, null, ['«' => '»']);
        $name->setSource('Lord «Doc» Smith', ['Lord', '«Doc»', 'Smith']);

        $this->assertSame($expected, $name->getConfidence());
        $this->assertSame(['«' => '»'], $name->getConfidenceNicknameDelimiters());
        $this->assertNull($name->getConfidenceWhitespace());

        $name->setConfidenceWhitespace('_');
        $this->assertSame('_', $name->getConfidenceWhitespace());

        $name->setConfidenceNicknameDelimiters(null);
        $this->assertNull($name->getConfidenceNicknameDelimiters());
    }

    // np-cr-008: resync preserves non-default mapper flags
    public function testResyncPreservesMapperFlags(): void
    {
        $parser = new Parser();
        $mappers = $parser->getMappers();

        $lastname = $this->findMapper($mappers, LastnameMapper::class);
        $middle = $this->findMapper($mappers, MiddlenameMapper::class);
        $salutation = $this->findMapper($mappers, SalutationMapper::class);

        $this->setProtected($lastname, 'matchSinglePart', true);
        $this->setProtected($lastname, 'surnameOnly', true);
        $this->setProtected($middle, 'mapWithoutLastname', true);
        $this->setProtected($salutation, 'requireRemainder', true);

        $parser->setMappers($mappers);
        $parser->setMaxSalutationIndex(5);

        $resynced = $parser->getMappers();
        $newLastname = $this->findMapper($resynced, LastnameMapper::class);
        $this->assertTrue($newLastname->matchesSinglePart());
        $this->assertTrue($newLastname->isSurnameOnly());
        $newMiddle = $this->findMapper($resynced, MiddlenameMapper::class);
        $newSalutation = $this->findMapper($resynced, SalutationMapper::class);

        $this->assertTrue($newMiddle->mapsWithoutLastname());
        $this->assertTrue($newSalutation->requiresRemainder());

        // surname-only + single-part flags stay live after the resync: the
        // whole row maps as the surname and parsing still works
        $this->assertSame('John Smith', $parser->parse('John Smith')->getLastname());
    }

    // np-cr-013: partner shares the source-backed confidence input
    public function testPartnerReportsSourceBackedConfidence(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');
        $partner = $name->getPartner();

        $this->assertNotNull($partner);
        $this->assertSame($name->getSource(), $partner->getSource());
        $this->assertSame($name->getConfidence(), $partner->getConfidence());
    }

    public function testManualNamePartnerKeepsReconstructionFallback(): void
    {
        $manual = new Name([
            new \Iliaal\NameParser\Part\Salutation('Mr.', 'Mr.'),
            new \Iliaal\NameParser\Part\SalutationConnector('and', 'and'),
            new \Iliaal\NameParser\Part\Salutation('Mrs.', 'Mrs.'),
            new Lastname('Smith'),
        ]);

        $partner = $manual->getPartner();

        $this->assertNotNull($partner);
        $this->assertNull($partner->getSource());
    }

    // np-cr-019: doc pin — default connectors join with " and "
    public function testDefaultJoinReproducesSalutation(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');

        $this->assertSame($name->getSalutation(), implode(' and ', $name->getSalutations()));
    }

    // np-cr-022: static dispatch map behaves like the old string dispatch
    public function testGetAllMatchesIndividualGetters(): void
    {
        $name = (new Parser())->parse('Mr. John (Bob) Smith Jr');

        $all = $name->getAll();

        $this->assertSame($name->getSalutation(), $all['salutation'] ?? null);
        $this->assertSame($name->getFirstname(), $all['firstname'] ?? null);
        $this->assertSame($name->getNickname(), $all['nickname'] ?? null);
        $this->assertSame($name->getLastname(), $all['lastname'] ?? null);
        $this->assertSame($name->getSuffix(), $all['suffix'] ?? null);
        $this->assertSame($name->getNickname(true), $name->getAll(true)['nickname'] ?? null);
    }

    // np-cr-023: centralized reset + shared factory (minimal path)
    public function testUniformUpperOverridesResetCentrally(): void
    {
        $suffix = new SuffixMapper(['md' => 'MD'], false, 2);
        $initial = new InitialMapper();

        $suffix->setUniformUpperOverride(true);
        $initial->setUniformUpperOverride(false);

        AbstractMapper::resetUniformUpperOverrides([$suffix, $initial]);

        $this->assertNull($this->getProtected($suffix, 'uniformUpperOverride'));
        $this->assertNull($this->getProtected($initial, 'uniformUpperOverride'));
    }

    public function testDecorationFactoryMatchesManualConstruction(): void
    {
        $tokens = ['John', '(Bob)', 'Smith', 'MD'];

        ['suffix' => $suffixMapper, 'nickname' => $nicknameMapper] = AbstractMapper::decorationAnalyzers(
            ['md' => 'MD'],
            ['(' => ')'],
        );

        $this->assertInstanceOf(SuffixMapper::class, $suffixMapper);
        $this->assertInstanceOf(NicknameMapper::class, $nicknameMapper);
        $this->assertSame(
            $this->stringifyParts(
                (new SuffixMapper(['md' => 'MD'], true, 0, ['(' => ')']))
                    ->map((new NicknameMapper(['(' => ')']))->map($tokens)),
            ),
            $this->stringifyParts($suffixMapper->map($nicknameMapper->map($tokens))),
        );
    }

    // np-cr-027: Text-routed case gates; digit-only and caseless tokens stay names
    public function testDigitOnlyTokenStaysAName(): void
    {
        $mapped = (new InitialMapper())->map(['123', 'Smith']);

        $this->assertSame('123', $mapped[0]);
        $this->assertFalse(Text::isUniformUpperTokens(['123']));
    }

    public function testCaselessTokenIsNotSplit(): void
    {
        $mapped = (new InitialMapper())->map(['李明', 'Smith']);

        $this->assertSame('李明', $mapped[0]);
        $this->assertFalse(Text::isUniformUpperTokens(['李明']));
    }

    public function testCombinedInitialsStillSplit(): void
    {
        $mapped = (new InitialMapper())->map(['JM', 'Smith']);

        $this->assertInstanceOf(Initial::class, $mapped[0]);
        $this->assertInstanceOf(Initial::class, $mapped[1]);
    }

    public function testCamelcaseLeavesDigitOnlyValuesAlone(): void
    {
        $this->assertSame('123', (new Firstname('123'))->normalize());
        $this->assertSame('McDonald', (new Firstname('McDonald'))->normalize());
    }

    public function testUniformUpperHelperSemantics(): void
    {
        $this->assertTrue(Text::isUniformUpperTokens(['JOHN', 'SMITH']));
        $this->assertFalse(Text::isUniformUpperTokens(['John', 'SMITH']));
        $this->assertFalse(Text::isUniformUpperTokens(['123']));
        $this->assertFalse(Text::isUniformUpperTokens([]));
        $this->assertTrue(Text::isUniformUpperTokens(['123', 'AB']));
    }

    // np-o-01: single-pass name-bearing segment scan
    public function testHostileConfidenceRemainsLinear(): void
    {
        $input = 'Lord John, ' . rtrim(str_repeat('MD,', 20000), ',');

        $start = microtime(true);
        $result = Confidence::assess($input);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(10.0, $elapsed);
        $this->assertTrue($result['ambiguous']);
    }

    public function testDecidingCommaStillDetectedInSinglePass(): void
    {
        // both comma segments carry names, so the comma decides and no
        // salutation-collision note is emitted
        $this->assertFalse(Confidence::assess('Lord, Jane')['ambiguous']);
    }

    // np-o-05: lazily-reused analyzer pair + indexed multi-word patterns
    public function testSalutationRemainderAnalysisIsReusable(): void
    {
        $mapper = new SalutationMapper(
            ['mr.' => 'Mr.', 'mrs.' => 'Mrs.', 'lord' => 'Lord', 'the honorable' => 'Hon.'],
            0,
            false,
            ['md' => 'MD'],
            ['(' => ')'],
        );

        $input = ['Mr.', 'and', 'Mrs.', 'Lord', 'MD'];
        $first = $this->stringifyParts($mapper->map($input));
        $second = $this->stringifyParts($mapper->map($input));

        $this->assertSame($first, $second);
    }

    public function testMultiWordSalutationStillMatches(): void
    {
        $mapper = new SalutationMapper(['the honorable' => 'Hon.']);

        $mapped = $mapper->map(['the', 'honorable', 'John', 'Smith']);

        $this->assertInstanceOf(\Iliaal\NameParser\Part\Salutation::class, $mapped[0]);
    }

    /**
     * @param  array<int, object>  $mappers
     * @template T of object
     * @param  class-string<T>  $class
     * @return T
     */
    private function findMapper(array $mappers, string $class): object
    {
        foreach ($mappers as $mapper) {
            if ($mapper instanceof $class) {
                return $mapper;
            }
        }

        $this->fail('Mapper ' . $class . ' not found in pipeline');
    }

    private function setProtected(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    private function getProtected(object $object, string $property): mixed
    {
        $ref = new ReflectionProperty($object, $property);

        return $ref->getValue($object);
    }

    /**
     * @param  array<int, object|string>  $parts
     */
    private function stringifyParts(array $parts): string
    {
        return implode('|', array_map(
            static function (object|string $part): string {
                if ($part instanceof AbstractPart) {
                    return $part::class . ':' . $part->normalize();
                }

                if (is_string($part)) {
                    return 'raw:' . $part;
                }

                return 'other:' . $part::class;
            },
            $parts,
        ));
    }
}
