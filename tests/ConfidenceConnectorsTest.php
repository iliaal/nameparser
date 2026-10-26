<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use Iliaal\NameParser\ConnectorsInterface;
use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Language\German;
use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfidenceConnectorsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function inputProvider(): iterable
    {
        yield 'trailing' => ['Lord Ashcroft %s', true];
        yield 'middle' => ['Lord %s Ashcroft', true];
        yield 'missing surname' => ['Lord %s Lady', true];
        yield 'joint' => ['Lord %s Lady Ashcroft', false];
        yield 'deciding title' => ['Lord %s Rev. Ashcroft', false];
        yield 'nickname after connector' => ['Lord %s (Bob) Ashcroft', true];
        yield 'trailing title' => ['Lord Ashcroft %s Frau', true];
        yield 'nickname' => ['Lord Ashcroft (Bob) %s', true];
        yield 'credential' => ['Lord Ashcroft MD %s', true];
        yield 'empty comma' => ['Lord Ashcroft %s,', true];
        yield 'deciding comma' => ['Lord, Ashcroft %s', false];
        yield 'ordinary name' => ['John %s Smith', false];
    }

    #[DataProvider('inputProvider')]
    public function testConfiguredConnectorsMatchEnglish(string $template, bool $ambiguous): void
    {
        $parser = new Parser([new English(), new German()]);
        $input = sprintf($template, 'und');
        $name = $parser->parse($input);
        $this->assertSame($ambiguous, $name->getConfidence()['ambiguous']);
        $this->assertSame($parser->parse(sprintf($template, 'and'))->getConfidence(), $name->getConfidence());
        $this->assertSame($name->getConfidence(), Confidence::assess(
            $input,
            $parser->getSuffixes(),
            $parser->getSalutations(),
            connectors: $parser->getConnectors(),
        ));
    }

    public function testDefaultsAndEmptyDictionaryRemainEnglish(): void
    {
        $input = 'Lord Ashcroft und';
        $this->assertFalse((new Parser())->parse($input)->getConfidence()['ambiguous']);
        $this->assertFalse(Confidence::assess($input)['ambiguous']);
        $this->assertSame(Confidence::assess($input), Confidence::assess($input, connectors: []));
        $this->assertSame(Confidence::assess('Lord Ashcroft and'), Confidence::assess('Lord Ashcroft and', connectors: []));
    }

    public function testCustomDictionarySnapshotSurvivesParserReuse(): void
    {
        $parser = new class extends Parser {
            /**
             * @var array<string, string>
             */
            public array $dictionary = ['plus' => '+', 'and' => 'and', '&' => '&'];

            public function getConnectors(): array
            {
                return $this->dictionary;
            }
        };
        $name = $parser->parse('Lord Ashcroft plus');
        $parser->dictionary = ['and' => 'and', '&' => '&'];
        $parser->setWhitespace(' ');
        $this->assertFalse($parser->parse('Lord Ashcroft plus')->getConfidence()['ambiguous']);
        $this->assertTrue($name->getConfidence()['ambiguous']);
        $this->assertSame(['salutation' => 'Lord', 'lastname' => 'Ashcroft'], $name->getAll());
    }

    public function testCustomLanguageAndPartnerRetainConfiguration(): void
    {
        $language = new class extends English implements ConnectorsInterface {
            public function getConnectors(): array
            {
                return ['plus' => '+'];
            }
        };
        $parser = new Parser([$language]);
        $this->assertTrue($parser->parse('Lord Ashcroft plus')->getConfidence()['ambiguous']);
        $joint = $parser->parse('Lord plus Lady Ashcroft');
        $partner = $joint->getPartner();
        $this->assertNotNull($partner);
        $this->assertSame('Lord + Lady', $joint->getSalutation());
        $this->assertSame($joint->getConfidence(), $partner->getConfidence());
        // Change only provenance to make connector retention observable on the derived Name.
        $partner->setSource('Lord Ashcroft plus', ['Lord', 'Ashcroft', 'plus']);
        $this->assertTrue($partner->getConfidence()['ambiguous']);
    }

    public function testSurnameFirstAndManualNameUseConfiguredConnectors(): void
    {
        $parser = (new Parser([new English(), new German()]))->setSurnameFirst(true);
        $input = 'Lord Ashcroft und';
        $this->assertSame(
            $parser->parse('Lord Ashcroft and')->getConfidence(),
            $parser->parse($input)->getConfidence(),
        );
        $name = new Name(confidenceConnectors: ['und' => 'und']);
        $name->setSource($input);
        $this->assertTrue($name->getConfidence()['ambiguous']);
        $this->assertSame(['ambiguous' => false, 'notes' => []], (new Name())->getConfidence());
    }
}
