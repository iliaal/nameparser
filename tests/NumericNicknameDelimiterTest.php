<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\StructuralCommaSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NumericNicknameDelimiterTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function delimiterProvider(): array
    {
        $cases = [];
        foreach (['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '-1'] as $delimiter) {
            $cases['delimiter ' . $delimiter] = [$delimiter];
        }

        return $cases;
    }

    #[DataProvider('delimiterProvider')]
    public function testSymmetricNumericDelimitersRequireTokenBoundaries(string $delimiter): void
    {
        $delimiters = [$delimiter => $delimiter];
        $input = 'Smith' . $delimiter . ', John' . $delimiter;

        $this->assertSame(
            ['Smith' . $delimiter, ' John' . $delimiter],
            StructuralCommaSplitter::split($input, $delimiters),
        );
        $name = (new Parser())->setNicknameDelimiters($delimiters)->parse($input);
        $this->assertSame('John' . $delimiter, $name->getFirstname());
        $this->assertSame('Smith' . $delimiter, $name->getLastname());
        $this->assertSame('', $name->getNickname());

        $this->assertSame(
            ['John ' . $delimiter . 'foo', 'bar' . $delimiter . 'x Smith'],
            StructuralCommaSplitter::split('John ' . $delimiter . 'foo,bar' . $delimiter . 'x Smith', $delimiters),
        );
        $closed = 'John ' . $delimiter . 'foo,bar' . $delimiter . ' Smith';
        $this->assertSame([$closed], StructuralCommaSplitter::split($closed, $delimiters));
    }
}
