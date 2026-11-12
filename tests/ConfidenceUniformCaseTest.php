<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfidenceUniformCaseTest extends TestCase
{
    #[DataProvider('uniformCases')]
    public function testUniformCasePreservesExactNotes(string $input, string $reason): void
    {
        $expected = $reason === '' ? [] : ["'do' could be a name or a credential; {$reason}"];

        $this->assertSame(
            ['ambiguous' => $expected !== [], 'notes' => $expected],
            Confidence::assess($input),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uniformCases(): iterable
    {
        foreach (['李', 'דוד', 'محمد', '123', '🙂', "\u{0301}", 'Ⅰ', ''] as $uncased) {
            yield 'uncased before lowercase ' . $uncased => [$uncased . ' do', 'input casing is uniform'];
            yield 'uncased after lowercase ' . $uncased => ['do ' . $uncased, 'input casing is uniform'];
            yield 'uncased only ' . $uncased => [$uncased, ''];
        }

        foreach (['anh', 'αβ', 'аб', 'ß', 'ﬃ', 'ς', "\u{10428}", "\u{0345}", "e\u{0301}"] as $lower) {
            yield 'lowercase before collision ' . $lower => [$lower . ' do', 'input casing is uniform'];
            yield 'lowercase after collision ' . $lower => ['do ' . $lower, 'input casing is uniform'];
        }

        foreach (['Anh', 'ANH', 'ΑΒ', 'ǅ', 'ẞ', 'İ', "\u{10400}", "E\u{0301}"] as $notLower) {
            yield 'nonlower before collision ' . $notLower => [$notLower . ' do', 'token is lowercase'];
            yield 'nonlower after collision ' . $notLower => ['do ' . $notLower, 'token is lowercase'];
        }
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('upperCases')]
    public function testUppercaseCollisionsKeepTheirScope(string $input, array $expected): void
    {
        $this->assertSame(['ambiguous' => $expected !== [], 'notes' => $expected], Confidence::assess($input));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function upperCases(): iterable
    {
        foreach (['李', 'דוד', '123', "\u{0301}", 'Ⅰ', 'ΑΒ', 'ẞ', 'İ', "\u{10400}"] as $token) {
            yield 'upper name collider ' . $token => [$token . ' DO', ["'DO' could be a name or a credential; input casing is uniform"]];
            yield 'upper clean credential ' . $token => [$token . ' RN', []];
        }

        yield 'mixed tokens' => ['ANH tran DO', []];
        yield 'titlecase token' => ['ǅ DO', []];
        yield 'cased combining mark' => ["\u{0345} DO", []];
        yield 'lowercase follows upper' => ['DO do', ["'do' could be a name or a credential; token is lowercase"]];
        yield 'note order and deduplication' => ['vi do vi', ["'vi' could be a name or a credential; input casing is uniform", "'do' could be a name or a credential; input casing is uniform"]];
    }

    public function testSuppliedTokensRemainTheCaseSignal(): void
    {
        $this->assertSame(
            ['ambiguous' => true, 'notes' => ["'do' could be a name or a credential; input casing is uniform"]],
            Confidence::assess('Mixed Source', tokens: ["BAD\xFF", 'do', '李']),
        );
        $this->assertSame(
            ['ambiguous' => false, 'notes' => []],
            Confidence::assess('do', suffixes: [], tokens: ['do']),
        );
    }
}
