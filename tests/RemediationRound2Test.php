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

class RemediationRound2Test extends TestCase
{
    public function testCanonicalPartsPinsDictionaryRendering(): void
    {
        $method = new ReflectionMethod(AbstractMapperTestCase::class, 'canonicalParts');

        $canonical = $method->invoke(null, [new Suffix('PHD', 'PhD')]);
        $drifted = $method->invoke(null, [new Suffix('PHD', 'PHD')]);

        $this->assertNotSame($canonical, $drifted);
    }

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

    // Nested parse() calls must restore the outer tail even after exceptions.
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

    public function testUniformGateSharesTokenMemo(): void
    {
        $parser = new Parser();
        $gate = new ReflectionMethod(Parser::class, 'isUniformUpperInput');

        $this->assertTrue($gate->invoke($parser, 'AB CD AB CD'));

        $memo = (new ReflectionProperty(Parser::class, 'tokenAnalysisMemo'))->getValue($parser);
        $this->assertIsArray($memo);
        $this->assertSame(['AB', 'CD'], array_keys($memo));
    }

    public function testTokenAnalysisMemoIsCapped(): void
    {
        $parser = new Parser();
        $cap = (new ReflectionClass(Parser::class))->getConstant('MAX_TOKEN_ANALYSIS_ENTRIES');
        $this->assertIsInt($cap);

        $analyze = new ReflectionMethod(Parser::class, 'analyzeToken');

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

    public function testTokenMemoClearedAfterParse(): void
    {
        $parser = new Parser();
        $parser->parse('Smith, John MD, FACS');

        $memo = (new ReflectionProperty(Parser::class, 'tokenAnalysisMemo'))->getValue($parser);
        $this->assertSame([], $memo);
    }

    // A stale uniform-uppercase override would suppress the DJ initial split.
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

        // Keep both construction sites tied to factory defaults.
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
