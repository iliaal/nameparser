<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Everything after the first comma is the given-name segment. This locks that
 * a comma-separated middle name is retained (not dropped as a non-credential
 * third segment) while trailing credentials are still stripped to the suffix,
 * including a given segment that is nothing but credentials.
 */
class CommaSegmentTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, first, middle, last, suffix
            'comma middle name retained'      => ['Smith, John, Robert', 'John', 'Robert', 'Smith', ''],
            'comma middle then credential'    => ['Smith, John Robert, MD', 'John', 'Robert', 'Smith', 'MD'],
            'comma first then credentials'    => ['Smith, John, MD, PhD', 'John', '', 'Smith', 'MD PhD'],
            'credential-only given segment'   => ['Smith, MD, PhD', '', '', 'Smith', 'MD PhD'],
            'single credential given'         => ['Smith, MD', '', '', 'Smith', 'MD'],
            'given named Della before credential' => ['Della Smith, MD', 'Della', '', 'Smith', 'MD'],
            'given named Van before credential' => ['Van Smith, MD', 'Van', '', 'Smith', 'MD'],
            'comma suffix Jr'                 => ['Williams, Hank, Jr.', 'Hank', '', 'Williams', 'Jr'],
            'comma initial + suffix'          => ['Miller, Walter M., Jr.', 'Walter', '', 'Miller', 'Jr'],
            'compound surname'                => ['Hidalgo Castillo, Maria', 'Maria', '', 'Hidalgo Castillo', ''],
            'surname suffix Jr'               => ['Doe Jr, John', 'John', '', 'Doe', 'Jr'],
            'surname roman suffix'            => ['Doe III, John', 'John', '', 'Doe', 'III'],
            'credential-only given keeps first segment western' => ['Anthony Von Fange III, PHD', 'Anthony', '', 'von Fange', 'III PhD'],
            // a whole credential-only segment is pulled out to the suffix; the
            // remaining name segments still fold into the given name
            'credential segment before given' => ['Smith, MD, John', 'John', '', 'Smith', 'MD'],
            'all-credential segments western'  => ['John Smith, MD, FACS', 'John', '', 'Smith', 'MD FACS'],
            'unknown credential rides on known' => ['Garcia, Maria, MD, FACS', 'Maria', '', 'Garcia', 'MD FACS'],
            'ambiguous credential segment keeps middle' => ['Smith, John, DO, Robert', 'John', 'Robert', 'Smith', 'DO'],
            // leading credential run inside the given segment
            'leading credential run in given' => ['Smith, MD John', 'John', '', 'Smith', 'MD'],
            'leading title-case name is not a credential' => ['Smith, Do John', 'Do', 'John', 'Smith', ''],
            'mixed credential positions keep source order' => ['Smith, MD, John PhD', 'John', '', 'Smith', 'MD PhD'],
            'candidate cannot cross a name segment' => ['Smith, JOHN, Robert, MD', 'John', 'Robert', 'Smith', 'MD'],
            'unknown candidate cannot cross a name segment' => ['Smith, FACS, John, MD', 'Facs', 'John', 'Smith', 'MD'],
            // pure all-caps given segments are names, not pre-anchor credentials
            'all-caps given before credential stays name' => ['Smith, JOHN, MD', 'John', '', 'Smith', 'MD'],
            'all-caps multi-token given before credential' => ['Smith, JOHN PAUL, MD', 'John', 'Paul', 'Smith', 'MD'],
            // pure unknown-candidate segments only ride after a dictionary anchor
            'pure unknown before dictionary stays name' => ['Smith, FACS, MD', 'Facs', '', 'Smith', 'MD'],
            // mixed-segment trailing candidate peels onto a later dictionary segment
            'mixed segment trailing candidate rides on later dictionary' => ['Smith, John FACS, MD', 'John', '', 'Smith', 'FACS MD'],
            // same-segment dictionary suffix anchors trailing unknown candidates
            'mixed same-segment dict then unknown' => ['Garcia, Maria MD FACS', 'Maria', '', 'Garcia', 'MD FACS'],
            'mixed same-segment multi-token then unknown' => ['Smith, John MD FACS', 'John', '', 'Smith', 'MD FACS'],
            // mixed segment with dict suffix then pure unknown segment
            'mixed then pure unknown segment rides' => ['Smith, John MD, FACS', 'John', '', 'Smith', 'MD FACS'],
            // trailing unknown peel without later dictionary stays a name
            'mixed trailing unknown without later dict stays name' => ['Smith, John FACS', 'John', 'Facs', 'Smith', ''],
            // terminal-token guard: ALL-CAPS lone ambiguous given is a credential
            'terminal all-caps ambiguous is credential' => ['Smith, DO', '', '', 'Smith', 'DO'],
            'terminal title-case ambiguous stays name' => ['Smith, Do', 'Do', '', 'Smith', ''],
            // junior/senior sole or leading given are names, not credentials
            'sole junior is given name' => ['Smith, Junior', 'Junior', '', 'Smith', ''],
            'sole senior is given name' => ['Smith, Senior', 'Senior', '', 'Smith', ''],
            'leading junior before given' => ['Smith, Junior Paul', 'Junior', 'Paul', 'Smith', ''],
            // multi-token left side keeps generational junior as suffix
            'generational junior after structured left' => ['Sir James Reynolds, Junior', 'James', '', 'Reynolds', 'Junior'],
        ];
    }

    #[DataProvider('provider')]
    public function testGivenSegmentFolding(string $input, string $first, string $middle, string $last, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    public function testAllCapsTwoLetterGivenIsNotSplitIntoInitials(): void
    {
        $name = (new Parser())->parse('JO ANDERSON');

        $this->assertSame('Jo', $name->getFirstname());
        $this->assertSame('', $name->getInitials());
    }

    public function testMixedCaseCombinedInitialsStillSplit(): void
    {
        $name = (new Parser())->parse('JM Walker');

        $this->assertSame('J', $name->getFirstname());
        $this->assertSame('M', $name->getInitials());
        $this->assertSame('Walker', $name->getLastname());
    }

    /**
     * The uniform-uppercase signal for the InitialMapper split gate comes from
     * the whole input, not the given segment alone. "Smith" proves mixed case,
     * so the JM token splits exactly as it does in the space-form "JM Smith".
     */
    public function testCommaGivenInitialsUseWholeInputCasing(): void
    {
        $name = (new Parser())->parse('Smith, JM');

        $this->assertSame('J', $name->getFirstname());
        $this->assertSame('M', $name->getInitials());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testUniformUpperCommaInputSuppressesInitialSplit(): void
    {
        $name = (new Parser())->parse('SMITH, JM');

        $this->assertSame('Jm', $name->getFirstname());
        $this->assertSame('', $name->getInitials());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * The override is transient: a plain single-segment parse on the same
     * instance is unaffected by a preceding comma parse.
     */
    public function testOverrideDoesNotLeakToSingleSegmentParse(): void
    {
        $parser = new Parser();
        $parser->parse('SMITH, JM');
        $name = $parser->parse('JM Walker');

        $this->assertSame('J', $name->getFirstname());
        $this->assertSame('M', $name->getInitials());
        $this->assertSame('Walker', $name->getLastname());
    }

    /**
     * a surname segment that is nothing but a salutation must keep it a
     * salutation, not promote it to a last name
     */
    public function testLoneSalutationSurnameSegmentStaysSalutation(): void
    {
        $name = (new Parser())->parse('Dr., John');

        $this->assertSame('Dr.', $name->getSalutation());
        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('', $name->getLastname());
    }

    /**
     * the surname sub-parser now runs the Nickname and Initial mappers, so a
     * parenthetical nickname is extracted and a stray letter becomes an initial
     * rather than raw middle-name text
     */
    public function testSurnameSegmentExtractsNickname(): void
    {
        $name = (new Parser())->parse('John (Bob) Smith, MD');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Bob', $name->getNickname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testSurnameSegmentSplitsInitials(): void
    {
        $name = (new Parser())->parse('J. R. Smith MD,');

        $this->assertSame('J.', $name->getFirstname());
        $this->assertSame('R.', $name->getInitials());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    /**
     * a comma inside a matched nickname delimiter span must not be treated as
     * the surname/given separator
     */
    public function testGivenSideNicknameKeepsItsComma(): void
    {
        $name = (new Parser())->parse('Smith, John (Jack, Robert)');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Jack, Robert', $name->getNickname());
    }

    public function testQuotedNicknameWithCommaDoesNotBisect(): void
    {
        $name = (new Parser())->parse("John 'Bob, Jr' Doe");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Doe', $name->getLastname());
        $this->assertSame('Bob, Jr', $name->getNickname());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function quotedNicknameBeforeCommaProvider(): array
    {
        return [
            'single quote' => ["Doe 'Bob, Robert', John"],
            'double quote' => ['Doe "Bob, Robert", John'],
        ];
    }

    #[DataProvider('quotedNicknameBeforeCommaProvider')]
    public function testQuotedNicknameCloserBeforeStructuralComma(string $input): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Doe', $name->getLastname());
        $this->assertSame('Bob, Robert', $name->getNickname());
    }

    public function testSuffixCollidingSpanCloserKeepsNicknameWhole(): void
    {
        // the span's closer token keys as a suffix ("III)"), but consuming it
        // would orphan the opener; the whole span stays a nickname and the
        // shielded comma must not leak into the middle name
        $name = (new Parser())->parse('Smith, John (Jack, III)');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        // Nickname normalization title-cases the all-caps token, like any
        // other all-caps nickname value
        $this->assertSame('Jack, Iii', $name->getNickname());
        $this->assertSame('', $name->getMiddlename());
    }

    public function testCommaInsideNicknameDoesNotBisect(): void
    {
        $name = (new Parser())->parse('John (Bob, Jr) Doe');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Bob, Jr', $name->getNickname());
        $this->assertSame('Doe', $name->getLastname());
    }

    /**
     * a real comma still separates the surname from the given segment; a
     * secondary comma inside a given-side parenthetical is not bisected into
     * the surname
     */
    public function testStructuralCommaStillSplitsWithGivenSideParenthetical(): void
    {
        $name = (new Parser())->parse('Smith, John (Jack, III)');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('John', $name->getFirstname());
    }

    /**
     * "MS" is both a salutation (Ms.) and a credential (MS). In the given
     * segment the Suffix mapper runs before the Salutation mapper, so a bare
     * "MS" given segment is classified as a trailing credential, not promoted
     * to a leading salutation.
     */
    public function testGivenSegmentCredentialOutranksSalutationCollision(): void
    {
        $name = (new Parser())->parse('Smith, MS');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MS', $name->getSuffix());
        $this->assertSame('', $name->getSalutation());
        $this->assertSame('', $name->getFirstname());
    }

    public function testSingleSegmentSalutationOutranksCredentialCollision(): void
    {
        $name = (new Parser())->parse('MS Smith');

        $this->assertSame('Ms.', $name->getSalutation());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getSuffix());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function commaCredentialNoiseProvider(): array
    {
        return [
            'punctuation after anchor' => ['Smith, Jane, MD, -'],
            'punctuation before anchor' => ['Smith, Jane, -, MD'],
            'placeholder after anchor' => ['Smith, Jane, MD, Unknown'],
            'placeholder before anchor' => ['Smith, Jane, Unknown, MD'],
        ];
    }

    #[DataProvider('commaCredentialNoiseProvider')]
    public function testCommaCredentialTailDropsNoiseAroundAnchor(string $input): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('Jane', $name->getFirstname());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testCommaTailNoiseWithoutCredentialAnchorIsPreserved(): void
    {
        $name = (new Parser())->parse('Smith, Jane, -');

        $this->assertSame('Jane', $name->getFirstname());
        $this->assertSame('-', $name->getMiddlename());
        $this->assertSame('', $name->getSuffix());
    }

    public function testTrailingSpanWithSuffixCollidingCloserStaysNickname(): void
    {
        // "MD)" closes the span; consuming it as a credential would orphan
        // the opener and demote the nickname body into the name getters
        $name = (new Parser())->parse('Doe, Jane (Bobbie, MD)');

        $this->assertSame('Jane', $name->getFirstname());
        $this->assertSame('Doe', $name->getLastname());
        $this->assertSame('Bobbie, Md', $name->getNickname());
        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('', $name->getSuffix());
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function allCapsGivenBeforeCredentialProvider(): array
    {
        return [
            'single all-caps given'    => ['Smith, JOHN MD', 'John', '', 'MD'],
            'two all-caps givens'      => ['Smith, JOHN PAUL MD', 'John', 'Paul', 'MD'],
            'unknown candidate given'  => ['Smith, FACS MD', 'Facs', '', 'MD'],
        ];
    }

    /**
     * an all-caps given name adjacent to the trailing credential must not be
     * swallowed into the suffix: the given segment cannot map entirely to
     * credentials, matching the comma-separated "Smith, JOHN, MD" locks
     */
    #[DataProvider('allCapsGivenBeforeCredentialProvider')]
    public function testAllCapsGivenAdjacentToCredentialStaysName(
        string $input,
        string $first,
        string $middle,
        string $suffix,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame('Smith', $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    public function testKnownCredentialLeadingUnknownCandidateStillRides(): void
    {
        // the README-documented preference: when the unknown stands alone
        // behind a known credential, both stay in the suffix
        $name = (new Parser())->parse('Smith, MD FACS');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD FACS', $name->getSuffix());
        $this->assertSame('', $name->getFirstname());
    }

    public function testLeadingCredentialRunDoesNotAnchorAcrossComma(): void
    {
        // a leading run ("MD John") must not promote a name in a following
        // segment; only a run touching the segment tail carries the anchor
        $name = (new Parser())->parse('Smith, MD John, PAUL');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Paul', $name->getMiddlename());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testNicknameAheadOfLeadingCredentialRunKeepsRun(): void
    {
        $name = (new Parser())->parse('Smith, (Doc) MD PhD John');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('', $name->getInitials());
        $this->assertSame('MD PhD', $name->getSuffix());
        $this->assertSame('Doc', $name->getNickname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testSurnameSegmentBindsPastMidSegmentNickname(): void
    {
        // the reverse surname scan must look through an extracted nickname
        // instead of stranding the far-side token as an invisible raw string
        $name = (new Parser())->parse('Hidalgo (Hid) Castillo, Maria');

        $this->assertSame('Maria', $name->getFirstname());
        $this->assertSame('Hidalgo Castillo', $name->getLastname());
        $this->assertSame('Hid', $name->getNickname());
    }
}
