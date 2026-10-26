<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RawInputBudgetTest extends TestCase
{
    #[DataProvider('shrinkingInputs')]
    public function testMalformedInputCannotShrinkBelowTheByteLimit(string $substitute, string $invalid, string $tail, bool $surnameFirst): void
    {
        $previous = mb_substitute_character();
        mb_substitute_character($substitute === '?' ? 63 : $substitute);

        try {
            $padding = Text::MAX_INPUT_BYTES + 1 - strlen($tail);
            $input = str_repeat($invalid, intdiv($padding, strlen($invalid)))
                . str_repeat(' ', $padding % strlen($invalid)) . $tail;
            $this->assertSame(Text::MAX_INPUT_BYTES + 1, strlen($input));
            $this->assertLessThan(Text::MAX_INPUT_BYTES, strlen(mb_scrub($input, 'UTF-8')));
            $this->expectException(\LengthException::class);
            $this->expectExceptionMessage('Name input exceeds the 1048576-byte limit.');

            (new Parser())->setSurnameFirst($surnameFirst)->parse($input);
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function shrinkingInputs(): iterable
    {
        foreach (['Western' => ['John Smith', false], 'comma' => ['Smith, John', false], 'surname-first' => ['Smith John', true]] as $mode => [$tail, $surnameFirst]) {
            yield $mode . ' removed bytes' => ['none', "\xFF", $tail, $surnameFirst];
            yield $mode . ' shortened sequence' => ['?', "\xE2\x82", $tail, $surnameFirst];
        }
    }

    #[DataProvider('acceptedByteBoundaries')]
    public function testInputAtOrBelowRawByteLimitIsStillAccepted(int $bytes, bool $malformed): void
    {
        $previous = mb_substitute_character();
        mb_substitute_character('none');

        try {
            $input = str_repeat($malformed ? "\xFF" : ' ', $bytes - 10) . 'John Smith';
            $this->assertSame($bytes, strlen($input));
            $name = (new Parser())->parse($input);
            $this->assertSame('John', $name->getFirstname());
            $this->assertSame('Smith', $name->getLastname());
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function acceptedByteBoundaries(): iterable
    {
        foreach ([Text::MAX_INPUT_BYTES - 1, Text::MAX_INPUT_BYTES] as $bytes) {
            yield $bytes . ' valid' => [$bytes, false];
            yield $bytes . ' malformed' => [$bytes, true];
        }
    }

    public function testScrubbedByteLimitIsStillEnforced(): void
    {
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            $input = str_repeat("\xFF", intdiv(Text::MAX_INPUT_BYTES, 3) + 1);
            $this->assertLessThan(Text::MAX_INPUT_BYTES, strlen($input));
            $this->assertGreaterThan(Text::MAX_INPUT_BYTES, strlen(mb_scrub($input, 'UTF-8')));
            $this->expectException(\LengthException::class);

            (new Parser())->parse($input);
        } finally {
            mb_substitute_character($previous);
        }
    }
}
