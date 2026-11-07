<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    #[DataProvider('controlCharacterCases')]
    public function testStripControlChars(string $text, string $expected, bool $preserveTab): void
    {
        $this->assertSame($expected, Text::stripControlChars($text, $preserveTab));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function controlCharacterCases(): iterable
    {
        $controls = '';
        foreach ([...range(0, 31), ...range(127, 159)] as $codepoint) {
            $controls .= mb_chr($codepoint, 'UTF-8');
        }

        // Format controls include supplementary-plane tags, not just C0/C1 bytes.
        $formats = "\u{00AD}\u{061C}\u{200B}\u{200C}\u{200D}\u{202E}\u{2066}\u{FEFF}\u{E0001}\u{E007F}";
        $visible = "É李\u{FE00}e\u{0301}";

        yield 'remove every C0 and C1 control' => [$visible . $controls . $visible, $visible . $visible, false];
        yield 'preserve only tab among C0 and C1 controls' => [$visible . $controls . $visible, $visible . "\t" . $visible, true];
        yield 'remove format controls' => [$formats . $visible . $formats, $visible, false];
        yield 'remove format controls while preserving tabs' => ["\t" . $formats . $visible . $formats . "\t", "\t" . $visible . "\t", true];
        yield 'preserve adjacent tabs' => ["\t\tJohn\t\tSmith\t\t", "\t\tJohn\t\tSmith\t\t", true];
        yield 'remove adjacent tabs' => ["\t\tJohn\t\tSmith\t\t", 'JohnSmith', false];
        yield 'empty input' => ['', '', true];
    }

    #[DataProvider('unknownCredentialCandidates')]
    public function testUnknownCredentialCandidate(string $token, bool $expected): void
    {
        $this->assertSame($expected, Text::isUnknownCredentialCandidate($token));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function unknownCredentialCandidates(): iterable
    {
        yield 'uppercase credential' => ['FACS', true];
        yield 'punctuated credential' => ['F.A.C.S.', true];
        yield 'mixed case' => ['Facs', false];
        yield 'lowercase' => ['facs', false];
        yield 'single letter' => ['A', false];
        yield 'single grapheme with combining mark' => ["E\u{0301}", false];
        yield 'two graphemes with combining mark' => ["E\u{0301}A", true];
        yield 'Greek uppercase' => ['ΑΒ', true];
        yield 'caseless script' => ['李王', false];
        yield 'digits only' => ['123', false];
        yield 'empty' => ['', false];
        yield 'invalid UTF-8' => ["AB\xFF", false];

        foreach (['(', ')', '[', ']', '{', '}', '<', '>', '"', "'"] as $delimiter) {
            yield 'nickname delimiter ' . $delimiter => [$delimiter . 'FACS', false];
        }
    }
}
