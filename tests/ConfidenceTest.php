<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
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
            // all-caps Census-surname colliders: casing carries no signal, so
            // the stripped roman numeral / MBA could equally be a surname
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
            // uppercase credential-leaning keys must not flag (data is often
            // all-caps; RN/PT strip cleanly and aren't name-leaning)
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
        // keying must strip trailing punctuation like the parser does, otherwise
        // "VI;" would miss the AMBIGUOUS_KEYS lookup that "VI" hits
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
}
