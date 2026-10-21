<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfidenceTest extends TestCase
{
    private const int MAX_INPUT_BYTES = 1024 * 1024;

    private const int MAX_INPUT_TOKENS = 65536;

    /**
     * @return array<string, array{string}>
     */
    public static function ambiguousProvider(): array
    {
        return [
            'all caps, DO collides'        => ['ANH TRAN DO'],
            'all lower, do collides'       => ['anh tran do'],
            'all lower comma, do collides' => ['smith, do'],
            'all caps comma, VI collides'  => ['NGUYEN, VI'],
            'all caps surname-collider II'  => ['JOHN SMITH II'],
            'all caps surname-collider MBA' => ['JANE DOE MBA'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decidableProvider(): array
    {
        return [
            'title-case surname Do'    => ['Anh Tran Do'],
            'title-case given Vi'      => ['Nguyen, Vi'],
            'all-caps credential DDS'  => ['Jane Doe DDS'],
            'comma credential DO'      => ['Robert Brown, DO'],
            'plain name'               => ['John Doe'],
            'all-caps credential RN'   => ['DONNA BARRETT, RN'],
            'all-caps credential PT'   => ['MARY JONES, PT'],
        ];
    }

    public function testSuffixFilterScopesAmbiguousKeys(): void
    {
        $this->assertTrue(Confidence::assess('ANH TRAN DO')['ambiguous']);
        $this->assertFalse(Confidence::assess('ANH TRAN DO', [])['ambiguous']);
        $this->assertFalse(Confidence::assess('ANH TRAN DO', ['md' => 'MD'])['ambiguous']);
        $this->assertTrue(Confidence::assess('ANH TRAN DO', ['do' => 'DO'])['ambiguous']);
    }

    #[DataProvider('ambiguousProvider')]
    public function testFlagsUninformativeCasing(string $input): void
    {
        $result = Confidence::assess($input);

        $this->assertTrue($result['ambiguous'], "expected '$input' to be flagged ambiguous");
        $this->assertNotEmpty($result['notes']);
    }

    #[DataProvider('decidableProvider')]
    public function testDoesNotFlagDecidableInput(string $input): void
    {
        $result = Confidence::assess($input);

        $this->assertFalse($result['ambiguous'], "expected '$input' to be decidable");
        $this->assertSame([], $result['notes']);
    }

    public function testFlagsLowercaseTokenInMixedCaseInput(): void
    {
        $result = Confidence::assess('John Smith do');

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(
            ["'do' could be a name or a credential; token is lowercase"],
            $result['notes'],
        );
    }

    public function testFlagsPunctuationSuffixedAmbiguousToken(): void
    {
        $result = Confidence::assess('NGUYEN, VI;');

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(
            ["'VI;' could be a name or a credential; input casing is uniform"],
            $result['notes'],
        );
    }

    public function testFlagsParenWrappedAmbiguousToken(): void
    {
        $result = Confidence::assess('SMITH, JOHN (DO)');

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(
            ["'(DO)' could be a name or a credential; input casing is uniform"],
            $result['notes'],
        );
    }

    public function testInvalidUtf8IsFlaggedInsteadOfPassingAsEmptyInput(): void
    {
        $result = Confidence::assess("Lord Ashcroft\xFF");

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(['input is not valid UTF-8'], $result['notes']);
    }

    public function testRepeatedAmbiguityReasonsAreDeduplicated(): void
    {
        $result = Confidence::assess('do do do');

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(
            ["'do' could be a name or a credential; input casing is uniform"],
            $result['notes'],
        );
    }

    public function testStandaloneAssessmentKeepsDefaultEnglishSalutationScope(): void
    {
        $result = Confidence::assess('Lord Ashcroft');

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(
            ["'Lord' could be a name or a salutation; nothing in the input decides it"],
            $result['notes'],
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decoratedTitleCollisionProvider(): array
    {
        return [
            'credential suffix' => ['Lord Ashcroft MD'],
            'generational suffix' => ['Lord Ashcroft Jr'],
            'trailing nickname' => ['Lord Ashcroft (Bob)'],
            'interior nickname' => ['Lord (Bob) Ashcroft'],
            'nickname containing comma' => ["Lord 'Bob, Jr' Ashcroft"],
            'empty comma tail' => ['Lord Ashcroft,'],
            'credential-only comma tail' => ['Lord Ashcroft, MD'],
        ];
    }

    #[DataProvider('decoratedTitleCollisionProvider')]
    public function testDecorationsDoNotHideTwoNamePartSalutationCollision(string $input): void
    {
        $result = Confidence::assess($input);

        $this->assertTrue($result['ambiguous'], "expected '$input' to be flagged ambiguous");
        $this->assertSame(
            ["'Lord' could be a name or a salutation; nothing in the input decides it"],
            $result['notes'],
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function parsedSuffixAndWrappedTitleCollisionProvider(): array
    {
        return [
            'single-letter roman V' => ['Lord Ashcroft V', 'Lord'],
            'single-letter roman I' => ['Lord Ashcroft I', 'Lord'],
            'single-letter roman X' => ['Lord Ashcroft X', 'Lord'],
            'lowercase roman' => ['Lord Ashcroft v', 'Lord'],
            'all-lower roman' => ['lord ashcroft v', 'lord'],
            'all-caps roman' => ['LORD ASHCROFT V', 'LORD'],
            'roman before credential' => ['Lord Ashcroft I MD', 'Lord'],
            'pastor roman' => ['Pastor Gonzalez V', 'Pastor'],
            'dame roman' => ['Dame Smith I', 'Dame'],
            'double-quoted title' => ['"Lord" Ashcroft', '"Lord"'],
            'single-quoted title' => ["'Lord' Ashcroft", "'Lord'"],
            'parenthesized title' => ['(Lord) Ashcroft', '(Lord)'],
            'quoted pastor' => ['"Pastor" Gonzalez', '"Pastor"'],
            'trailing connector' => ['Lord Ashcroft and', 'Lord'],
            'wrapped title, trailing connector' => ['"Lord" Ashcroft and', '"Lord"'],
            'second title' => ['Lord Pastor Smith', 'Lord'],
            'wrapped title, second title' => ['(Lord) Pastor Smith', '(Lord)'],
        ];
    }

    #[DataProvider('parsedSuffixAndWrappedTitleCollisionProvider')]
    public function testTitleCollisionCountsNamesTheWayTheParseMapsThem(string $input, string $lead): void
    {
        $result = Confidence::assess($input);

        $this->assertTrue($result['ambiguous'], "expected '$input' to be flagged ambiguous");
        $this->assertSame(
            ["'{$lead}' could be a name or a salutation; nothing in the input decides it"],
            $result['notes'],
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decidedTitleCollisionProvider(): array
    {
        return [
            'no colliding title' => ['John Ashcroft V'],
            'two names after title' => ['Lord Ashcroft Smith'],
            'unambiguous title' => ['Dr. Ashcroft V'],
            'comma form' => ['Lord, Jack'],
            'span opened by wrapped title' => ['(Lord Bob) Ashcroft'],
            'double-quoted comma surname' => ['"Lord", Smith'],
            'single-quoted comma surname' => ["'Lord', Smith"],
            'parenthesized comma surname' => ['(Lord), Smith'],
            'wrapped comma surname run' => ['(Lord) (Bob), Smith'],
            'joint honorific' => ['Lord and Lady Smith'],
            'joint honorific with ampersand' => ['Lord & Lady Smith'],
            'joint honorific, colliding partner first' => ['Lady and Lord Smith'],
            'wrapped joint honorific' => ['(Lord) and (Lady) Smith'],
            'stacked Mr.' => ['Lord Mr. Ashcroft'],
            'stacked Rev.' => ['Lord Rev. Smith'],
            'stacked multi-word Rt Hon' => ['Lord Rt Hon Smith'],
            'stacked Sir' => ['Lord Sir Smith'],
        ];
    }

    #[DataProvider('decidedTitleCollisionProvider')]
    public function testDecidedTitleCollisionStaysUnflagged(string $input): void
    {
        $this->assertSame(['ambiguous' => false, 'notes' => []], Confidence::assess($input));
    }

    public function testNonEmptyStructuralCommaResolvesSalutationCollision(): void
    {
        $this->assertSame(
            ['ambiguous' => false, 'notes' => []],
            Confidence::assess('Lord, Ashcroft'),
        );
    }

    public function testStandaloneAssessmentRejectsInputOverByteBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(str_repeat('A', self::MAX_INPUT_BYTES + 1));
    }

    public function testStandaloneAssessmentRejectsInputOverTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(str_repeat('A ', self::MAX_INPUT_TOKENS) . 'A');
    }

    public function testInvalidUtf8StillRejectsInputOverTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(str_repeat('A ', self::MAX_INPUT_TOKENS) . "\xFF");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUtf8WhitespaceProvider(): array
    {
        return [
            'lone 0xFF' => ["\xFF"],
            'raw NBSP byte after space' => [" \xA0"],
        ];
    }

    #[DataProvider('invalidUtf8WhitespaceProvider')]
    public function testInvalidUtf8WhitespaceIsRejectedWithoutWarnings(string $whitespace): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Whitespace characters must be valid UTF-8.');

        Confidence::assess('Lord Ashcroft', whitespace: $whitespace);
    }

    public function testNulWhitespaceIsStrippedBeforeConfidenceTokenization(): void
    {
        $this->assertSame(
            ['ambiguous' => false, 'notes' => []],
            Confidence::assess("Lord\x00Ashcroft", whitespace: "\x00"),
        );
    }

    public function testNulStrippingAppliesAtTheTokenLimit(): void
    {
        $input = str_repeat("A\x00", self::MAX_INPUT_TOKENS) . 'A';

        $this->assertSame(
            ['ambiguous' => false, 'notes' => []],
            Confidence::assess($input, whitespace: "\x00"),
        );
    }

    public function testEmptyWhitespacePreservesTabsBeforeConfidenceTokenization(): void
    {
        $this->assertSame(
            ['ambiguous' => false, 'notes' => []],
            Confidence::assess("D\tO smith", whitespace: ''),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function numericNicknameDelimiterProvider(): array
    {
        $cases = [];
        for ($digit = 0; $digit <= 9; ++$digit) {
            $cases['digit ' . $digit] = [(string) $digit];
        }

        return $cases;
    }

    #[DataProvider('numericNicknameDelimiterProvider')]
    public function testNumericNicknameDelimitersShieldCommas(string $delimiter): void
    {
        $delimiters = [$delimiter => $delimiter];
        $input = 'John ' . $delimiter . 'foo,do,bar' . $delimiter . ' Smith';
        $name = (new Parser())->setNicknameDelimiters($delimiters)->parse($input);

        $this->assertSame('Foo,Do,Bar', $name->getNickname());
        $this->assertSame(['ambiguous' => false, 'notes' => []], $name->getConfidence());
        $this->assertSame(
            $name->getConfidence(),
            Confidence::assess($input, nicknameDelimiters: $delimiters),
        );

        // The comma after the closed nickname must remain structural.
        $this->assertTrue(Confidence::assess(
            $input . ', do',
            nicknameDelimiters: $delimiters,
        )['ambiguous']);
        // An unmatched opener must not hide the lowercase credential.
        $this->assertTrue(Confidence::assess(
            'John ' . $delimiter . 'foo,do,bar Smith',
            nicknameDelimiters: $delimiters,
        )['ambiguous']);
    }

    public function testManyConfiguredDelimitersStayWithinMemoryBudget(): void
    {
        $input = 'X ' . str_repeat('a', (1024 * 1024) - 10) . ', Y';
        /** @var array<string, string> $delimiters */
        $delimiters = [];
        /** @var list<string> $delimiterCharacters */
        $delimiterCharacters = ['"', "'", 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', '0', '1', '2', '3', '4'];
        foreach ($delimiterCharacters as $delimiter) {
            $delimiters[$delimiter] = $delimiter;
        }

        $this->assertFalse(Confidence::assess($input, nicknameDelimiters: $delimiters)['ambiguous']);
    }

    public function testLargeCommaOnlyInputWithIgnoredDelimiterDoesNotMaterializeSegments(): void
    {
        $input = str_repeat(',', 1024 * 1024);
        $delimiter = str_repeat('x', 65);

        $this->assertFalse(Confidence::assess(
            $input,
            nicknameDelimiters: [$delimiter => $delimiter],
        )['ambiguous']);
    }

    public function testLargeSpaceSeparatedInputStopsAtTokenLimit(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(str_repeat('A ', 1024 * 512));
    }

    public function testSuppliedTokensStillValidateLargeOriginalTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(
            str_repeat('A ', 1024 * 512),
            tokens: ['A'],
        );
    }

    public function testSelfBalancedQuoteCloserCannotBypassTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess("Lord ' " . str_repeat('A,', 65536) . "'Genius' Ashcroft");
    }

    public function testExplicitEmptyDelimiterArrayRestoresDefaultNicknameParity(): void
    {
        $input = "Lord 'Bob, Jr' Ashcroft";
        $parser = (new Parser())->setNicknameDelimiters([]);

        $this->assertSame(
            $parser->parse($input)->getConfidence(),
            Confidence::assess($input, nicknameDelimiters: []),
        );
    }

    public function testInvalidUtf8InputCannotBypassTheTokenLimit(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess('Lord ' . str_repeat('A,', 70000) . " \xFE");
    }

    public function testLeadingSymmetricQuoteBoundaryMatchesParserConfidence(): void
    {
        $input = "'Bob, Jr' Ashcroft";
        $parser = new Parser();

        $this->assertSame(
            $parser->parse($input)->getConfidence(),
            Confidence::assess($input),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shieldedCommaLimitProvider(): array
    {
        return [
            '65533 commas' => ['Lord (' . str_repeat(',', 65533) . str_repeat('X', 30) . ') Ashcroft'],
            '65534 commas' => ['Lord (' . str_repeat(',', 65534) . str_repeat('X', 30) . ') Ashcroft'],
        ];
    }

    #[DataProvider('shieldedCommaLimitProvider')]
    public function testShieldedCommasDoNotCountAsConfidenceTokens(string $input): void
    {
        $this->assertSame(
            ["'Lord' could be a name or a salutation; nothing in the input decides it"],
            Confidence::assess($input)['notes'],
        );
    }

    public function testSuppliedTokensDoNotBypassByteBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(
            str_repeat('A', self::MAX_INPUT_BYTES + 1),
            tokens: ['John', 'Smith'],
        );
    }

    public function testSuppliedTokensDoNotBypassTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(
            'John Smith',
            tokens: array_fill(0, self::MAX_INPUT_TOKENS + 1, 'A'),
        );
    }

    public function testSuppliedTokensDoNotBypassOriginalTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(
            str_repeat('A ', self::MAX_INPUT_TOKENS) . 'A',
            tokens: ['A'],
        );
    }

    public function testSuppliedTokensHaveAnAggregateByteBudget(): void
    {
        $this->expectException(\LengthException::class);

        Confidence::assess(
            'John Smith',
            tokens: [str_repeat('A', self::MAX_INPUT_BYTES + 1)],
        );
    }
}
