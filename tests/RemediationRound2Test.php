<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\CommaCredentialTail;
use Iliaal\NameParser\Mapper\FirstnameMapper;
use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Suffix;
use Iliaal\NameParser\SegmentParserFactory;
use Iliaal\NameParser\Text;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Iliaal\NameParser\Mapper\AbstractMapperTestCase;

/**
 * Remediation pins for the round-2 review beads (full-review 20260903).
 * Each test names its bead.
 */
class RemediationRound2Test extends TestCase
{
    // np-r2-01: canonicalParts includes normalize(), so a dictionary
    // rendering drift (same class + raw value, different rendered form)
    // yields different descriptors and fails mapper unit tests
    public function testCanonicalPartsPinsDictionaryRendering(): void
    {
        $method = new ReflectionMethod(AbstractMapperTestCase::class, 'canonicalParts');
        $method->setAccessible(true);

        $canonical = $method->invoke(null, [new Suffix('PHD', 'PhD')]);
        $drifted = $method->invoke(null, [new Suffix('PHD', 'PHD')]);

        $this->assertNotSame($canonical, $drifted);
    }

    // np-r2-02: a subclass hook routing another input through
    // parseSplitName() first must not consume the outer stashed tail
    public function testReentrantSplitHookKeepsOuterTail(): void
    {
        $parser = new class extends Parser {
            public ?Name $diverted = null;

            private bool $reentered = false;

            protected function parseSplitName(string $surname, string $given): Name
            {
                if (! $this->reentered) {
                    $this->reentered = true;
                    $this->diverted = parent::parseSplitName('Doe', 'Jane, MD');
                }

                return parent::parseSplitName($surname, $given);
            }
        };

        $outer = $parser->parse('Smith, John, PhD');

        $this->assertNotNull($parser->diverted);
        $this->assertSame((string) (new Parser())->parse('Doe, Jane, MD'), (string) $parser->diverted);
        $this->assertSame((string) (new Parser())->parse('Smith, John, PhD'), (string) $outer);
    }

    // np-r3-01: a re-entrant $this->parse() inside a parseSplitName
    // override pushes and pops its own stash entry: the hook only peeks,
    // so the outer tail is still on top when the inner parse returns,
    // at depth>1 and on the exception path.
    public function testReentrantParseInsideHookKeepsStackBalanced(): void
    {
        $parser = new class extends Parser {
            /**
             * @var list<string>
             */
            public array $givens = [];

            /**
             * @var list<mixed>
             */
            public array $headsBefore = [];

            /**
             * @var array<int, mixed>
             */
            public array $headsAfter = [];

            /**
             * @var list<Name>
             */
            public array $diverted = [];

            private int $dives = 0;

            protected function parseSplitName(string $surname, string $given): Name
            {
                if ($this->dives < 2) {
                    ++$this->dives;

                    try {
                        $stack = (new ReflectionProperty(Parser::class, 'preSplitTailStack'))->getValue($this);
                        \PHPUnit\Framework\Assert::assertIsArray($stack);
                        $at = count($this->headsBefore);
                        $this->givens[] = $given;
                        $this->headsBefore[] = end($stack);
                        $this->diverted[] = $this->parse('Doe, Jane, MD');
                        $stack = (new ReflectionProperty(Parser::class, 'preSplitTailStack'))->getValue($this);
                        \PHPUnit\Framework\Assert::assertIsArray($stack);
                        $this->headsAfter[$at] = end($stack);
                    } finally {
                        --$this->dives;
                    }
                }

                return parent::parseSplitName($surname, $given);
            }
        };

        $outer = $parser->parse('Smith, John, PhD');

        $this->assertSame((string) (new Parser())->parse('Smith, John, PhD'), (string) $outer);
        $this->assertCount(2, $parser->diverted);

        foreach ($parser->diverted as $name) {
            $this->assertSame((string) (new Parser())->parse('Doe, Jane, MD'), (string) $name);
        }

        // every diving level saw its own tail restored: the inner parse
        // left the outer entry untouched on top of the stack
        $this->assertSame([' John, PhD', ' Jane, MD'], $parser->givens);
        $this->assertCount(2, $parser->headsBefore);
        $this->assertCount(2, $parser->headsAfter);

        foreach ($parser->headsAfter as $i => $after) {
            $this->assertSame($parser->headsBefore[$i], $after);
            $this->assertIsArray($after);
            $this->assertSame($parser->givens[$i], $after[0]);
        }

        $stack = (new ReflectionProperty(Parser::class, 'preSplitTailStack'))->getValue($parser);
        $this->assertSame([], $stack);

        // exception path: a throw out of the hook still unwinds the stash
        // and leaves the parser reusable
        $throwing = new class extends Parser {
            private bool $reentered = false;

            private bool $detonated = false;

            protected function parseSplitName(string $surname, string $given): Name
            {
                if (! $this->reentered) {
                    $this->reentered = true;

                    try {
                        $this->parse('Doe, Jane, MD');
                    } finally {
                        $this->reentered = false;
                    }

                    if (! $this->detonated) {
                        $this->detonated = true;

                        throw new \RuntimeException('hook boom');
                    }
                }

                return parent::parseSplitName($surname, $given);
            }
        };

        try {
            $throwing->parse('Smith, John, PhD');
            $this->fail('expected hook exception');
        } catch (\RuntimeException) {
        }

        $stack = (new ReflectionProperty(Parser::class, 'preSplitTailStack'))->getValue($throwing);
        $this->assertSame([], $stack);
        $this->assertSame(
            (string) (new Parser())->parse('Smith, John, PhD'),
            (string) $throwing->parse('Smith, John, PhD'),
        );
    }

