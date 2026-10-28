<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use Iliaal\NameParser\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfidenceScrubbedBudgetTest extends TestCase
{
    #[DataProvider('scrubbedBoundaries')]
    public function testScrubbedByteLimitPrecedesNormalization(int $substitute, int $bytes, bool $suppliedTokens, bool $customWhitespace): void
    {
        $previous = mb_substitute_character();
        mb_substitute_character($substitute);

        try {
            $replacement = mb_chr($substitute, 'UTF-8');
            $width = strlen($replacement);
            $input = str_repeat("\xFF", intdiv($bytes, $width)) . str_repeat('x', $bytes % $width);
            $this->assertLessThan(Text::MAX_INPUT_BYTES, strlen($input));
            $this->assertSame($bytes, strlen(mb_scrub($input, 'UTF-8')));

            if ($bytes > Text::MAX_INPUT_BYTES) {
                $this->expectException(\LengthException::class);
                $this->expectExceptionMessage('Name input exceeds the 1048576-byte limit.');
            }

            $result = Confidence::assess(
                $input,
                tokens: $suppliedTokens ? [] : null,
                whitespace: $customWhitespace ? $replacement : null,
            );
            $this->assertSame(['ambiguous' => true, 'notes' => ['input is not valid UTF-8']], $result);
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * @return iterable<string, array{int, int, bool, bool}>
     */
    public static function scrubbedBoundaries(): iterable
    {
        foreach ([0xFFFD, 0x1F600, 0x200B, 0x2028] as $substitute) {
            foreach ([-1, 0, 1] as $offset) {
                foreach ([false, true] as $suppliedTokens) {
                    yield $substitute . ':' . $offset . ':' . (int) $suppliedTokens => [
                        $substitute, Text::MAX_INPUT_BYTES + $offset, $suppliedTokens, false,
                    ];
                }
            }
        }
        // Even custom whitespace collapse must not hide scrubbed-byte overflow.
        foreach ([-1, 0, 1] as $offset) {
            foreach ([false, true] as $suppliedTokens) {
                yield 'custom whitespace:' . $offset . ':' . (int) $suppliedTokens => [
                    0xFFFD, Text::MAX_INPUT_BYTES + $offset, $suppliedTokens, true,
                ];
            }
        }
    }
}
