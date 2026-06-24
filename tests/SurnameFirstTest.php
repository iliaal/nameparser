<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * setSurnameFirst(true) reads a space-separated, comma-less name in CJK order
 * (surname first): the first token is the surname, the rest is the given-name
 * segment, routed through the same split path as the comma form. It is an
 * opt-in mode the caller asserts for the batch, since romanized order cannot be
 * auto-detected; the default parser stays Western-ordered.
 */
class SurnameFirstTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, first, middle, last
            'two-token chinese'   => ['Mao Zedong', 'Zedong', '', 'Mao'],
            'two-token chinese 2' => ['Xi Jinping', 'Jinping', '', 'Xi'],
            'three-token korean'  => ['Kim Jong Un', 'Jong', 'Un', 'Kim'],
            'hyphenated given'    => ['Park Geun-hye', 'Geun-Hye', '', 'Park'],
            'three-token chinese' => ['Lee Kuan Yew', 'Kuan', 'Yew', 'Lee'],
        ];
    }

    #[DataProvider('provider')]
    public function testSurnameFirstOrder(string $input, string $first, string $middle, string $last): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    public function testSingleTokenIsLeftAsGivenName(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Kim');

        $this->assertSame('Kim', $name->getFirstname());
        $this->assertSame('', $name->getLastname());
    }

    public function testCommaFormTakesPrecedence(): void
    {
        $name = (new Parser())->setSurnameFirst(true)->parse('Smith, John');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testDefaultParserStaysWesternOrdered(): void
    {
        $name = (new Parser())->parse('Mao Zedong');

        $this->assertSame('Mao', $name->getFirstname());
        $this->assertSame('Zedong', $name->getLastname());
    }
}
