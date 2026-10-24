<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NicknameDelimiterSanitizationTest extends TestCase
{
    public function testLimitCountsValidPairsAndPreservesKeys(): void
    {
        $delimiters = ['' => ')', 'bad,' => ')', '(' => '', "\xFF" => ')'];
        $expected = ['«' => '»'];
        for ($i = 0; $i < 31; $i++) {
            $expected['open' . $i] = 'close' . $i;
        }

        $delimiters += $expected + ['overflow' => 'ignored'];

        $this->assertSame($expected, Text::sanitizeNicknameDelimiters($delimiters));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function numericDelimiters(): array
    {
        return ['zero' => ['0'], 'positive' => ['7'], 'negative' => ['-1']];
    }

    #[DataProvider('numericDelimiters')]
    public function testNumericKeysArePreserved(string $delimiter): void
    {
        $delimiters = [$delimiter => $delimiter];
        $this->assertSame($delimiters, Text::sanitizeNicknameDelimiters($delimiters));
    }

    public function testEntriesAfterLimitAreStillValidated(): void
    {
        $delimiters = [];
        for ($i = 0; $i < 32; $i++) {
            $delimiters['open' . $i] = 'close' . $i;
        }
        $delimiters['invalid'] = [];

        $this->expectException(\TypeError::class);
        // Preserve validation of malformed caller data beyond the result limit.
        (new \ReflectionMethod(Text::class, 'sanitizeNicknameDelimiters'))->invoke(null, $delimiters);
    }

    public function testLargeConfigurationDoesNotCopyDiscardedPairs(): void
    {
        $delimiters = [];
        for ($i = 0; $i < 100000; $i++) {
            $delimiters['open' . $i] = 'close' . $i;
        }
        // Load the class before measuring the sanitizer rather than autoloading.
        Text::sanitizeNicknameDelimiters([]);
        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        $result = Text::sanitizeNicknameDelimiters($delimiters);
        $extraPeak = memory_get_peak_usage() - $baseline;

        $this->assertCount(32, $result);
        $this->assertSame(array_slice($delimiters, 0, 32, true), $result);
        $this->assertLessThan(1024 * 1024, $extraPeak);
    }
}
