<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type ConfidenceConfiguration array{confidenceSuffixes?: array<string, string>, confidenceSalutations?: array<string, string>, confidenceNicknameDelimiters?: array<string, string>, confidenceWhitespace?: string, confidenceConnectors?: array<string, string>}
 */
class NameConfidenceProvenanceTest extends TestCase
{
    private const array UNAMBIGUOUS = ['ambiguous' => false, 'notes' => []];

    private const array CREDENTIAL_AMBIGUITY = [
        'ambiguous' => true,
        'notes' => ["'VI' could be a name or a credential; input casing is uniform"],
    ];

    private const array TITLE_AMBIGUITY = [
        'ambiguous' => true,
        'notes' => ["'Lord' could be a name or a salutation; nothing in the input decides it"],
    ];

    public function testPartEditsKeepParsedConfidenceProvenance(): void
    {
        $name = (new Parser())->parse('NGUYEN, VI');
        $this->assertSame(self::CREDENTIAL_AMBIGUITY, $name->getConfidence());

        foreach ($name->getParts() as $part) {
            if ($part instanceof Lastname) {
                $part->setValue('smith');
            }
        }
        $this->assertSame('Smith VI', (string) $name);
        $this->assertSame('NGUYEN, VI', $name->getSource());
        $this->assertSame(self::CREDENTIAL_AMBIGUITY, $name->getConfidence());

        $name->setParts([new Firstname('Jane'), new Lastname('Smith')]);
        $this->assertSame('Jane Smith', (string) $name);
        $this->assertSame('NGUYEN, VI', $name->getSource());
        $this->assertSame(self::CREDENTIAL_AMBIGUITY, $name->getConfidence());
    }

    public function testManualNameWithoutSourceAssessesItsRenderedParts(): void
    {
        $parsed = (new Parser())->parse('NGUYEN, VI');
        $manual = new Name($parsed->getParts());
        $this->assertNull($manual->getSource());
        $this->assertSame('Nguyen VI', (string) $manual);
        $this->assertSame(self::UNAMBIGUOUS, $manual->getConfidence());
        $this->assertSame(self::CREDENTIAL_AMBIGUITY, $parsed->getConfidence());

        $manual->setParts([new Firstname('Lord'), new Lastname('Ashcroft')]);
        $this->assertSame('Lord Ashcroft', (string) $manual);
        $this->assertSame(self::TITLE_AMBIGUITY, $manual->getConfidence());
    }

    /**
     * @return iterable<string, array{string, ConfidenceConfiguration, bool}>
     */
    public static function manualConfigurationCases(): iterable
    {
        yield 'suffixes' => ['Lord Ashcroft MD', ['confidenceSuffixes' => []], false];
        yield 'salutations' => ['Lord Ashcroft', ['confidenceSalutations' => []], false];
        yield 'nickname delimiters' => [
            'Lord {Bob} Ashcroft', ['confidenceNicknameDelimiters' => ['<' => '>']], false,
        ];
        yield 'whitespace' => ['Lord|Ashcroft', ['confidenceWhitespace' => '|'], true];
        yield 'connectors' => ['Lord Ashcroft und', ['confidenceConnectors' => ['und' => 'und']], true];
    }

    /**
     * @param  ConfidenceConfiguration  $configuration
     */
    #[DataProvider('manualConfigurationCases')]
    public function testManualFallbackHonorsEachConfigurationOption(string $input, array $configuration, bool $ambiguous): void
    {
        // A part can contain spaces; no Parser or stored source supplies these options.
        $parts = [new Firstname($input)];
        $default = new Name($parts);
        $configured = new Name($parts, ...$configuration);

        $this->assertNull($configured->getSource());
        $this->assertSame((string) $default, (string) $configured);
        $this->assertSame($ambiguous ? self::UNAMBIGUOUS : self::TITLE_AMBIGUITY, $default->getConfidence());
        $this->assertSame($ambiguous ? self::TITLE_AMBIGUITY : self::UNAMBIGUOUS, $configured->getConfidence());
    }

    public function testSetSourceReplacesTokensAndOmittingThemClearsTheSnapshot(): void
    {
        $name = (new Parser())->parse('NGUYEN, VI');
        $name->setSource('Lord Ashcroft', ['Lord', 'Ashcroft']);
        $this->assertSame(self::TITLE_AMBIGUITY, $name->getConfidence());
        $this->assertSame('Lord Ashcroft', $name->getSource());
        $this->assertSame('Nguyen VI', (string) $name);

        $name->setSource('John Smith');
        $this->assertSame('John Smith', $name->getSource());
        $this->assertSame(self::UNAMBIGUOUS, $name->getConfidence());
        $this->assertSame('Nguyen VI', (string) $name);
    }

    public function testEmptyStoredSourceDoesNotFallBackToRenderedParts(): void
    {
        $name = new Name([new Firstname('Lord'), new Lastname('Ashcroft')]);
        $this->assertSame(self::TITLE_AMBIGUITY, $name->getConfidence());
        $name->setSource('');
        $this->assertSame('', $name->getSource());
        $this->assertSame('Lord Ashcroft', (string) $name);
        $this->assertSame(self::UNAMBIGUOUS, $name->getConfidence());
    }

    public function testExplicitTokenSnapshotSurvivesEditsAndPartnerExtraction(): void
    {
        $name = (new Parser())->parse('Lord and Lady Ashcroft');
        // Deliberately distinct source/tokens make re-tokenization observable.
        $name->setSource('John Smith', ['NGUYEN', 'VI']);
        $partner = $name->getPartner();
        $this->assertNotNull($partner);
        $this->assertSame('John Smith', $partner->getSource());
        $this->assertSame(self::CREDENTIAL_AMBIGUITY, $partner->getConfidence());

        $name->setParts([new Firstname('Jane'), new Lastname('Smith')]);
        $this->assertSame(self::CREDENTIAL_AMBIGUITY, $name->getConfidence());
        $name->setSource('John Smith');
        $this->assertSame(self::UNAMBIGUOUS, $name->getConfidence());
        $this->assertSame(self::CREDENTIAL_AMBIGUITY, $partner->getConfidence());
    }

    public function testPartnerRetainsParsedSourceInsteadOfAssessingItsDisplay(): void
    {
        $name = (new Parser())->parse('Lord and Lady Ashcroft');
        $partner = $name->getPartner();
        $this->assertNotNull($partner);
        $this->assertSame('Lady Ashcroft', (string) $partner);
        $this->assertSame('Lord and Lady Ashcroft', $partner->getSource());
        $this->assertSame(self::UNAMBIGUOUS, $name->getConfidence());
        $this->assertSame(self::UNAMBIGUOUS, $partner->getConfidence());
        $this->assertSame([
            'ambiguous' => true,
            'notes' => ["'Lady' could be a name or a salutation; nothing in the input decides it"],
        ], (new Name($partner->getParts()))->getConfidence());
    }
}
