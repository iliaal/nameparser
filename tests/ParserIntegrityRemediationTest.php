<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Mapper\FirstnameMapper;
use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Mapper\MiddlenameMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Text;
use Iliaal\NameParser\TokenCredentialClass;
use PHPUnit\Framework\TestCase;

/**
 * Remediation pins for the Parser/Text Phase-1 beads: each test names its
 * bead. Behavior that an existing test pins (CR-014 purge scope) is asserted
 * as documented, not as changed; the STOP is recorded on the bead itself.
 */
class ParserIntegrityRemediationTest extends TestCase
{
    // np-cr-001: an ASCII-separated hostile row is counted by the budget and
    // rejected instead of flowing into the pipeline
    public function testSpaceSeparatedRowHitsTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('AB ', 70000) . 'Smith');
    }

    // np-cr-001: multibyte Unicode spaces (NBSP) ride inside counted tokens,
    // so the byte budget cannot see them; the capped preg_split in
    // isUniformUpperInput bounds the real split cost instead (200k-token row
    // completes — the uncapped split would materialize all 200k tokens with
    // three Unicode scans each)
    public function testNbspSeparatedRowStaysBounded(): void
    {
        $name = (new Parser())->parse(str_repeat("AB\xC2\xA0", 200000) . ', Smith');

        $this->assertSame('Smith', $name->getFirstname());
        $this->assertStringStartsWith('Ab', $name->getLastname());
    }

    // np-cr-001 + np-cr-003: ASCII control separators (VT) are stripped by
    // normalize before the budget runs, so they read as one token
    public function testVerticalTabIsStrippedByNormalize(): void
    {
        $name = (new Parser())->parse("AB\x0BAB");

        $this->assertSame('Abab', $name->getFirstname());
    }

    // np-cr-007 carve-out: an invalid-UTF-8 whitespace set keeps the legacy
    // bytewise contract instead of destroying configured separators
    public function testInvalidUtf8WhitespaceKeepsBytewiseContract(): void
    {
        $name = (new Parser())->setWhitespace("\xFF")->parse("John\xFFSmith");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    // np-cr-011: the cheaper mask path shields identically (asymmetric,
    // symmetric, nested)
    public function testMaskingShieldsNicknameCommas(): void
    {
        $name = (new Parser())->parse('John (Bob, Jr) Doe');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Bob, Jr', $name->getNickname());
        $this->assertSame('Doe', $name->getLastname());

        $quoted = (new Parser())->parse("John 'Bob, Boy' Doe");

        $this->assertSame('John', $quoted->getFirstname());
        $this->assertSame('Doe', $quoted->getLastname());
    }

    // np-cr-012: hostile delimiter pairs (comma, NUL, whitespace, controls)
    // are ignored, so the structural comma split survives them
    public function testHostileDelimiterPairsAreIgnored(): void
    {
        $parser = (new Parser())->setNicknameDelimiters([
            ',' => ',',
            "a\x00b" => ')',
            'a b' => ')',
            "a\tb" => ')',
            '(' => ')',
        ]);

        $this->assertSame(['(' => ')'], Text::sanitizeNicknameDelimiters([
            ',' => ',',
            "a\x00b" => ')',
            'a b' => ')',
            '(' => ')',
        ]));

        $name = $parser->parse('Smith, John');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    // np-cr-007: SCRUB policy, invalid bytes are replaced deterministically
    // (mb_scrub substitutes '?'), so the row parses instead of degrading per
    // call site
    public function testInvalidUtf8IsScrubbedDeterministically(): void
    {
        $name = (new Parser())->parse("John\xFFSmith");

        $this->assertSame('John?Smith', $name->getFirstname());
        $this->assertTrue(mb_check_encoding($name->getFirstname(), 'UTF-8'));
    }

    public function testCommaCredentialNoiseDropsAreAccounted(): void
    {
        $name = (new Parser())->parse('Smith, Jane, -, MD');

        $this->assertSame('Jane', $name->getFirstname());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());

        foreach ($name->getParts() as $part) {
            $this->assertNotSame('-', is_string($part) ? $part : $part->getValue());
        }
        // the attested placeholder set is a named predicate (np-o-04)
        $this->assertTrue(Text::isCredentialPlaceholder('Unknown'));
        $this->assertFalse(Text::isCredentialPlaceholder('-'));
        $this->assertFalse(Text::isCredentialPlaceholder('John'));
    }

    // np-cr-014 acceptance (documented otherwise): the surname segment never
    // enters the given-side purge, so Unknown stays a lastname here
    public function testSurnameUnknownSurvivesCommaCredential(): void
    {
        $name = (new Parser())->parse('John Unknown, MD');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Unknown', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    // np-cr-015: past the 4096-entry table the key cache degrades gradually
    // (oldest quarter evicted) instead of dropping wholesale, and stays exact
    public function testKeyCacheStaysExactPastEviction(): void
    {
        for ($i = 0; $i < 5000; $i++) {
            Text::key('token-' . $i . '.');
        }

        $this->assertSame('john', Text::key('John.'));
        $this->assertSame('token-0', Text::key('token-0.'));
        $this->assertSame('token-4999', Text::key('token-4999.'));

        $long = str_repeat('A', 100);

        $this->assertSame(Text::key($long), Text::key($long));

        Text::clearCache();

        $this->assertSame('john', Text::key('John.'));
    }

    // np-cr-016: a max-size interior token classifies identically (memoized
    // single analysis, same observable outcome)
    public function testLongTokenClassifiesIdentically(): void
    {
        $name = (new Parser())->parse(str_repeat('A', 2000));

        $this->assertSame(2000, strlen($name->getFirstname()));
        $this->assertSame('', $name->getLastname());
    }

    // np-cr-018: jr/sr promote exactly like junior/senior
    public function testGenerationalAbbreviationsPromote(): void
    {
        foreach (['Smith, Jr', 'Smith, Sr'] as $input) {
            $name = (new Parser())->parse($input);

            $this->assertSame('Smith', $name->getLastname(), "last for '$input'");
            $this->assertSame('', $name->getSuffix(), "suffix for '$input'");
            $this->assertNotSame('', $name->getFirstname(), "first for '$input'");
        }

        $this->assertSame('Jr', (new Parser())->parse('Smith, Jr')->getFirstname());
        $this->assertSame('Sr', (new Parser())->parse('Smith, Sr')->getFirstname());
        $this->assertSame('Junior', (new Parser())->parse('Smith, Junior')->getFirstname());

        // a side that already carries a given name keeps the token as suffix
        $withGiven = (new Parser())->parse('Smith, John Jr');

        $this->assertSame('John', $withGiven->getFirstname());
        $this->assertSame('Jr', $withGiven->getSuffix());
    }

    // np-cr-025: the credential classification is a named enum, not magic ints
    public function testTokenCredentialClassValues(): void
    {
        $this->assertSame(0, TokenCredentialClass::Name->value);
        $this->assertSame(1, TokenCredentialClass::DictionaryCredential->value);
        $this->assertSame(2, TokenCredentialClass::UnknownCandidate->value);
    }

    // np-o-02: invalid UTF-8 plus comma plus opener splits deterministically
    // (unmasked) instead of shielding/exposing the wrong comma
    public function testInvalidUtf8CommaRowSplitsDeterministically(): void
    {
        $name = (new Parser())->setWhitespace("\xFF")->parse("John\xFF(Bob, Jr");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Bob', $name->getLastname());
        $this->assertSame('Jr', $name->getSuffix());
    }

    // np-o-03: NULs are stripped, preserving the placeholder invariant
    public function testNulBytesAreStripped(): void
    {
        $name = (new Parser())->parse("Jo\x00hn Smith");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    // np-o-04: drops stay distinguishable in getParts() (noise gone with an
    // anchor, preserved without one)
    public function testNoiseAccountingInParts(): void
    {
        $anchored = (new Parser())->parse('Smith, Jane, MD, -');
        $values = array_map(
            static fn($part): string => is_string($part) ? $part : $part->getValue(),
            $anchored->getParts(),
        );

        $this->assertNotContains('-', $values);
        $this->assertSame('MD', $anchored->getSuffix());

        $unanchored = (new Parser())->parse('Smith, Jane, -');
        $unanchoredValues = array_map(
            static fn($part): string => is_string($part) ? $part : $part->getValue(),
            $unanchored->getParts(),
        );

        $this->assertContains('-', $unanchoredValues);
    }

    // np-o-13: all four pipeline sites build from the same factories, so the
    // single-segment pipeline has exactly the default stage sequence
    public function testDefaultPipelineStageSequence(): void
    {
        $classes = array_map(
            static fn($mapper): string => $mapper::class,
            (new Parser())->getMappers(),
        );

        $this->assertSame(
            [
                SalutationMapper::class,
                SuffixMapper::class,
                NicknameMapper::class,
                SuffixMapper::class,
                InitialMapper::class,
                LastnameMapper::class,
                FirstnameMapper::class,
                MiddlenameMapper::class,
            ],
            $classes,
        );
    }

    // np-o-14: a custom list applies to the single-segment path only; the
    // comma path keeps the centralized default segment behavior
    public function testCustomMappersDoNotAffectCommaPath(): void
    {
        $parser = (new Parser())->setMappers([
            new FirstnameMapper(),
            new LastnameMapper([]),
        ]);

        $comma = $parser->parse('Smith, John MD');

        $this->assertSame('MD', $comma->getSuffix());
        $this->assertSame('John', $comma->getFirstname());
        $this->assertSame('Smith', $comma->getLastname());

        $plain = $parser->parse('John Smith MD');

        $this->assertSame('', $plain->getSuffix());
    }

    // np-cr-017: a >4KB row keeps its nickname shielding (bounded byte scan,
    // no unshielded-split fallback on the single-byte path)
    public function testLongRowKeepsNicknameShielding(): void
    {
        $name = (new Parser())->parse('Smith, John ' . str_repeat('x', 5000) . ' (Bob, Jr)');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Bob, Jr', $name->getNickname());
        $this->assertSame('', $name->getSuffix());
        $this->assertStringStartsWith('John', $name->getFirstname());
    }

    // np-cr-027: whole-input uniform-upper helper semantics
    public function testIsUniformUpperTokens(): void
    {
        $this->assertTrue(Text::isUniformUpperTokens(['JOHN', 'DOE-2']));
        $this->assertTrue(Text::isUniformUpperTokens(['JOHN', '中文']));
        $this->assertTrue(Text::isUniformUpperTokens(['DOE', '123']));
        $this->assertFalse(Text::isUniformUpperTokens(['John', 'DOE']));
        $this->assertFalse(Text::isUniformUpperTokens(['123', '---']));
        $this->assertFalse(Text::isUniformUpperTokens([]));
        $this->assertFalse(Text::isUniformUpperTokens(['中文']));
    }

    // np-cr-027 wiring: mixed-case input is not uniform-upper at parse level
    public function testUniformUpperGateAtParseLevel(): void
    {
        $upper = (new Parser())->parse('JOHN SMITH');
        $mixed = (new Parser())->parse('John Smith');

        $this->assertSame($upper->getFirstname(), $mixed->getFirstname());
        $this->assertSame($upper->getLastname(), $mixed->getLastname());
    }
}
