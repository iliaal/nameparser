<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Language\German;
use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GermanParserTest extends TestCase
{
    /**
     * @return array<int, array{string, array<string, string>}>
     */
    public static function provider(): array
    {
        return [
            [
                'Herr Schmidt',
                [
                    'salutation' => 'Herr',
                    'lastname' => 'Schmidt',
                ],
            ],
            [
                'Frau Maria Lange',
                [
                    'salutation' => 'Frau',
                    'firstname' => 'Maria',
                    'lastname' => 'Lange',
                ],
            ],
            [
                'Hr. Juergen von der Lippe',
                [
                    'salutation' => 'Herr',
                    'firstname' => 'Juergen',
                    'lastname' => 'von der Lippe',
                ],
            ],
            [
                'Fr. Charlotte von Stein',
                [
                    'salutation' => 'Frau',
                    'firstname' => 'Charlotte',
                    'lastname' => 'von Stein',
                ],
            ],
            [
                'Friedrich Wilhelm 2.',
                [
                    'firstname' => 'Friedrich',
                    'lastname' => 'Wilhelm',
                    'suffix' => '2.',
                ],
            ],
            [
                'Otto von Bismarck II',
                [
                    'firstname' => 'Otto',
                    'lastname' => 'von Bismarck',
                    'suffix' => 'II',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $expectation
     */
    #[DataProvider('provider')]
    public function testParse(string $input, array $expectation): void
    {
        $parser = new Parser([
            new German(),
        ]);
        $name = $parser->parse($input);

        $this->assertInstanceOf(Name::class, $name);
        $this->assertEquals($expectation, $name->getAll());
    }

    public function testLanguageOrderIsFirstWins(): void
    {
        $germanFirst = new Parser([new German(), new English()]);
        $englishFirst = new Parser([new English(), new German()]);

        $this->assertSame('Frau', $germanFirst->parse('Fr. Charlotte Stein')->getSalutation());
        $this->assertSame('Fr.', $englishFirst->parse('Fr. Charlotte Stein')->getSalutation());
    }

    public function testEnglishCanBeComposedWithGermanForProfessionalCredentials(): void
    {
        $name = (new Parser([new English(), new German()]))->parse('Herr Hans Schmidt MD');

        $this->assertSame('Herr', $name->getSalutation());
        $this->assertSame('Hans', $name->getFirstname());
        $this->assertSame('Schmidt', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testGermanOnlyDoesNotLoadEnglishCredentials(): void
    {
        $name = (new Parser([new German()]))->parse('Herr Hans Schmidt MD');

        $this->assertSame('Herr', $name->getSalutation());
        $this->assertSame('Hans', $name->getFirstname());
        $this->assertSame('', $name->getSuffix());
        // MD is not in the German dictionary, so it stays in the name stream
        $this->assertStringContainsStringIgnoringCase('md', $name->getLastname() . $name->getMiddlename());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function absentAmbiguousSuffixProvider(): array
    {
        return [
            'Vi' => ['Vi'],
            'Do' => ['Do'],
            'Rn' => ['Rn'],
            'Mba' => ['Mba'],
            'Vii' => ['Vii'],
        ];
    }

    #[DataProvider('absentAmbiguousSuffixProvider')]
    public function testGermanOnlyCommaNameKeepsAbsentAmbiguousSuffixAsGivenName(string $given): void
    {
        $name = (new Parser([new German()]))->parse("Smith, {$given}");

        $this->assertSame($given, $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getSuffix());
    }

    public function testGermanJointHonorificConnector(): void
    {
        // "und" comes from German::getConnectors(); without it the conjunction
        // was title-cased into the first name and Frau exported as middle name
        $name = (new Parser([new German()]))->parse('Herr und Frau Schmidt');

        $this->assertSame('Herr und Frau', $name->getSalutation());
        $this->assertSame(['Herr', 'Frau'], $name->getSalutations());
        $this->assertSame('', $name->getFirstname());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('Schmidt', $name->getLastname());
        $this->assertTrue($name->isJoint());

        $partner = $name->getPartner();
        $this->assertNotNull($partner);
        $this->assertSame('Frau', $partner->getSalutation());
        $this->assertSame('Schmidt', $partner->getLastname());
    }

    public function testGermanJointHonorificWithEnglishFirst(): void
    {
        $name = (new Parser([new English(), new German()]))->parse('Herr und Frau Schmidt');

        $this->assertSame('Herr und Frau', $name->getSalutation());
        $this->assertTrue($name->isJoint());
        $this->assertSame('Schmidt', $name->getLastname());
    }

    public function testEnglishConnectorStillWorksAlongsideGerman(): void
    {
        $name = (new Parser([new English(), new German()]))->parse('Mr. and Mrs. Brad Smith');

        $this->assertSame('Mr. and Mrs.', $name->getSalutation());
        $this->assertTrue($name->isJoint());
        $this->assertSame('Brad', $name->getFirstname());
    }
}
