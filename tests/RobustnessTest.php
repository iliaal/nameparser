<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RobustnessTest extends TestCase
{
    public function testUnicodeInitialIsNotCorrupted(): void
    {
        $name = (new Parser())->parse("\u{00C9} Durand");

        $this->assertSame('Durand', $name->getLastname());
        $combined = $name->getFirstname() . $name->getInitials();
        $this->assertStringContainsString("\u{00C9}", $combined);
        $this->assertStringNotContainsString("\u{FFFD}", $combined, 'no replacement char');
    }

    public function testShortUnicodeLastnameDoesNotAbsorbMiddleName(): void
    {
        $name = (new Parser())->parse("Mary Jo \u{00C9}");

        $this->assertSame('Mary', $name->getFirstname());
        $this->assertSame('Jo', $name->getMiddlename());
        $this->assertSame("\u{00C9}", $name->getLastname());
    }

    public function testDecomposedShortLastnameDoesNotAbsorbMiddleName(): void
    {
        $lastname = "E\u{0301}";
        $name = (new Parser())->parse('Mary Jo ' . $lastname);

        $this->assertSame('Mary', $name->getFirstname());
        $this->assertSame('Jo', $name->getMiddlename());
        $this->assertSame($lastname, $name->getLastname());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function caselessScriptProvider(): array
    {
        // Input surname, caseless given name, expected suffix.
        return [
            'han' => ['Wang', "\u{674E}\u{660E}", ''],
            'hebrew' => ['Cohen', "\u{05DC}\u{05D9}", ''],
            'arabic' => ['Haddad', "\u{0645}\u{062D}\u{0645}\u{062F}", ''],
            'thai' => ['Somsak', "\u{0E2D}\u{0E32}\u{0E17}\u{0E34}\u{0E15}\u{0E22}\u{0E4C}", ''],
            'hiragana' => ['Tanaka', "\u{3042}\u{304D}\u{3089}", ''],
            'han with credential' => ['Wang', "\u{674E}\u{660E}", 'MD'],
        ];
    }

    #[DataProvider('caselessScriptProvider')]
    public function testCaselessScriptGivenNameIsNotSplitIntoInitials(string $last, string $given, string $suffix): void
    {
        $input = $suffix === '' ? "{$last}, {$given}" : "{$last}, {$given} {$suffix}";
        $name = (new Parser())->parse($input);

        $this->assertSame($given, $name->getFirstname(), "firstname for '$input'");
        $this->assertSame($last, $name->getLastname(), "lastname for '$input'");
        $this->assertSame('', $name->getInitials(), "initials for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    public function testLoneCaselessGivenCharIsFirstNameNotInitial(): void
    {
        $name = (new Parser())->parse("Wang, \u{674E}");

        $this->assertSame("\u{674E}", $name->getFirstname());
        $this->assertSame('Wang', $name->getLastname());
        $this->assertSame('', $name->getInitials());
    }

    public function testTrailingCommaCredentialsAreNotDropped(): void
    {
        $name = (new Parser())->parse('Smith, John, MD, PhD');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD PhD', $name->getSuffix());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function trailingPunctuationCredentialProvider(): array
    {
        return [
            'semicolon' => ['John Smith MD;', 'MD'],
            'paren' => ['John Smith MD)', 'MD'],
            'comma' => ['John Smith MD,', 'MD'],
        ];
    }

    #[DataProvider('trailingPunctuationCredentialProvider')]
    public function testTrailingPunctuationDoesNotBlockCredentialLookup(string $input, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame($suffix, $name->getSuffix());
    }

    public function testTrailingCommaWithEmptyGivenSegmentKeepsSurnameSemantics(): void
    {
        $name = (new Parser())->parse('Smith,');

        $this->assertSame('', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Smith', $name->getFullName());
    }

    public function testToStringOmitsEmptyNicknameParentheses(): void
    {
        $this->assertSame('John Smith', (string) (new Parser())->parse('John Smith'));
        $this->assertSame('Bob', (new Parser())->parse('John (Bob) Smith')->getNickname());
    }

    public function testSpacedNicknameParenthesesYieldCleanNickname(): void
    {
        $name = (new Parser())->parse('John ( Bob ) Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Bob', $name->getNickname());
    }

    public function testElidedDutchParticleIsNotTreatedAsNickname(): void
    {
        $name = (new Parser())->parse("Gerard 't Hooft");

        $this->assertSame('Gerard', $name->getFirstname());
        $this->assertSame('Hooft', $name->getLastname());
        $this->assertSame('', $name->getNickname());
        $this->assertSame('', $name->getInitials());
        $this->assertSame("'T", $name->getMiddlename());
    }

    public function testSymmetricQuoteNicknameStillExtracted(): void
    {
        $name = (new Parser())->parse("John 'Bob' Smith");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Bob', $name->getNickname());
    }

    public function testMultibyteWhitespaceDoesNotCorruptSharedByteGlyphs(): void
    {
        // U+3000 shares bytes with other glyphs; a bytewise class would corrupt them.
        $parser = (new Parser())->setWhitespace("\u{3000}");
        $name = $parser->parse("\u{7530}\u{4E2D}\u{3000}Smith\u{3002}X");

        $this->assertSame("\u{7530}\u{4E2D}", $name->getFirstname());
        $this->assertSame("Smith\u{3002}X", $name->getLastname());
        $this->assertTrue(mb_check_encoding($name->getLastname(), 'UTF-8'));
    }

    public function testInvalidUtf8WhitespaceFallsBackToBytewiseWithoutWarnings(): void
    {
        // Invalid UTF-8 whitespace cannot compile under /u; retain bytewise matching.
        $parser = (new Parser())->setWhitespace("\xFF");
        $name = $parser->parse("John\xFFSmith");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidUtf8BodyProvider(): array
    {
        // Input, expected firstname, expected lastname; mb_scrub uses '?' for invalid bytes.
        return [
            'invalid byte in comma given name' => ["Smith, J\xFFhn", 'J?Hn', 'Smith'],
            'invalid byte leading the given name' => ["J\xFFhn Smith", 'J?Hn', 'Smith'],
            'invalid byte trailing into surname' => ["Smith J\xFFhn", 'Smith', 'J?Hn'],
            'lone invalid byte as given name' => ["\xFF Smith", '?', 'Smith'],
        ];
    }

    #[DataProvider('invalidUtf8BodyProvider')]
    public function testInvalidUtf8BodyIsScrubbedUnderDefaultConfig(string $input, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), 'firstname for ' . bin2hex($input));
        $this->assertSame($last, $name->getLastname(), 'lastname for ' . bin2hex($input));
        $this->assertTrue(mb_check_encoding($name->getFirstname() . $name->getLastname(), 'UTF-8'), 'scrubbed output is valid UTF-8');
    }

    public function testInvalidUtf8NicknameDelimiterIsIgnoredWithoutWarnings(): void
    {
        $parser = (new Parser())->setNicknameDelimiters(["\xC3" => "\xC3"]);
        $name = $parser->parse('John Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testOversizedNicknameDelimiterIsIgnoredWithoutWarnings(): void
    {
        $opener = str_repeat('a', 65);
        $parser = (new Parser())->setNicknameDelimiters([
            $opener => ']',
        ]);
        $name = $parser->parse($opener . 'Bob] Smith');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getNickname());
    }

    public function testOversizedNicknameDelimiterDoesNotShieldStructuralComma(): void
    {
        $opener = str_repeat('x', 65);
        $input = "John {$opener}Nick, Jr>> Smith, MD";

        $overLimit = (new Parser())->setNicknameDelimiters([$opener => '>>'])->parse($input);
        $ignored = (new Parser())->setNicknameDelimiters(['nope' => '>>'])->parse($input);

        $this->assertSame($ignored->toArray(), $overLimit->toArray());
    }

    public function testNicknameDelimiterBeyondPairLimitDoesNotShieldStructuralComma(): void
    {
        $delimiters = [];
        for ($i = 0; $i < 32; $i++) {
            $delimiters["q{$i}["] = "]{$i}";
        }

        $accepted = $delimiters;
        $delimiters['target['] = ']';
        $input = 'John target[Nick, Jr] Smith, MD';

        $overLimit = (new Parser())->setNicknameDelimiters($delimiters)->parse($input);
        $ignored = (new Parser())->setNicknameDelimiters($accepted)->parse($input);

        $this->assertSame($ignored->toArray(), $overLimit->toArray());
    }

    public function testEmptyNicknameCloserIsIgnoredWithoutWarnings(): void
    {
        $parser = (new Parser())->setNicknameDelimiters(['(' => '']);
        $name = $parser->parse('John (Bob) Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getNickname());
    }

    public function testCustomWhitespaceTrimsEdges(): void
    {
        $parser = new Parser();
        $parser->setWhitespace('_');
        $name = $parser->parse('_John_Smith_');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testMaxSalutationIndexBeyondPartsDoesNotWarn(): void
    {
        $parser = new Parser();
        $parser->setMaxSalutationIndex(10);
        $name = $parser->parse('Mr');

        $this->assertSame('Mr.', $name->getSalutation());
    }

    public function testUnclosedDelimiterDoesNotLeakIntoName(): void
    {
        $this->assertSame('Jones', (new Parser())->parse('Bob Jones (')->getLastname());
        $this->assertSame('Smith', (new Parser())->parse('John (Bob Smith')->getLastname());
    }

    #[DataProvider('loneDelimiterProvider')]
    public function testLoneDelimiterTokenDoesNotCrash(string $input): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('', $name->getFirstname());
        $this->assertSame('', $name->getLastname());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loneDelimiterProvider(): array
    {
        return [
            'open paren'   => ['('],
            'open brace'   => ['{'],
            'open bracket' => ['['],
            'open angle'   => ['<'],
            'double quote' => ['"'],
            'single quote' => ["'"],
        ];
    }

    #[DataProvider('degenerateInputProvider')]
    public function testDegenerateInputYieldsAllEmptyName(string $input): void
    {
        $name = (new Parser())->parse($input);

        $expected = [
            'salutation' => '',
            'firstname' => '',
            'initials' => '',
            'middlename' => '',
            'lastname_prefix' => '',
            'lastname' => '',
            'suffix' => '',
            'nickname' => '',
            'given_name' => '',
            'full_name' => '',
        ];

        $this->assertSame($expected, $name->toArray(), "toArray for '$input'");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function degenerateInputProvider(): array
    {
        return [
            'empty'        => [''],
            'spaces only'  => ['   '],
            'bare comma'   => [','],
            'spaced comma' => [' , '],
            'double comma' => [',,'],
        ];
    }

    public function testCommaSegmentWithLoneDelimiterKeepsSurname(): void
    {
        $name = (new Parser())->parse('Smith, (');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getFirstname());
    }

    public function testPartialMultiWordSalutationIsNotMatched(): void
    {
        $parser = new Parser();
        $parser->setMaxSalutationIndex(10);

        $name = $parser->parse('Smith, Her');
        $this->assertSame('', $name->getSalutation());
        $this->assertSame('Her', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());

        $full = $parser->parse('Her Honour Mary Smith');
        $this->assertSame('Her Honour', $full->getSalutation());
    }

    public function testEmptyWhitespaceSetDoesNotWarn(): void
    {
        $name = (new Parser())->setWhitespace('')->parse('John Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function gluedCloserPunctuationProvider(): array
    {
        return [
            'semicolon after closer' => ['John Smith (Bob);', 'Smith'],
            'period after closer'    => ['John Smith (Bob).', 'Smith'],
            'closer mid-name'        => ['John (Bob); Smith', 'Smith'],
        ];
    }

    #[DataProvider('gluedCloserPunctuationProvider')]
    public function testCloserWithGluedPunctuationClosesSpan(string $input, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame($last, $name->getLastname());
        $this->assertSame('Bob', $name->getNickname());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function suffixCollidingSpanTailProvider(): array
    {
        return [
            'paren span ending Jr'   => ['John Doe (Bob Jr)', 'Doe', 'Bob Jr'],
            'quote span ending Jr'   => ["John Doe 'Bob, Jr'", 'Doe', 'Bob, Jr'],
        ];
    }

    #[DataProvider('suffixCollidingSpanTailProvider')]
    public function testTrailingSpanEndingInSuffixCollidingWordStaysNickname(
        string $input,
        string $last,
        string $nickname,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame($last, $name->getLastname());
        $this->assertSame($nickname, $name->getNickname());
        $this->assertSame('', $name->getSuffix());
    }

    public function testSelfBalancedQuotedTokenDoesNotCloseElidedParticle(): void
    {
        // 'Genius' closes itself, not the apostrophe in 't.
        $name = (new Parser())->parse("'t Hooft, Gerard 'Genius'");

        $this->assertSame('Gerard', $name->getFirstname());
        $this->assertSame("'T Hooft", $name->getLastname());
        $this->assertSame('Genius', $name->getNickname());

        $spaceForm = (new Parser())->parse("Gerard 't Hooft 'Genius'");

        $this->assertSame('Gerard', $spaceForm->getFirstname());
        $this->assertSame('Hooft', $spaceForm->getLastname());
        $this->assertSame("'T", $spaceForm->getMiddlename());
        $this->assertSame('Genius', $spaceForm->getNickname());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trailingPlaceholderProvider(): array
    {
        return [
            'dash'      => ['John Smith -'],
            'semicolon' => ['John Smith ;'],
        ];
    }

    #[DataProvider('trailingPlaceholderProvider')]
    public function testTrailingLetterlessPlaceholderIsNotASurname(string $input): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testInteriorLetterlessTokenKeepsOldSeparatorReading(): void
    {
        $name = (new Parser())->parse('John - Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }
}
