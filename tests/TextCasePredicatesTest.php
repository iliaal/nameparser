<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextCasePredicatesTest extends TestCase
{
    #[DataProvider('casePredicates')]
    public function testCasePredicates(string $token, bool $upper, bool $lower, bool $cased): void
    {
        $this->assertSame($upper, Text::isUpperCase($token));
        $this->assertSame($lower, Text::isLowerCase($token));
        $this->assertSame($cased, Text::isCased($token));

        $analysis = Text::analyzeToken($token);
        $this->assertSame($analysis['upper'], Text::isUpperCase($token));
        $this->assertSame($analysis['lower'], Text::isLowerCase($token));
        $this->assertSame($analysis['cased'], Text::isCased($token));
    }

    /**
     * @return iterable<string, array{string, bool, bool, bool}>
     */
    public static function casePredicates(): iterable
    {
        yield 'ASCII uppercase' => ['FACS', true, false, true];
        yield 'ASCII lowercase' => ['facs', false, true, true];
        yield 'mixed case' => ['Facs', false, false, true];
        yield 'punctuated uppercase' => ['M.D.-123', true, false, true];
        yield 'punctuated lowercase' => ["o'brien", false, true, true];
        yield 'Greek uppercase' => ['ΑΒ', true, false, true];
        yield 'Greek lowercase' => ['αβ', false, true, true];
        yield 'Cyrillic mixed case' => ['Иван', false, false, true];
        yield 'supplementary uppercase' => ["\u{10400}", true, false, true];
        yield 'supplementary lowercase' => ["\u{10428}", false, true, true];
        yield 'titlecase letter' => ['ǅ', false, false, true];
        yield 'expanding uppercase mapping' => ['ß', false, true, true];
        yield 'capital sharp S' => ['ẞ', true, false, true];
        yield 'expanding lowercase mapping' => ['İ', true, false, true];
        yield 'ligature' => ['ﬃ', false, true, true];
        yield 'final sigma' => ['ς', false, true, true];
        yield 'combining uppercase' => ["E\u{0301}", true, false, true];
        yield 'combining lowercase' => ["e\u{0301}", false, true, true];
        yield 'combining marks only' => ["\u{0301}\u{0308}", false, false, false];
        yield 'cased combining mark' => ["\u{0345}", false, true, true];
        yield 'Han' => ['李王', false, false, false];
        yield 'Hebrew' => ['דוד', false, false, false];
        yield 'Arabic' => ['محمد', false, false, false];
        yield 'Han and uppercase' => ['李A', true, false, true];
        yield 'Han and lowercase' => ['李a', false, true, true];
        yield 'uncased letter' => ['ª', false, false, false];
        yield 'nonletter case mappings ignored' => ['Ⅰⅰ', false, false, false];
        yield 'uppercase nonletter only' => ['Ⅰ', false, false, false];
        yield 'lowercase nonletter only' => ['ⅰ', false, false, false];
        yield 'uppercase letters with lowercase nonletter' => ['ABCⅰ', true, false, true];
        yield 'lowercase letters with uppercase nonletter' => ['abcⅠ', false, true, true];
        yield 'no letters' => ['123-🙂', false, false, false];
        yield 'empty' => ['', false, false, false];
        yield 'malformed UTF-8' => ["ABC\xFF", false, false, false];
        yield 'long uppercase' => [str_repeat('É', 1025), true, false, true];
        yield 'long mixed case' => [str_repeat('É', 1024) . 'é', false, false, true];
    }
}