    // np-r2-03: resyncing a promoted default list through the factory
    // element builders yields the factory-built pipeline and parses
    // identically to a fresh parser with the same config
    public function testResyncedMappersMatchFactoryBuilders(): void
    {
        $promoted = new Parser();
        $promoted->setMappers($promoted->getMappers());
        $promoted->setMaxSalutationIndex(2);
        $promoted->setMaxCombinedInitials(1);

        $expected = SegmentParserFactory::newDefaultPipeline(
            false,
            $promoted->getSalutations(),
            $promoted->getMaxSalutationIndex(),
            $promoted->getSuffixes(),
            $promoted->getNicknameDelimiters(),
            $promoted->getConnectors(),
            $promoted->getMaxCombinedInitials(),
            $promoted->getLastnamePrefixes(),
        );
        $actual = $promoted->getMappers();

        $this->assertSame(count($expected), count($actual));

        foreach ($expected as $i => $mapper) {
            $this->assertSame($mapper::class, $actual[$i]::class);
            $this->assertEquals($mapper, $actual[$i]);
        }

        $fresh = (new Parser())
            ->setMaxSalutationIndex(2)
            ->setMaxCombinedInitials(1);

        foreach (['John Robert Smith', 'Smith, John Robert', 'Francis Mr', 'DJ Westbam', 'Smith, John MD, FACS'] as $name) {
            $this->assertSame((string) $fresh->parse($name), (string) $promoted->parse($name), $name);
        }
    }

    // np-r2-04: isUnknownTail uses the injected per-parse memoized
    // candidate and rider tests (same definition as the split scan)
    public function testUnknownTailUsesInjectedCandidateAndRiderTests(): void
    {
        $parser = new Parser();
        $candidateCalls = [];
        $riderCalls = [];
        $tail = new CommaCredentialTail(
            $parser->getSuffixes(),
            function (string $token) use (&$candidateCalls): bool {
                $candidateCalls[] = $token;

                return Text::isUnknownCredentialCandidate($token);
            },
            static fn(array $tokens, bool $uniform): array => $tokens,
            function (string $token) use (&$riderCalls): bool {
                $riderCalls[] = $token;

                return Text::isCredentialTailRider($token);
            },
        );

        $this->assertTrue($tail->isUnknownTail(['LMHP', 'D']));
        $this->assertContains('LMHP', $candidateCalls);
        $this->assertContains('D', $riderCalls);
        $this->assertFalse($tail->isUnknownTail(['John']));
    }

    // np-r2-05: the uniform-uppercase gate shares the per-parse token
    // memo instead of re-scanning every token
    public function testUniformGateSharesTokenMemo(): void
    {
        $parser = new Parser();
        $gate = new ReflectionMethod(Parser::class, 'isUniformUpperInput');
        $gate->setAccessible(true);

        $this->assertTrue($gate->invoke($parser, 'AB CD AB CD'));

        $memo = (new ReflectionProperty(Parser::class, 'tokenAnalysisMemo'))->getValue($parser);
        $this->assertIsArray($memo);
        $this->assertSame(['AB', 'CD'], array_keys($memo));
    }

