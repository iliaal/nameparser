<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unclosed-nickname regressions ported from tobyberster/name-parser.
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
     * Stray closers remain attached to names but do not block credential matching.
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

    /**
     * @return iterable<string, array{string, string, string, string, array<string, string>}>
     */
    public static function adjacentNestedNicknameProvider(): iterable
    {
        yield 'literal heart' => ['John (<3) Doe', '<3', '', 'John', []];
        yield 'literal brace' => ['John ({Bob) Doe', '{Bob', '', 'John', []];
        yield 'literal bracket' => ['John ([Bob) Doe', '[Bob', '', 'John', []];
        yield 'literal parenthesis' => ['John {(Bob} Doe', '(Bob', '', 'John', []];
        yield 'same pair' => ['John ((Bob) Robert) Doe', '(Bob) Robert', '', 'John', []];
        yield 'mixed pairs' => ['John ([Bob] Robert) Doe', '[Bob] Robert', '', 'John', []];
        yield 'inside a span' => ['John (Bob ((Rob) Robert) Z) Doe', 'Bob ((Rob) Robert) Z', '', 'John', []];
        yield 'comma inside a nested nickname' => ['John ((Bob), Robert) Doe', '(Bob), Robert', '', 'John', []];
        yield 'punctuation after inner closer' => ['John ((Bob); Robert) Doe', '(Bob); Robert', '', 'John', []];
        yield 'empty nested nickname' => ['John (() ) Doe', '()', '', 'John', []];
        yield 'unclosed span' => ['John ((Bob) Robert Doe', '', '(Bob) Robert', 'John (Bob) Robert', []];
        yield 'mismatched closer' => ['John ([Bob) Robert] Doe', '[Bob', 'Robert]', 'John Robert]', []];
        yield 'unclosed asymmetric span inside a quote' => ['John "(Bob Robert" Doe', '(Bob Robert', '', 'John', []];
        yield 'custom longest opener' => ['John <<<<Bob>> Robert>> Doe', '<<Bob>> Robert', '', 'John', ['<' => '>', '<<' => '>>']];
        yield 'Unicode pair' => ['John ««Bób» Robert» Doe', '«Bób» Robert', '', 'John', ['«' => '»']];
    }

    /**
     * @param  array<string, string>  $delimiters
     */
    #[DataProvider('adjacentNestedNicknameProvider')]
    public function testAdjacentNestedNicknameFields(
        string $input,
        string $nickname,
        string $middle,
        string $given,
        array $delimiters,
    ): void {
        $parser = (new Parser())->setNicknameDelimiters($delimiters);
        $this->assertSame([
            'salutation' => '',
            'firstname' => 'John',
            'initials' => '',
            'middlename' => $middle,
            'lastname_prefix' => '',
            'lastname' => 'Doe',
            'suffix' => '',
            'nickname' => $nickname,
            'given_name' => $given,
            'full_name' => $given . ' Doe',
        ], $parser->parse($input)->toArray());
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, string>}>
     */
    public static function nicknameAfterEnclosingSpanProvider(): iterable
    {
        yield 'reported heart' => ['Jane (<3) Doe <Janie>', 'Jane', '<3 Janie', []];
        yield 'reported brace' => ['John ({Bob) Doe {Bobby}', 'John', '{Bob Bobby', []];
        foreach (Mapper\NicknameMapperTest::enclosingBoundaryProvider() as $label => [$delimiters, $input, $expected]) {
            if (! str_ends_with($label, ' closer after span')) {
                continue;
            }
            if (! $expected[1] instanceof \Iliaal\NameParser\Part\Nickname) {
                throw new \LogicException('Expected a nickname fixture.');
            }
            yield $label => [implode(' ', $input), 'John', $expected[1]->getValue() . ' Bobby', $delimiters];
        }
    }

    /**
     * @param  array<string, string>  $delimiters
     */
    #[DataProvider('nicknameAfterEnclosingSpanProvider')]
    public function testLaterNicknameDoesNotStealSurname(string $input, string $first, string $nickname, array $delimiters): void
    {
        $name = (new Parser())->setNicknameDelimiters($delimiters)->parse($input);
        $this->assertSame([
            'salutation' => '',
            'firstname' => $first,
            'initials' => '',
            'middlename' => '',
            'lastname_prefix' => '',
            'lastname' => 'Doe',
            'suffix' => '',
            'nickname' => $nickname,
            'given_name' => $first,
            'full_name' => $first . ' Doe',
        ], $name->toArray());
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
