<?php

namespace Tests\Iliaal\NameParser\Part;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AbstractPartTest extends TestCase
{
    public function testCamelcaseCacheIsInvalidatedOnSetValue(): void
    {
        $part = new Lastname('mcdonald');
        $this->assertEquals('Mcdonald', $part->normalize());

        $part->setValue('van der berg');
        $this->assertEquals('Van Der Berg', $part->normalize());
    }

    public function testNormalize(): void
    {
        $part = new class ('abc') extends AbstractPart {};
        $this->assertEquals('abc', $part->normalize());
    }

    public function testSetValueUnwraps(): void
    {
        $part = new class ('abc') extends AbstractPart {};
        $this->assertEquals('abc', $part->getValue());

        $wrapped = new class ($part) extends AbstractPart {};
        $this->assertEquals('abc', $wrapped->getValue());
    }

    public function testCamelcaseIsKeyedByWordNotFirstCall(): void
    {
        $part = new class ('irrelevant') extends Lastname {
            /**
             * @return list<string>
             */
            public function camelcaseBoth(): array
            {
                return [$this->camelcase('alpha'), $this->camelcase('beta')];
            }
        };

        $this->assertSame(['Alpha', 'Beta'], $part->camelcaseBoth());
    }

    #[DataProvider('camelcaseProvider')]
    public function testCamelcaseDetection(string $input, string $expected): void
    {
        $this->assertSame($expected, (new Lastname($input))->normalize());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function camelcaseProvider(): iterable
    {
        yield 'standard title case' => ['Macdonald', 'Macdonald'];
        yield 'lowercase' => ['macdonald', 'Macdonald'];
        yield 'two-letter initial transition' => ['mA', 'Ma'];
        yield 'lower-upper-lower' => ['iPhone', 'iPhone'];
        yield 'upper-lower-upper' => ['McDonald', 'McDonald'];
        yield 'upper-upper-lower' => ['ABc', 'ABc'];
        yield 'upper-lower-upper ending' => ['AbC', 'AbC'];
        yield 'lower-upper-lower ending' => ['aBc', 'aBc'];
        yield 'lowercase to uppercase tail' => ['macDONALD', 'macDONALD'];
        yield 'single initial transition only' => ['jOHN', 'John'];
    }
}