    // np-r2-05: the per-parse token memo is capped; past the cap tokens
    // are still analyzed correctly without retaining entries
    public function testTokenAnalysisMemoIsCapped(): void
    {
        $parser = new Parser();
        $cap = (new ReflectionClass(Parser::class))->getConstant('MAX_TOKEN_ANALYSIS_ENTRIES');
        $this->assertIsInt($cap);

        $analyze = new ReflectionMethod(Parser::class, 'analyzeToken');
        $analyze->setAccessible(true);

        $last = '';

        for ($i = 0; $i < $cap + 500; $i++) {
            $last = 'Q' . $i . 'X';
            $analyze->invoke($parser, $last);
        }

        $memo = (new ReflectionProperty(Parser::class, 'tokenAnalysisMemo'))->getValue($parser);
        $this->assertIsArray($memo);
        $this->assertLessThanOrEqual($cap, count($memo));
        $this->assertEquals(Text::analyzeToken($last), $analyze->invoke($parser, $last));
        $this->assertEquals(Text::analyzeToken('Q0X'), $analyze->invoke($parser, 'Q0X'));
    }

    // np-r2-05: the per-parse token memo is dropped at the end of
    // parse(), so a hostile row does not pin entries between parses
    public function testTokenMemoClearedAfterParse(): void
    {
        $parser = new Parser();
        $parser->parse('Smith, John MD, FACS');

        $memo = (new ReflectionProperty(Parser::class, 'tokenAnalysisMemo'))->getValue($parser);
        $this->assertSame([], $memo);
    }

    // np-r2-06: parse() entry resets the sticky casing overrides through
    // the shared helper, so a stale override cannot leak into the next parse
    // (a stale uniform-upper `true` would suppress the DJ split and yield no
    // initials instead of the stock 'D J' split)
    public function testParseEntryResetsUniformUpperOverrides(): void
    {
        $parser = new Parser();

        foreach ($parser->getMappers() as $mapper) {
            if ($mapper instanceof InitialMapper || $mapper instanceof SuffixMapper) {
                $mapper->setUniformUpperOverride(true);
            }
        }

        $this->assertSame('J', $parser->parse('DJ Westbam')->getInitials());
        $this->assertSame((string) (new Parser())->parse('DJ Westbam'), (string) $parser->parse('DJ Westbam'));
    }

    // np-r2-07: the firstname stage comes from the factory element
    // builder in both the default pipeline and the second-segment parser
    public function testFirstnameBuilderFeedsBothPipelines(): void
    {
        $this->assertInstanceOf(FirstnameMapper::class, SegmentParserFactory::newFirstnameMapper());

        $parser = new Parser();
        $pipeline = SegmentParserFactory::newDefaultPipeline(
            false,
            $parser->getSalutations(),
            $parser->getMaxSalutationIndex(),
            $parser->getSuffixes(),
            $parser->getNicknameDelimiters(),
            $parser->getConnectors(),
            $parser->getMaxCombinedInitials(),
            $parser->getLastnamePrefixes(),
        );
        $classes = array_map(static fn(object $mapper): string => $mapper::class, $pipeline);
        $this->assertContains(FirstnameMapper::class, $classes);

        // Parser::getMappers() routes through the factory default pipeline,
        // so its firstname stage equals a factory-built element
        $defaultMappers = $parser->getMappers();
        $defaultClasses = array_map(static fn(object $mapper): string => $mapper::class, $defaultMappers);
        $this->assertContains(FirstnameMapper::class, $defaultClasses);
        $this->assertEquals(SegmentParserFactory::newFirstnameMapper(), self::findFirstnameMapper($defaultMappers));

        $parser->parse('Smith, John');
        $secondSegment = (new ReflectionProperty(Parser::class, 'secondSegmentParser'))->getValue($parser);
        $this->assertInstanceOf(Parser::class, $secondSegment);
        $secondMappers = $secondSegment->getMappers();
        $secondClasses = array_map(static fn(object $mapper): string => $mapper::class, $secondMappers);
        $this->assertContains(FirstnameMapper::class, $secondClasses);
        $this->assertEquals(SegmentParserFactory::newFirstnameMapper(), self::findFirstnameMapper($secondMappers));

        // both Parser construction sites route through the factory element
        // builder, so a factory default change cannot leave an inline site
        // stale (np-r3-02): reverting either site to `new FirstnameMapper()`
        // fails this pin.
        $file = (new ReflectionClass(Parser::class))->getFileName();
        $this->assertIsString($file);
        $src = (string) file_get_contents($file);
        $this->assertSame(2, substr_count($src, 'SegmentParserFactory::newFirstnameMapper()'));
        $this->assertStringNotContainsString('new FirstnameMapper(', $src);
    }

    /**
     * @param array<int, object> $mappers
     */
    private static function findFirstnameMapper(array $mappers): FirstnameMapper
    {
        foreach ($mappers as $mapper) {
            if ($mapper instanceof FirstnameMapper) {
                return $mapper;
            }
        }

        self::fail('no FirstnameMapper stage in pipeline');
    }
}
