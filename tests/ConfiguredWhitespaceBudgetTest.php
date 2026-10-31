<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfiguredWhitespaceBudgetTest extends TestCase
{
    #[DataProvider('retainedTabRuns')]
    public function testRetainedTabsDoNotConsumeTheTokenBudget(int $pieces, string $mode): void
    {
        $token = str_repeat("anna\t", $pieces - 1) . 'anna';
        $rendered = str_repeat("Anna\t", $pieces - 1) . 'Anna';
        $input = match ($mode) {
            'comma' => 'Smith, ' . $token,
            'surname-first' => 'Smith ' . $token,
            default => $token,
        };
        $name = (new Parser())->setWhitespace('')
            ->setSurnameFirst($mode === 'surname-first')->parse($input);

        $this->assertSame($input, $name->getSource());
        $this->assertSame($rendered, $name->getFirstname());
        $this->assertSame($mode === 'Western' ? '' : 'Smith', $name->getLastname());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame(['ambiguous' => false, 'notes' => []], $name->getConfidence());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function retainedTabRuns(): iterable
    {
        foreach ([Text::MAX_INPUT_TOKENS - 1, Text::MAX_INPUT_TOKENS, Text::MAX_INPUT_TOKENS + 1] as $pieces) {
            foreach (['Western', 'comma', 'surname-first'] as $mode) {
                yield $mode . ' with ' . $pieces . ' tab-separated pieces' => [$pieces, $mode];
            }
        }
    }

    #[DataProvider('realTokenSeparators')]
    public function testRealSeparatorsStillEnforceTheTokenBudget(string $separator, string $whitespace, string $token): void
    {
        $input = str_repeat($token . $separator, Text::MAX_INPUT_TOKENS) . $token;
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('Name input exceeds the 65536-token limit.');

        (new Parser())->setWhitespace($whitespace)->parse($input);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function realTokenSeparators(): iterable
    {
        yield 'space with retained interior tabs' => [' ', '', "aa\tbb"];
        yield 'comma with retained interior tabs' => [',', '', "aa\tbb"];
        yield 'default whitespace collapses tabs' => ["\t", " \r\n\t", 'anna'];
        yield 'custom whitespace collapses tabs' => ["\t", "\t", 'anna'];
    }

    public function testNonemptyWhitespaceStripsUnconfiguredTabsBeforeBudgeting(): void
    {
        $pieces = Text::MAX_INPUT_TOKENS + 1;
        $input = str_repeat("anna\t", $pieces - 1) . 'anna';
        $name = (new Parser())->setWhitespace(' ')->parse($input);

        $this->assertSame(str_repeat('anna', $pieces), $name->getSource());
        $this->assertSame('Anna' . str_repeat('anna', $pieces - 1), $name->getFirstname());
        $this->assertSame('', $name->getLastname());
    }

    #[DataProvider('controlWhitespace')]
    public function testOtherControlsAreStrippedOrCollapsedBeforeTokenization(string $control, string $whitespace, bool $collapsed): void
    {
        $name = (new Parser())->setWhitespace($whitespace)->parse('anna' . $control . 'beth Smith');

        $this->assertSame($collapsed ? 'anna beth Smith' : 'annabeth Smith', $name->getSource());
        $this->assertSame($collapsed ? 'Anna' : 'Annabeth', $name->getFirstname());
        $this->assertSame($collapsed ? 'Beth' : '', $name->getMiddlename());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function controlWhitespace(): iterable
    {
        foreach (['LF' => "\n", 'CR' => "\r", 'VT' => "\x0B", 'FF' => "\x0C"] as $label => $control) {
            yield $label . ' with empty whitespace' => [$control, '', false];
            yield $label . ' with custom whitespace' => [$control, $control, true];
            yield $label . ' with default whitespace' => [$control, " \r\n\t", $control === "\n" || $control === "\r"];
        }
    }
}
