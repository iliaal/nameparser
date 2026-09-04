<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\CommaCredentialTail;
use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\GivenNamePart;
use Iliaal\NameParser\Part\Ignored;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\LastnamePrefix;
use Iliaal\NameParser\Part\Middlename;
use Iliaal\NameParser\Part\MiddlenamePrefix;
use Iliaal\NameParser\Part\NamePart;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\PreNormalizedPart;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\SalutationConnector;
use Iliaal\NameParser\Part\Suffix;
use Iliaal\NameParser\SegmentParserFactory;
use Iliaal\NameParser\StructuralCommaSplitter;
use PHPUnit\Framework\TestCase;

/**
 * structural remediation pins (np-cr-024, np-cr-026): the Part taxonomy keeps
 * every class name importable with its instanceof lattice intact, the partner
 * keeps clone (not shared-reference) semantics, and the three Parser
 * extractions route identically to the inline code they replace.
 */
class StructuralRemediationTest extends TestCase
{
    /**
     * the partner's parts are clones: no object is shared with the source
     * name, so writing through one side cannot reach the other
     */
    public function testPartnerPartsAreClones(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');
        $partner = $name->getPartner();

        $this->assertNotNull($partner);

        $sourceParts = $name->getParts();
        $partnerParts = $partner->getParts();

        $this->assertNotEmpty($partnerParts);

        foreach ($partnerParts as $partnerPart) {
            foreach ($sourceParts as $sourcePart) {
                $this->assertNotSame($sourcePart, $partnerPart);
            }
        }
    }

    /**
     * the partner derives from the same parse, so it shares the source
     */
    public function testPartnerSharesSource(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');
        $partner = $name->getPartner();

        $this->assertNotNull($partner);
        $this->assertSame($name->getSource(), $partner->getSource());
    }

    /**
     * np-cr-024: every Part class stays importable and keeps its instanceof
     * lineage, so external instanceof checks survive the taxonomy cleanup
     */
    public function testPartTaxonomyLattice(): void
    {
        $this->assertInstanceOf(GivenNamePart::class, new Firstname('John'));
        $this->assertInstanceOf(NamePart::class, new Firstname('John'));
        $this->assertInstanceOf(AbstractPart::class, new Firstname('John'));
        $this->assertNotInstanceOf(Lastname::class, new Firstname('John'));

        $this->assertInstanceOf(GivenNamePart::class, new Middlename('Robert'));
        $this->assertInstanceOf(GivenNamePart::class, new Initial('J'));
        $this->assertInstanceOf(NamePart::class, new Nickname('Bob'));
        $this->assertInstanceOf(NamePart::class, new Lastname('Smith'));

        $this->assertInstanceOf(Lastname::class, new LastnamePrefix('van', 'van'));
        $this->assertInstanceOf(Middlename::class, new MiddlenamePrefix('del', 'del'));

        $this->assertInstanceOf(PreNormalizedPart::class, new Salutation('Mr.', 'Mr.'));
        $this->assertInstanceOf(PreNormalizedPart::class, new Suffix('Jr', 'Jr'));
        $this->assertInstanceOf(Salutation::class, new SalutationConnector('and', 'and'));

        $this->assertInstanceOf(AbstractPart::class, new Ignored('and'));
        $this->assertNotInstanceOf(NamePart::class, new Ignored('and'));
        $this->assertNotInstanceOf(PreNormalizedPart::class, new Ignored('and'));
    }

    /**
     * np-cr-024: one pre-normalized mechanism — the dictionary form fixed at
     * map time renders verbatim, for the base-class line and the
     * trait-direct particle prefixes alike
     */
    public function testPreNormalizedRendersDictionaryForm(): void
    {
        $this->assertSame('van', (new LastnamePrefix('VAN', 'van'))->normalize());
        $this->assertSame('del', (new MiddlenamePrefix('DEL', 'del'))->normalize());
        $this->assertSame('Mr.', (new Salutation('mr.', 'Mr.'))->normalize());
        $this->assertSame('PhD', (new Suffix('PHD', 'PhD'))->normalize());
    }

    /**
     * np-cr-024: camelcased parts still derive their rendering at render time
     */
    public function testCamelcasedPartsNormalize(): void
    {
        $this->assertSame('John', (new Firstname('john'))->normalize());
        $this->assertSame('Smith', (new Lastname('smith'))->normalize());
        $this->assertSame('J', (new Initial('j'))->normalize());
        $this->assertSame('and', (new Ignored('and'))->normalize());
    }

