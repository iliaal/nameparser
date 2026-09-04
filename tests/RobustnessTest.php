<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Edge-case regressions: Unicode handling, multi-segment comma input, empty
 * nickname rendering, custom whitespace, and salutation-index overflow.
 */
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
        // input surname, caseless given name, expected suffix: caseless
        // scripts have no upper/lower case, so the all-uppercase split gate
        // must not fire and the given name stays whole with no bogus initials.
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
        // full suffix string: contains-asserts pass on reordered/duplicated
        // credentials ('PhD MD', 'MD MD PhD'), so the order is pinned exactly.
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
        // the elided particle survives verbatim; the pipeline title-cases it to 'T
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
        // U+3000 shares lead bytes with other CJK punctuation; a bytewise
        // pattern would eat those bytes out of unrelated glyphs
        $parser = (new Parser())->setWhitespace("\u{3000}");
        $name = $parser->parse("\u{7530}\u{4E2D}\u{3000}Smith\u{3002}X");

        $this->assertSame("\u{7530}\u{4E2D}", $name->getFirstname());
        $this->assertSame("Smith\u{3002}X", $name->getLastname());
        $this->assertTrue(mb_check_encoding($name->getLastname(), 'UTF-8'));
    }

    public function testInvalidUtf8WhitespaceFallsBackToBytewiseWithoutWarnings(): void
    {
        // /u cannot compile a pattern containing the raw byte; the pattern
        // drops to bytewise semantics instead of warning per parse
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
        // input, expected firstname, expected lastname: under the default
        // config the SCRUB policy replaces invalid bytes up front (mb_scrub
        // renders them as '?'), so the byte lands deterministically in its
        // field instead of degrading per call site, and phpunit's
        // failOnWarning proves the parse stays quiet.
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
        // failOnWarning turns the per-token preg compile warning into a failure
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
        // empty closer cannot close a span; drop the pair and fall back to no-op
        // nickname extraction rather than swallowing the token forever
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
        // phpunit.xml sets failOnWarning, so an undefined-array-key warning here
        // fails the test rather than passing silently.
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

    /**
     * A lone nickname delimiter is stripped to nothing, leaving no parts. The
     * parser must return an empty Name rather than throw, so one malformed cell
     * does not abort a batch import. failOnWarning also catches the undefined
     * array-key warning that preceded the TypeError.
     */
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

    /**
     * a degenerate whole-string input (blank or bare punctuation) yields an
     * all-empty Name: every toArray() key is present as '' and nothing warns or
     * throws, so one malformed cell cannot abort a batch import (failOnWarning
     * turns any stray warning into a failure).
     */
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

    /**
     * A one-token tail must not partial-match the first word of a multi-word
     * salutation pattern ("her honour"); "Her" stays a name, not a salutation,
     * even with the salutation scan reaching the final token.
     */
    public function testPartialMultiWordSalutationIsNotMatched(): void
    {
        $parser = new Parser();
        $parser->setMaxSalutationIndex(10);

        $name = $parser->parse('Smith, Her');
        $this->assertSame('', $name->getSalutation());
        $this->assertSame('Her', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());

        // the full multi-word salutation still matches
        $full = $parser->parse('Her Honour Mary Smith');
        $this->assertSame('Her Honour', $full->getSalutation());
    }

    /**
     * an empty whitespace set collapses nothing and must not emit an E_WARNING
     * from a degenerate "/[]+/" pattern (failOnWarning would catch it)
     */
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

    /**
     * a closing delimiter with glued trailing punctuation still closes the
     * span; the unclosed-opener revert must not leave "Bob)" as the surname
     */
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

    /**
     * the closer-bearing token of a trailing span keys as a suffix ("Jr)"),
     * but consuming it would orphan the opener; the nickname survives whole
     * and the surname stays the surname
     */
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
        // 'Genius' closes itself; its tail quote must not serve as the closer
        // for the elided-particle apostrophe in 't, or the whole row degrades
        // to nickname parts
        $name = (new Parser())->parse("'t Hooft, Gerard 'Genius'");

        $this->assertSame('Gerard', $name->getFirstname());
        $this->assertSame("'T Hooft", $name->getLastname());
        $this->assertSame('Genius', $name->getNickname());

        // the space form matches its no-nickname baseline ("Gerard 't Hooft"):
        // the elided particle survives as a middle name there
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
        // only a trailing placeholder is skipped; an interior one still stops
        // the surname scan the way it always did
        $name = (new Parser())->parse('John - Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }
}
