<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An opening nickname delimiter with no matching close must not swallow the
 * surname. Regression for inputs like "John (Bob Smith" where upstream lost
 * the last name to the NicknameMapper. Ported from tobyberster/name-parser.
 */
class UnclosedNicknameTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unclosedProvider(): array
    {
        return [
            // input, expected first, expected last
            'unclosed paren'   => ['John (Bob Smith', 'John', 'Smith'],
            'unclosed quote'   => ['Mary "Sue Jones', 'Mary', 'Jones'],
            'unclosed bracket' => ['Bob [nick Williams', 'Bob', 'Williams'],
        ];
    }

    #[DataProvider('unclosedProvider')]
    public function testUnclosedDelimiterPreservesName(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
    }

    /**
     * Mirror of the opener provider for stray closers: a closer with no
     * earlier opener is not a nickname span. A credential-shaped tail
     * ('MD)') still maps (the closer is ordinary trailing punctuation), but
     * a bare name tail keeps the closer glued ('Smith)') and a mid-name
     * closer rides with its token ('Bob)'), so every getter is pinned.
     *
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function strayCloserProvider(): array
    {
        // input, expected first, expected last, expected nickname, expected suffix
        return [
            'credential before stray paren closer' => ['John Smith MD)', 'John', 'Smith', '', 'MD'],
            'bare stray paren closer stays glued' => ['John Smith)', 'John', 'Smith)', '', ''],
            'mid-name stray paren closer rides along' => ['John Bob) Smith', 'John', 'Smith', '', ''],
            'stray paren closer in comma given segment' => ['Smith, John)', 'John)', 'Smith', '', ''],
            'bare stray bracket closer stays glued' => ['John Smith]', 'John', 'Smith]', '', ''],
            'bare stray quote closer stays glued' => ['Mary Sue Jones"', 'Mary', 'Jones"', '', ''],
        ];
    }

    #[DataProvider('strayCloserProvider')]
    public function testStrayCloserWithoutOpenerIsNotANickname(
        string $input,
        string $first,
        string $last,
        string $nickname,
        string $suffix,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($nickname, $name->getNickname(), "nickname for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    public function testClosedNicknameStillExtracted(): void
    {
        $name = (new Parser())->parse('John (Bob) Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Bob', $name->getNickname());
    }

    public function testClosedNicknameWithSalutationAndSuffix(): void
    {
        $name = (new Parser())->parse('Dr. Jane (JJ) Doe MD');

        $this->assertSame('Jane', $name->getFirstname());
        $this->assertSame('Doe', $name->getLastname());
        $this->assertSame('Jj', $name->getNickname());
        $this->assertSame('MD', $name->getSuffix());
        $this->assertSame('Dr.', $name->getSalutation());
    }
}
