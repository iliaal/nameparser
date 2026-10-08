<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
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
