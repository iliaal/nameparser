<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfidenceEncodingTest extends TestCase
{
    /**
     * @return iterable<string, array{int|string, string, string, list<string>}>
     */
    public static function malformedInputProvider(): iterable
    {
        foreach (['question mark' => [63, '?'], 'replacement character' => [0xFFFD, "\u{FFFD}"], 'removal' => ['none', '']] as $mode => [$setting, $replacement]) {
            yield $mode . ': Western name' => [$setting, "John Smith\xFF", 'John Smith' . $replacement, []];
            yield $mode . ': comma name' => [$setting, "Smith, John\xFF", 'Smith, John' . $replacement, []];
            yield $mode . ': invalid-only input' => [$setting, "\xFF", $replacement, []];
            yield $mode . ': credential ambiguity' => [$setting, "ANH\xFF TRAN DO", 'ANH' . $replacement . ' TRAN DO',
                ["'DO' could be a name or a credential; input casing is uniform"]];
            yield $mode . ': title ambiguity' => [$setting, "Lord Ashcroft\xFF", 'Lord Ashcroft' . $replacement,
                ["'Lord' could be a name or a salutation; nothing in the input decides it"]];
        }
    }

    /**
     * @param  list<string>  $notes
     */
    #[DataProvider('malformedInputProvider')]
    public function testParsedConfidenceUsesScrubbedSource(int|string $setting, string $input, string $source, array $notes): void
    {
        $previous = mb_substitute_character();
        mb_substitute_character($setting);

        try {
            $this->assertSame(
                ['ambiguous' => true, 'notes' => ['input is not valid UTF-8']],
                Confidence::assess($input),
            );
            $name = (new Parser())->parse($input);
            $this->assertSame($source, $name->getSource());
            $this->assertSame(['ambiguous' => $notes !== [], 'notes' => $notes], $name->getConfidence());
            $this->assertSame($setting, mb_substitute_character());
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * @return iterable<string, array{int|string, string}>
     */
    public static function substitutionProvider(): iterable
    {
        yield 'question mark' => [63, '?'];
        yield 'replacement character' => [0xFFFD, "\u{FFFD}"];
        yield 'removal' => ['none', ''];
    }

    #[DataProvider('substitutionProvider')]
    public function testPartnerRetainsScrubbedConfidenceProvenance(int|string $setting, string $replacement): void
    {
        $previous = mb_substitute_character();
        mb_substitute_character($setting);

        try {
            $name = (new Parser())->parse("MR. AND MRS. ANH\xFF TRAN DO");
            $partner = $name->getPartner();
            $this->assertNotNull($partner);
            $source = 'MR. AND MRS. ANH' . $replacement . ' TRAN DO';
            $expected = ['ambiguous' => true, 'notes' => ["'DO' could be a name or a credential; input casing is uniform"]];
            $this->assertSame($source, $name->getSource());
            $this->assertSame($source, $partner->getSource());
            $this->assertSame($expected, $name->getConfidence());
            $this->assertSame($expected, $partner->getConfidence());
        } finally {
            mb_substitute_character($previous);
        }
    }

    #[DataProvider('substitutionProvider')]
    public function testValidUnicodeNeedsNoEncodingWarning(int|string $setting, string $replacement): void
    {
        $previous = mb_substitute_character();
        mb_substitute_character($setting);

        try {
            $input = 'André Smith';
            $expected = ['ambiguous' => false, 'notes' => []];
            $this->assertSame($expected, Confidence::assess($input));
            $name = (new Parser())->parse($input);
            $this->assertSame($input, $name->getSource());
            $this->assertSame($expected, $name->getConfidence());
        } finally {
            mb_substitute_character($previous);
        }
    }
}
