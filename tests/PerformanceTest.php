<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\TestCase;

class PerformanceTest extends TestCase
{
    private const float MAX_SECONDS = 1.5;

    private const float MAX_SCALING_RATIO = 3.0;

    private const int MAX_INPUT_BYTES = 1024 * 1024;

    private const int MAX_INPUT_TOKENS = 65536;

    public function testCombinedInitialExpansionRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('AB ', $size) . 'Smith',
        );
    }

    public function testMultiwordSalutationMappingRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('the honorable ', $size) . 'John Smith',
        );
    }

    public function testJointSalutationValidationRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('Mr. and ', $size) . 'Mrs. Brad Smith',
            false,
            2000,
            4000,
        );
    }

    public function testSurnameFirstSalutationPeelingRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('Dr ', $size) . 'Kim Jong',
            true,
        );
    }

    public function testNestedNicknameDepthRemainsBoundedAtBatchScale(): void
    {
        $elapsed = $this->parseSeconds(
            str_repeat('( ', 16000) . str_repeat(') ', 16000) . 'Smith',
        );

        $this->assertLessThan(0.2, $elapsed);
    }

    public function testCommaHeavyInputUsesBoundedWorkingMemory(): void
    {
        $input = 'Smith,' . str_repeat(',', 500000);
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        (new Parser())->parse($input);

        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testMaximumSizeInteriorTokenUsesBoundedWorkingMemory(): void
    {
        $input = 'John ' . str_repeat('A', self::MAX_INPUT_BYTES - 11) . ' Smith';
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        (new Parser())->parse($input);

        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testMaximumCamelcaseScanRemainsFastWithoutPcreJit(): void
    {
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');

        try {
            $started = hrtime(true);
            $word = 'A' . str_repeat('a', 1023);
            for ($i = 0; $i < 250; ++$i) {
                (new Lastname($word))->normalize();
            }
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        } finally {
            if ($jit !== false) {
                ini_set('pcre.jit', $jit);
            }
        }

        $this->assertLessThan(0.25, $elapsed);
    }

    public function testMaximumCommonPrefixDelimiterScanRemainsBounded(): void
    {
        $delimiters = [];
        for ($i = 0; $i < 32; ++$i) {
            $delimiter = str_repeat('a', 62) . sprintf('%02d', $i);
            $delimiters[$delimiter] = $delimiter;
        }

        $parser = (new Parser())->setNicknameDelimiters($delimiters);
        $started = hrtime(true);
        $parser->parse(str_repeat('a', 4094) . ',X');
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertLessThan(0.15, $elapsed);
    }

    public function testRejectsInputOverByteBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('A', self::MAX_INPUT_BYTES + 1));
    }

    public function testRejectsInputOverTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('A ', self::MAX_INPUT_TOKENS) . 'A');
    }

    public function testAcceptsExactCommaTokenBudget(): void
    {
        $input = str_repeat('A,', self::MAX_INPUT_TOKENS - 1) . 'A';

        $this->assertSame($input, (new Parser())->parse($input)->getSource());
    }

    public function testRejectsInputOverCommaTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('A,', self::MAX_INPUT_TOKENS) . 'A');
    }

    /**
     * @param  callable(int): string  $input
     */
    private function assertLinearScaling(
        callable $input,
        bool $surnameFirst = false,
        int $smallSize = 16000,
        int $largeSize = 32000,
    ): void {
        $small = $this->parseSeconds($input($smallSize), $surnameFirst);
        $large = $this->parseSeconds($input($largeSize), $surnameFirst);

        $this->assertLessThan(self::MAX_SECONDS, $large);
        $this->assertLessThan(
            ($small * self::MAX_SCALING_RATIO) + 0.005,
            $large,
        );
    }

    private function parseSeconds(string $input, bool $surnameFirst = false): float
    {
        $started = hrtime(true);
        $parser = (new Parser())->setSurnameFirst($surnameFirst);
        $parser->parse($input)->toArray();

        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
