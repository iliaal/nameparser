<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\StructuralCommaSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommaNestingLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function asymmetricPairs(): iterable
    {
        yield 'ASCII scan' => ['(', ')', ['(' => ')']];
        yield 'unused Unicode pair' => ['(', ')', ['(' => ')', '«' => '»']];
        yield 'Unicode pair' => ['«', '»', ['«' => '»']];
        yield 'longest ASCII opener' => ['<<', '>>', ['<' => '>', '<<' => '>>']];
        yield 'combining-mark pair' => ["e\u{0301}[", "]e\u{0301}", ["e\u{0301}[" => "]e\u{0301}"]];
        yield 'closer also opens another pair' => ['(', ')', ['(' => ')', ')' => '»']];
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, int}>
     */
    public static function nestingCases(): iterable
    {
        foreach (self::asymmetricPairs() as $label => [$open, $close, $delimiters]) {
            foreach ([127, 128, 129, 130] as $depth) {
                yield $label . ' depth ' . $depth => [$open, $close, $delimiters, $depth];
            }
        }
    }

    /**
     * @param  array<string, string>  $delimiters
     */
    #[DataProvider('nestingCases')]
    public function testNestingLimit(string $open, string $close, array $delimiters, int $depth): void
    {
        $left = str_repeat($open, $depth) . 'a' . str_repeat($close, $depth - 1);
        $right = 'b' . $close;
        $input = $left . ',' . $right;
        $shielded = $depth <= 128;

        $this->assertSame(
            $left . ($shielded ? StructuralCommaSplitter::COMMA_PLACEHOLDER : ',') . $right,
            StructuralCommaSplitter::mask($input, $delimiters),
        );
        $this->assertSame(
            $shielded ? [$input] : [$left, $right],
            StructuralCommaSplitter::split($input, $delimiters),
        );
    }

    /**
     * @param  array<string, string>  $delimiters
     */
    #[DataProvider('asymmetricPairs')]
    public function testOverflowOpenerRemainsInsideTrackedSpan(string $open, string $close, array $delimiters): void
    {
        $input = str_repeat($open, 129) . 'a,b' . str_repeat($close, 128);

        $this->assertSame(str_replace(',', StructuralCommaSplitter::COMMA_PLACEHOLDER, $input), StructuralCommaSplitter::mask($input, $delimiters));
        $this->assertSame([$input], StructuralCommaSplitter::split($input, $delimiters));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function symmetricPairs(): iterable
    {
        yield 'ASCII scan' => ['"', ['(' => ')', '"' => '"']];
        yield 'unused Unicode pair' => ['"', ['(' => ')', '"' => '"', '«' => '»']];
        yield 'multi-character quote' => ['~~', ['(' => ')', '~~' => '~~']];
        yield 'Unicode quote' => ['§', ['(' => ')', '§' => '§']];
    }

    /**
     * @param  array<string, string>  $delimiters
     */
    #[DataProvider('symmetricPairs')]
    public function testSymmetricSpanUsesTheSameDepthBudget(string $quote, array $delimiters): void
    {
        foreach ([127, 128] as $depth) {
            // Only the quote closes; the surrounding asymmetric spans stay open.
            $input = str_repeat('(', $depth) . ' ' . $quote . 'a,b' . $quote;
            $expected = $depth === 127 ? str_replace(',', StructuralCommaSplitter::COMMA_PLACEHOLDER, $input) : $input;
            $this->assertSame($expected, StructuralCommaSplitter::mask($input, $delimiters));
        }
    }

    public function testScanningResumesAfterTrackedSpansClose(): void
    {
        $input = str_repeat('(', 129) . str_repeat(')', 128) . ' «a,b»';

        $this->assertSame(
            str_replace(',', StructuralCommaSplitter::COMMA_PLACEHOLDER, $input),
            StructuralCommaSplitter::mask($input, ['(' => ')', '«' => '»']),
        );
    }

    public function testUnusedUnicodePairDoesNotChangeParserBoundaries(): void
    {
        $input = 'John ' . str_repeat('(', 129) . 'Bob' . str_repeat(')', 128) . ', Jane Doe)';
        $ascii = (new Parser())->setNicknameDelimiters(['(' => ')'])->parse($input);
        $mixed = (new Parser())->setNicknameDelimiters(['(' => ')', '«' => '»'])->parse($input);

        $this->assertSame('Jane', $ascii->getFirstname());
        $this->assertSame('John', $ascii->getLastname());
        $this->assertSame($ascii->toArray(), $mixed->toArray());
    }
}
