<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextKeyCacheTest extends TestCase
{
    protected function setUp(): void
    {
        Text::clearCache();
    }

    protected function tearDown(): void
    {
        Text::clearCache();
    }

    #[DataProvider('byteBoundaryCases')]
    public function testByteBoundaries(string $word, string $expected, string $cache): void
    {
        $this->assertSame($expected, Text::key($word));
        $this->assertSame($expected, Text::key($word));
        $this->assertSame($cache === 'cache' ? [$word => $expected] : [], self::cache('cache'));
        $this->assertSame($cache === 'longCache' ? [$word => $expected] : [], self::cache('longCache'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function byteBoundaryCases(): iterable
    {
        foreach ([63, 64, 65, 4095, 4096, 4097] as $bytes) {
            $cache = $bytes <= 64 ? 'cache' : ($bytes <= 4096 ? 'longCache' : '');
            yield 'ASCII ' . $bytes . ' bytes' => [str_repeat('A', $bytes), str_repeat('a', $bytes), $cache];
            // The cutoffs count bytes, not Unicode characters.
            $tail = $bytes % 2 === 0 ? '' : 'A';
            yield 'UTF-8 ' . $bytes . ' bytes' => [
                str_repeat('É', intdiv($bytes, 2)) . $tail,
                str_repeat('é', intdiv($bytes, 2)) . strtolower($tail),
                $cache,
            ];
        }
    }

    #[DataProvider('normalizationCases')]
    public function testNormalizationAcrossCacheClear(string $word, string $expected): void
    {
        $this->assertSame($expected, Text::key($word));
        $this->assertSame($expected, Text::key($word));
        Text::clearCache();
        $this->assertSame($expected, Text::key($word));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizationCases(): iterable
    {
        yield 'empty key' => ['', ''];
        yield 'numeric zero' => ['0', '0'];
        yield 'leading zero' => ['00', '00'];
        yield 'punctuated short key' => [" \t(É.J.);\r\n", 'éj'];
        yield 'punctuated long key' => ['(' . str_repeat('É.', 40) . ');', str_repeat('é', 40)];
        yield 'punctuated uncached key' => ['(' . str_repeat('É.', 1400) . ');', str_repeat('é', 1400)];
    }

    public function testShortCacheEvictsOldestQuarterWithoutRenumberingNumericKeys(): void
    {
        $long = str_repeat('B', 65);
        Text::key($long);
        for ($i = 0; $i < 4096; ++$i) {
            $this->assertSame((string) $i, Text::key((string) $i));
        }
        $this->assertCount(4096, self::cache('cache'));

        // A hit at capacity does not evict or move an entry to the newest end.
        $this->assertSame('0', Text::key('0'));
        $this->assertCount(4096, self::cache('cache'));
        $this->assertSame('4096', Text::key('4096'));
        $this->assertSame(range(1024, 4096), array_keys(self::cache('cache')));
        $this->assertSame([$long => str_repeat('b', 65)], self::cache('longCache'));

        // Renumbering numeric keys during eviction returns another token's value.
        $this->assertSame('0', Text::key('0'));
        $this->assertSame('1024', Text::key('1024'));
        $this->assertSame('4096', Text::key('4096'));
    }

    public function testLongCacheEvictsOldestQuarterIndependently(): void
    {
        Text::key('Short.');
        $expected = [];
        for ($i = 0; $i < 256; ++$i) {
            $word = str_repeat('A', 65) . $i;
            $expected[$word] = str_repeat('a', 65) . $i;
            $this->assertSame($expected[$word], Text::key($word));
        }
        $this->assertSame($expected, self::cache('longCache'));
        $first = str_repeat('A', 65) . '0';
        $this->assertSame(str_repeat('a', 65) . '0', Text::key($first));
        $this->assertSame($expected, self::cache('longCache'));

        $word = str_repeat('A', 65) . '256';
        $this->assertSame(str_repeat('a', 65) . '256', Text::key($word));
        $expected = array_slice($expected, 64, null, true);
        $expected[$word] = str_repeat('a', 65) . '256';
        $this->assertSame($expected, self::cache('longCache'));
        $this->assertSame(['Short.' => 'short'], self::cache('cache'));
        $this->assertSame(str_repeat('a', 65) . '0', Text::key($first));
        $this->assertSame(str_repeat('a', 65) . '64', Text::key(str_repeat('A', 65) . '64'));
    }

    public function testClearCacheEmptiesBothStores(): void
    {
        Text::key('Short.');
        Text::key(str_repeat('A', 65));
        $this->assertNotEmpty(self::cache('cache'));
        $this->assertNotEmpty(self::cache('longCache'));
        Text::clearCache();
        $this->assertSame([], self::cache('cache'));
        $this->assertSame([], self::cache('longCache'));
        Text::clearCache();
        $this->assertSame([], self::cache('cache'));
        $this->assertSame([], self::cache('longCache'));
    }

    /**
     * Inspect storage only to lock down the internal bounds, without timing or memory heuristics.
     *
     * @return array<int|string, string>
     */
    private static function cache(string $property): array
    {
        $cache = (new \ReflectionProperty(Text::class, $property))->getValue();
        self::assertIsArray($cache);
        self::assertContainsOnlyString($cache);

        return $cache;
    }
}