    /**
     * np-cr-026: the splitter shields nickname commas and bisects the rest,
     * exactly as the inline Parser code did
     */
    public function testStructuralSplitterEquivalence(): void
    {
        $this->assertSame(['a', ' b'], StructuralCommaSplitter::split('a, b', []));

        $masked = StructuralCommaSplitter::mask('John (Bob, Jr) Doe', ['(' => ')']);
        $this->assertNotSame('John (Bob, Jr) Doe', $masked);
        $this->assertSame('John (Bob, Jr) Doe', str_replace("\x00", ',', $masked));

        $this->assertSame('a, b', StructuralCommaSplitter::mask('a, b', []));
    }

    /**
     * np-cr-026: comma routing through the extracted collaborators parses as
     * before — nickname commas stay structural-shielded, credential tails
     * classify, surname-first still peels
     */
    public function testCommaRoutingEquivalence(): void
    {
        $parser = new Parser();

        $folded = $parser->parse('Smith, John, Robert');
        $this->assertSame('John Robert Smith', (string) $folded);

        $shielded = $parser->parse('John (Bob, Jr) Doe');
        $this->assertSame('John (Bob, Jr) Doe', (string) $shielded);
        $this->assertSame('Doe', $shielded->getLastname());

        $credential = $parser->parse('Christina Nemec, LMHP');
        $this->assertSame('Nemec', $credential->getLastname());
        $this->assertSame('LMHP', $credential->getSuffix());

        $anchored = $parser->parse('Smith, John MD, FACS');
        $this->assertSame('MD FACS', $anchored->getSuffix());

        $surnameFirst = (new Parser())->setSurnameFirst(true)->parse('Mao Zedong');
        $this->assertSame('Mao', $surnameFirst->getLastname());
        $this->assertSame('Zedong', $surnameFirst->getFirstname());
    }

    /**
     * np-cr-026: the factory builds the stock pipeline Parser used inline —
     * a factory-built parser parses identically to the default one
     */
    public function testSegmentFactoryBuildsWorkingPipeline(): void
    {
        $parser = new Parser();
        $mappers = SegmentParserFactory::newDefaultPipeline(
            false,
            $parser->getSalutations(),
            $parser->getMaxSalutationIndex(),
            $parser->getSuffixes(),
            $parser->getNicknameDelimiters(),
            $parser->getConnectors(),
            $parser->getMaxCombinedInitials(),
            $parser->getLastnamePrefixes(),
        );

        $this->assertNotEmpty($mappers);

        $direct = (new Parser())->setMappers($mappers)->parse('John Robert Smith');
        $this->assertSame((string) (new Parser())->parse('John Robert Smith'), (string) $direct);
    }

    /**
     * np-cr-026: the tail classifier answers through its wired dependencies
     * (live dictionary, memoized candidate test, mapper ride)
     */
    public function testCredentialTailClassifier(): void
    {
        $parser = new Parser();
        $tail = new CommaCredentialTail(
            $parser->getSuffixes(),
            static fn(string $token): bool => $token === 'LMHP',
            static fn(array $tokens, bool $uniform): array => $tokens,
        );

        $this->assertTrue($tail->isUnknownTail(['LMHP']));
        $this->assertFalse($tail->isUnknownTail(['John']));

        $credited = $tail->creditParts(['LMHP']);
        $this->assertCount(1, $credited);
        $this->assertInstanceOf(Suffix::class, $credited[0]);
    }

    /**
     * a manually constructed joint Name still yields an independent partner
     */
    public function testManualNamePartnerIsIndependent(): void
    {
        $manual = new Name([
            new Salutation('Mr.', 'Mr.'),
            new SalutationConnector('and', 'and'),
            new Salutation('Mrs.', 'Mrs.'),
            new Lastname('Smith'),
        ]);

        $partner = $manual->getPartner();

        $this->assertNotNull($partner);
        $this->assertSame('Mrs. Smith', (string) $partner);

        foreach ($partner->getParts() as $part) {
            if ($part instanceof Lastname) {
                $part->setValue('Jones');
            }
        }

        $this->assertSame('Jones', $partner->getLastname());
        $this->assertSame('Smith', $manual->getLastname());
    }
}
