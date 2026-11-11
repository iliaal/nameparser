<?php

namespace Tests\Iliaal\NameParser\Part;

use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Middlename;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalisationBoundaryTest extends TestCase
{
    #[DataProvider('mixedCaseBoundaryProvider')]
    public function testMixedCaseBudgetUsesBytesAfterRemovingCombiningMarks(
        string $input,
        int $inputBytes,
        string $expected,
    ): void {
        $this->assertSame($inputBytes, strlen($input));

        // All ordinary name parts share the same normalization path.
        foreach ([Firstname::class, Middlename::class, Lastname::class] as $class) {
            $part = new $class($input);
            $this->assertSame($expected, $part->normalize(), $class);
            $this->assertSame($expected, $part->normalize(), $class . ' cached');
        }
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function mixedCaseBoundaryProvider(): iterable
    {
        foreach ([1023, 1024, 1025] as $bytes) {
            $tail = str_repeat('a', $bytes - 3);
            $ascii = 'AbC' . $tail;
            $expected = ($bytes <= 1024 ? 'AbC' : 'Abc') . $tail;
            yield 'ASCII shape ' . $bytes => [$ascii, $bytes, $expected];

            $tail = str_repeat('é', intdiv($bytes - 3, 2)) . (($bytes - 3) % 2 ? 'x' : '');
            yield 'multibyte shape ' . $bytes => [
                'AbC' . $tail,
                $bytes,
                ($bytes <= 1024 ? 'AbC' : 'Abc') . $tail,
            ];

            // Marks increase the original byte length, but not the case-shape budget.
            yield 'combining mark after shape ' . $bytes => [
                $ascii . "\u{0301}",
                $bytes + 2,
                $expected . "\u{0301}",
            ];
        }
    }

    #[DataProvider('callbackBoundaryProvider')]
    public function testLongUniformCaseTokensBypassPerRunCallbacksAndCacheResults(
        string $input,
        int $bytes,
        string $expected,
        int $callbacks,
    ): void {
        $part = new class ($input) extends Lastname {
            public int $callbackCount = 0;

            /**
             * @param  array<int, string>  $matches
             */
            #[\Override]
            protected function camelcaseReplace(array $matches): string
            {
                ++$this->callbackCount;

                return parent::camelcaseReplace($matches);
            }
        };

        $this->assertSame($bytes, strlen($input));
        $this->assertSame($expected, $part->normalize());
        $this->assertSame($callbacks, $part->callbackCount);
        $this->assertSame($expected, $part->normalize());
        $this->assertSame($callbacks, $part->callbackCount, 'A cache hit must not repeat callbacks.');
    }

    /**
     * @return iterable<string, array{string, int, string, int}>
     */
    public static function callbackBoundaryProvider(): iterable
    {
        foreach ([255, 256, 257] as $bytes) {
            $callbacks = $bytes <= 256 ? 1 : 0;
            yield 'ASCII ' . $bytes => [str_repeat('a', $bytes), $bytes, 'A' . str_repeat('a', $bytes - 1), $callbacks];
            yield 'multibyte ' . $bytes => ['é' . str_repeat('a', $bytes - 2), $bytes, 'É' . str_repeat('a', $bytes - 2), $callbacks];
            yield 'combining mark ' . $bytes => [
                "e\u{0301}" . str_repeat('a', $bytes - 3),
                $bytes,
                "E\u{0301}" . str_repeat('a', $bytes - 3),
                $callbacks,
            ];
        }
    }
}
