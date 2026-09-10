<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalutationCollisionTest extends TestCase
{
    use LoneSalutationCases;

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, expected first, expected last, expected salutation

            'Lord + surname'          => ['Lord Ashcroft', '', 'Ashcroft', 'Lord'],
            'Lady + full name'        => ['Lady Diana Spencer', 'Diana', 'Spencer', 'Lady'],
            'Dame + full name'        => ['Dame Judi Dench', 'Judi', 'Dench', 'Dame'],
            'Rt Hon'                  => ['Rt Hon Boris Johnson', 'Boris', 'Johnson', 'Rt Hon.'],
            'Rt Hon with periods'     => ['Rt. Hon. Boris Johnson', 'Boris', 'Johnson', 'Rt Hon.'],
            'The Rt Hon'              => ['The Rt Hon Boris Johnson', 'Boris', 'Johnson', 'Rt Hon.'],
            'Reverend spelled out'    => ['Reverend John Smith', 'John', 'Smith', 'Rev.'],
            'Pastor'                  => ['Pastor Rick Warren', 'Rick', 'Warren', 'Pastor'],
            'Professor spelled out'   => ['Professor Alice Green', 'Alice', 'Green', 'Prof.'],

            'Lord as surname'         => ['Lord, Jack', 'Jack', 'Lord', ''],
            'Pastor as surname'       => ['Pastor, Maria', 'Maria', 'Pastor', ''],
            'Dame as surname'         => ['Dame, Robert', 'Robert', 'Dame', ''],
            'Lady as surname'         => ['Lady, Anne', 'Anne', 'Lady', ''],
            'Master as surname'       => ['Master, John', 'John', 'Master', ''],
            'Hon as surname'          => ['Hon, John', 'John', 'Hon', ''],
            'Lord as surname allcaps' => ['LORD, JACK', 'Jack', 'Lord', ''],
            'Pastor surname allcaps'  => ['PASTOR, MARIA', 'Maria', 'Pastor', ''],

            'Miss alone stays title'  => ['Miss, John', 'John', '', 'Miss'],
            'Sir alone stays title'   => ['Sir, John', 'John', '', 'Sir'],

            'colliding title midname' => ['John Lord Smith Jr', 'John', 'Smith', ''],
            'Master midname'          => ['John Master Smith Jr', 'John', 'Smith', ''],
            'Lord midname'            => ['Mary Lord Smith', 'Mary', 'Smith', ''],

            'Lord trailing surname'   => ['Jack Lord', 'Jack', 'Lord', ''],
            'Lord after initial'      => ['David R. Lord', 'David', 'Lord', ''],
            'Pastor trailing surname' => ['Maria Pastor', 'Maria', 'Pastor', ''],

            'leading article'         => ['The Rev. Mark Williams', 'Mark', 'Williams', 'Rev.'],
            'stacked titles'          => ['Rev. Dr John Doe', 'John', 'Doe', 'Rev. Dr.'],
            'title in comma surname'  => ['Mrs. Brown, Amanda', 'Amanda', 'Brown', 'Mrs.'],
        ];
    }

    #[DataProvider('provider')]
    public function testSalutationCollisions(string $input, string $first, string $last, string $salutation): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "firstname for '$input'");
        $this->assertSame($last, $name->getLastname(), "lastname for '$input'");
        $this->assertSame($salutation, $name->getSalutation(), "salutation for '$input'");
    }

    #[DataProvider('loneSalutationProvider')]
    public function testLoneSalutationSharedLock(
        string $input,
        string $salutation,
        string $first,
        string $last,
        string $initials,
        string $suffix,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame($salutation, $name->getSalutation(), "salutation for '$input'");
        $this->assertSame($first, $name->getFirstname(), "firstname for '$input'");
        $this->assertSame($last, $name->getLastname(), "lastname for '$input'");
        $this->assertSame($initials, $name->getInitials(), "initials for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    public function testCollidingTitleAfterAGivenNameStaysAMiddleName(): void
    {
        $name = (new Parser())->parse('John Lord Smith Jr');

        $this->assertSame('Lord', $name->getMiddlename());
        $this->assertSame('Jr', $name->getSuffix());
    }

    public function testCollidingSurnameSurvivesACredentialTail(): void
    {
        $name = (new Parser())->parse('Pastor, Maria RN');

        $this->assertSame('Maria', $name->getFirstname());
        $this->assertSame('Pastor', $name->getLastname());
        $this->assertSame('RN', $name->getSuffix());
    }

    public function testCollidingGivenNameSurvivesACredentialTail(): void
    {
        $name = (new Parser())->parse('Smith, Lord RN');

        $this->assertSame('', $name->getSalutation());
        $this->assertSame('Lord', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('RN', $name->getSuffix());
    }

    #[DataProvider('decoratedCollidingSurnameProvider')]
    public function testCollidingSurnameSurvivesNonNameDecoration(string $input, string $nickname, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('', $name->getSalutation());
        $this->assertSame('Jack', $name->getFirstname());
        $this->assertSame('Lord', $name->getLastname());
        $this->assertSame($nickname, $name->getNickname());
        $this->assertSame($suffix, $name->getSuffix());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function decoratedCollidingSurnameProvider(): array
    {
        return [
            'nickname'   => ['Lord (Bob), Jack', 'Bob', ''],
            'credential' => ['Lord MD, Jack', '', 'MD'],
        ];
    }

    #[DataProvider('collidingTitleWithNameRemainderProvider')]
    public function testCollidingTitleStillMapsWithARealNameRemainder(
        string $input,
        string $first,
        string $last,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame('Lord', $name->getSalutation());
        $this->assertSame($first, $name->getFirstname());
        $this->assertSame($last, $name->getLastname());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function collidingTitleWithNameRemainderProvider(): array
    {
        return [
            'given segment'   => ['Smith, Lord John', 'John', 'Smith'],
            'surname segment' => ['Lord Brown, Jack', 'Jack', 'Brown'],
        ];
    }

    #[DataProvider('salutationProvider')]
    public function testRenderedSalutationsParseBack(string $rendered): void
    {
        $name = (new Parser())->parse($rendered . ' Alex Testsurname');

        $this->assertSame($rendered, $name->getSalutation(), "'$rendered' did not round-trip");
        $this->assertSame('Testsurname', $name->getLastname(), "lastname after '$rendered'");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function salutationProvider(): array
    {
        $cases = [];
        foreach (English::SALUTATIONS as $key => $rendered) {
            $cases[(string) $key] = [$rendered];
        }

        return $cases;
    }

    #[DataProvider('confidenceProvider')]
    public function testConfidenceFlagsUndecidableLeadingTitles(string $input, bool $ambiguous): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($ambiguous, $name->getConfidence()['ambiguous'], "confidence for '$input'");
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function confidenceProvider(): array
    {
        return [
            'Lord + surname'         => ['Lord Ashcroft', true],
            'Pastor + surname'       => ['Pastor Gonzalez', true],
            'Hon + surname'          => ['Hon Chan', true],
            'Master + surname'       => ['Master Smith', true],

            'comma form'             => ['Lord, Jack', false],
            'three tokens'           => ['Lady Diana Spencer', false],
            'plain title'            => ['Dr. Jane Doe', false],
            'plain name'             => ['Jack Lord', false],
        ];
    }
}
