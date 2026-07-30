<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Ignored;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A connector joining two titles belongs to the honorific, not to the given
 * name ("Mr. and Mrs. Brad Smith" keeps Brad as the first name). It needs a
 * title on both sides, so a stray "and" is never absorbed, and Name::isJoint()
 * reports the rows that cover two people.
 */
class JointSalutationTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, expected salutation, expected first, expected last

            // the reported forms
            'and spelled out'     => ['Mr. and Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'ampersand'           => ['Mr. & Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'no periods'          => ['Mr and Mrs Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'surname only'        => ['Mr. and Mrs. Smith', 'Mr. and Mrs.', '', 'Smith'],

            // the connector normalizes, so both spellings land on one value
            'uppercase input'     => ['MR. AND MRS. BRAD SMITH', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'lowercase input'     => ['mr. and mrs. brad smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'title case and'      => ['Mr. And Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],

            // any pairing of titles, not just Mr/Mrs
            'two Ms'              => ['Ms. & Ms. Jane Doe', 'Ms. and Ms.', 'Jane', 'Doe'],
            'two Mr'              => ['Mr. and Mr. John Smith', 'Mr. and Mr.', 'John', 'Smith'],
            'two Dr, no first'    => ['Dr. & Dr. Chen', 'Dr. and Dr.', '', 'Chen'],
            'mixed titles'        => ['Dr. and Mrs. Brad Smith', 'Dr. and Mrs.', 'Brad', 'Smith'],
            'Prof pairing'        => ['Prof. and Mrs. Alan Turing', 'Prof. and Mrs.', 'Alan', 'Turing'],
            'colliding surname'   => ['Mr. and Mrs. Lord', 'Mr. and Mrs.', '', 'Lord'],
            'second colliding surname' => ['Mr. and Mrs. Pastor', 'Mr. and Mrs.', '', 'Pastor'],
            'colliding surname after multi-word title' => ['Mr. and Rt Hon Lord', 'Mr. and Rt Hon.', '', 'Lord'],

            // composes with the rest of the pipeline
            'with initial'        => ['Mr. and Mrs. Brad J. Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'with suffix'         => ['Mr. and Mrs. Brad Smith Jr', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'with credential'     => ['Mr. & Mrs. John Smith, MD', 'Mr. and Mrs.', 'John', 'Smith'],
            'with prefix surname' => ['Mr. and Mrs. van der Berg', 'Mr. and Mrs.', '', 'van der Berg'],
            'comma form'          => ['Mr. and Mrs. Smith, Brad', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'comma stacked titles' => ['Doe, Rev. Dr. John', 'Rev. Dr.', 'John', 'Doe'],

            // a connector needs a title on both sides to join the honorific.
            // Unjoined, it still belongs to nobody, so it is dropped from the
            // getters rather than title-cased into a name ("And").
            'no title after'      => ['Mr. and Brad Smith', 'Mr.', 'Brad', 'Smith'],
            'no title before'     => ['Brad and Smith', '', 'Brad', 'Smith'],
            'doubled connector'   => ['Mr. and and Mrs. Smith', 'Mr.', '', 'Smith'],

            // real names are matched whole, so these never come close
            'surname Anderson'    => ['Anderson, Andrea', '', 'Andrea', 'Anderson'],
            'given Andre'         => ['Andre Smith', '', 'Andre', 'Smith'],
            'surname Andrews'     => ['Amanda Andrews', '', 'Amanda', 'Andrews'],

            // single titles are untouched
            'single title'        => ['Mr. Brad Smith', 'Mr.', 'Brad', 'Smith'],
            'stacked titles'      => ['Rev. Dr John Doe', 'Rev. Dr.', 'John', 'Doe'],
            // Without a named person the connector never joins, so only the
            // leading title resolves. The trailing "Mrs." addresses a second
            // person nobody named, so it is not this person's surname either and
            // "Mr. and Mrs." yields no name at all.
            'title-only joint'    => ['Mr. and Mrs.', 'Mr.', '', ''],
            'title and credential only' => ['Smith, Mr. and Mrs. MD', 'Mr.', '', 'Smith'],
            'title and nickname only' => ['Smith, Mr. and Mrs. (Bob)', 'Mr.', '', 'Smith'],
        ];
    }

    #[DataProvider('provider')]
    public function testJointSalutations(string $input, string $salutation, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($salutation, $name->getSalutation(), "salutation for '$input'");
        $this->assertSame($first, $name->getFirstname(), "firstname for '$input'");
        $this->assertSame($last, $name->getLastname(), "lastname for '$input'");
    }

    /**
     * A conjunction the honorific could not absorb belongs to nobody, so it is
     * kept out of every getter instead of being title-cased into a name. Same
     * for a title that directly follows one: it addresses a second person, not
     * the person named here. The given name beside that title is left where it
     * lands, since identifying the second person is a separate question.
     *
     * @param  array<string, string>  $expected
     */
    #[DataProvider('unattributedProvider')]
    public function testUnabsorbedConnectorStaysOutOfTheGetters(string $input, array $expected): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($expected, $name->getAll(), "parts for '$input'");
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function unattributedProvider(): array
    {
        return [
            // two named people sharing a surname: the conjunction and the second
            // title go, the second given name stays as a middle name
            'two givens with titles' => ['Mr. Andrew and Mrs Sally Smith', [
                'salutation' => 'Mr.', 'firstname' => 'Andrew',
                'middlename' => 'Sally', 'lastname' => 'Smith',
            ]],
            'two givens with multi-word title' => ['Mr. Andrew and His Honour Sally Smith', [
                'salutation' => 'Mr.', 'firstname' => 'Andrew',
                'middlename' => 'Sally', 'lastname' => 'Smith',
            ]],
            'two givens with abbreviated multi-word title' => ['Mr. Andrew and Rt Hon Sally Smith', [
                'salutation' => 'Mr.', 'firstname' => 'Andrew',
                'middlename' => 'Sally', 'lastname' => 'Smith',
            ]],
            'two givens no titles' => ['Andrew and Sally Smith', [
                'firstname' => 'Andrew', 'middlename' => 'Sally', 'lastname' => 'Smith',
            ]],
            'two givens ampersand' => ['Andrew & Sally Smith', [
                'firstname' => 'Andrew', 'middlename' => 'Sally', 'lastname' => 'Smith',
            ]],
            'two givens prefix surname' => ['Andrew and Sally van der Berg', [
                'firstname' => 'Andrew', 'middlename' => 'Sally', 'lastname' => 'van der Berg',
            ]],
        ];
    }

    /**
     * the raw text is marked Ignored rather than dropped, so a caller that wants
     * the household structure can still recover it from getParts()
     */
    public function testIgnoredTokensStayVisibleInGetParts(): void
    {
        $name = (new Parser())->parse('Mr. Andrew and Mrs Sally Smith');

        $ignored = [];

        foreach ($name->getParts() as $part) {
            if ($part instanceof Ignored) {
                $ignored[] = $part->getValue();
            }
        }

        $this->assertSame(['and', 'Mrs'], $ignored);
    }

    public function testEveryWordOfAnUnattributedTitleIsIgnored(): void
    {
        $name = (new Parser())->parse('Mr. Andrew and His Honour Sally Smith');

        $ignored = [];

        foreach ($name->getParts() as $part) {
            if ($part instanceof Ignored) {
                $ignored[] = $part->getValue();
            }
        }

        $this->assertSame(['and', 'His', 'Honour'], $ignored);
    }

    /**
     * Several salutation keys double as credentials ("ms" is both Ms. and MS),
     * and SalutationMapper runs before SuffixMapper in the single-segment
     * pipeline. Only a title introduced by a conjunction is dropped, so a
     * trailing credential is untouched.
     */
    #[DataProvider('credentialCollisionProvider')]
    public function testTitleShapedCredentialIsNotDropped(string $input, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame('Jane', $name->getFirstname(), "first name for '$input'");
        $this->assertSame('Doe', $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function credentialCollisionProvider(): array
    {
        return [
            'ms space form' => ['Jane Doe MS', 'MS'],
            'ms comma form' => ['Jane Doe, MS', 'MS'],
            'ma space form' => ['Jane Doe MA', 'MA'],
        ];
    }

    /**
     * a title that is also a real personal name keeps its name reading, so the
     * NAME_COLLIDING_KEYS carve-out is not undone by the conjunction rule
     */
    public function testNameCollidingTitleAfterConnectorStaysAName(): void
    {
        $name = (new Parser())->parse('John Lord Smith Jr');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Lord', $name->getMiddlename());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Jr', $name->getSuffix());
    }

    /**
     * the connector must not leak into the name getters it used to pollute
     */
    public function testConnectorLeavesTheNameGettersClean(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');

        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('Brad', $name->getGivenName());
        $this->assertSame('Brad Smith', $name->getFullName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function titleOnlyConnectorProvider(): array
    {
        return [
            'single-word titles' => ['Mr and Mrs and Ms MD'],
            'colliding single-word title' => ['Mr and Mrs and Pastor MD'],
            'multi-word Her Honour' => ['Mr and Her Honour MD'],
            'multi-word Rt Hon' => ['Mr and Rt Hon MD'],
        ];
    }

    #[DataProvider('titleOnlyConnectorProvider')]
    public function testTitleOnlyConnectorChainDoesNotCreatePartner(string $input): void
    {
        $name = (new Parser())->parse($input);

        $this->assertFalse($name->isJoint());
        $this->assertNull($name->getPartner());
    }

    #[DataProvider('jointProvider')]
    public function testIsJointReportsTwoPersonRows(string $input, bool $joint): void
    {
        $this->assertSame($joint, (new Parser())->parse($input)->isJoint(), "isJoint for '$input'");
    }

    /**
     * the honorific splits into one entry per person addressed, so a caller with
     * a single prefix field per contact can take the first and derive the
     * partner from the second
     *
     * @param  list<string>  $expected
     */
    #[DataProvider('salutationsProvider')]
    public function testGetSalutationsSplitsPerPerson(string $input, array $expected): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($expected, $name->getSalutations(), "getSalutations for '$input'");

        // the entries recompose into the rendered honorific
        $this->assertSame($name->getSalutation(), implode(' and ', $expected), "recomposition for '$input'");
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function salutationsProvider(): array
    {
        return [
            'and spelled out'  => ['Mr. and Mrs. Brad Smith', ['Mr.', 'Mrs.']],
            'ampersand'        => ['Mr. & Mrs. Brad Smith', ['Mr.', 'Mrs.']],
            'no periods'       => ['Mr and Mrs Brad Smith', ['Mr.', 'Mrs.']],
            'uppercase input'  => ['MR. AND MRS. BRAD SMITH', ['Mr.', 'Mrs.']],
            'two doctors'      => ['Dr. & Dr. Chen', ['Dr.', 'Dr.']],
            'mixed titles'     => ['Dr. and Mrs. Brad Smith', ['Dr.', 'Mrs.']],
            'comma form'       => ['Mr. and Mrs. Smith, Brad', ['Mr.', 'Mrs.']],
            'surname only'     => ['Mr. and Mrs. Smith', ['Mr.', 'Mrs.']],

            // stacked titles address one person, so they stay in one entry
            'stacked titles'   => ['Rev. Dr John Doe', ['Rev. Dr.']],
            'stacked and joint' => ['Rev. Dr. and Mrs. John Doe', ['Rev. Dr.', 'Mrs.']],

            'single title'     => ['Mr. Brad Smith', ['Mr.']],
            // the leading article is not retained by the mapper
            'article led'      => ['The Rev. Mark Williams', ['Rev.']],
            'no honorific'     => ['Brad Smith', []],
            'unabsorbed and'   => ['Mr. and Brad Smith', ['Mr.']],
        ];
    }

    /**
     * the shape the reported CiviCRM import needs: one prefix for the named
     * contact, the partner assembled from the second title and the surname
     */
    public function testSalutationsDrivePerContactMapping(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');
        $salutations = $name->getSalutations();

        $this->assertTrue($name->isJoint());
        $this->assertSame('Mr.', $salutations[0]);
        $this->assertSame('Mrs. Smith', $salutations[1] . ' ' . $name->getLastname());
    }

    /**
     * the second addressee comes back as a Name carrying her title and the
     * shared surname, so the caller renders "Mrs. Smith" or "Mrs. Brad Smith"
     * to its own taste
     */
    #[DataProvider('partnerProvider')]
    public function testGetPartner(string $input, ?string $salutation, ?string $lastname): void
    {
        $partner = (new Parser())->parse($input)->getPartner();

        if ($salutation === null) {
            $this->assertNull($partner, "partner for '$input'");

            return;
        }

        $this->assertNotNull($partner, "partner for '$input'");
        $this->assertSame($salutation, $partner->getSalutation(), "partner salutation for '$input'");
        $this->assertSame($lastname, $partner->getLastname(), "partner lastname for '$input'");
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function partnerProvider(): array
    {
        return [
            // input, partner salutation, partner lastname (null salutation = no partner)
            'and spelled out'    => ['Mr. and Mrs. Brad Smith', 'Mrs.', 'Smith'],
            'ampersand'          => ['Mr. & Mrs. Brad Smith', 'Mrs.', 'Smith'],
            'no periods'         => ['Mr and Mrs Brad Smith', 'Mrs.', 'Smith'],
            'uppercase input'    => ['MR. AND MRS. BRAD SMITH', 'Mrs.', 'Smith'],
            'two doctors'        => ['Dr. & Dr. Chen', 'Dr.', 'Chen'],
            'mixed titles'       => ['Dr. and Mrs. Brad Smith', 'Mrs.', 'Smith'],
            'comma form'         => ['Mr. and Mrs. Smith, Brad', 'Mrs.', 'Smith'],
            'surname only'       => ['Mr. and Mrs. Smith', 'Mrs.', 'Smith'],

            // the particle belongs to the shared surname
            'prefix surname'     => ['Mr. and Mrs. van der Berg', 'Mrs.', 'van der Berg'],

            // a stacked honorific addresses the first person, so only the
            // second group crosses over
            'stacked and joint'  => ['Rev. Dr. and Mrs. John Doe', 'Mrs.', 'Doe'],

            'single title'       => ['Mr. Brad Smith', null, null],
            'no honorific'       => ['Brad Smith', null, null],
            'unabsorbed and'     => ['Mr. and Brad Smith', null, null],
            'bare two givens'    => ['Brad and Jane Smith', null, null],
            'credential-only remainder' => ['Mr. and Mrs. MD', null, null],
            'nickname-only remainder' => ['Mr. and Mrs. (Bob)', null, null],
        ];
    }

    /**
     * the given name and any credential belong to the person actually named,
     * so neither follows the partner
     */
    public function testPartnerCarriesNoGivenNameOrSuffix(): void
    {
        $partner = (new Parser())->parse('Mr. and Mrs. Brad J. Smith Jr')->getPartner();

        $this->assertNotNull($partner);
        $this->assertSame('', $partner->getFirstname());
        $this->assertSame('', $partner->getInitials());
        $this->assertSame('', $partner->getMiddlename());
        $this->assertSame('', $partner->getSuffix());
        $this->assertSame('Smith', $partner->getFullName());
        $this->assertSame('Mrs. Smith', (string) $partner);
    }

    /**
     * the partner is one person, so she carries a single-entry salutation list
     * and no connector of her own
     */
    public function testPartnerIsNotItselfJoint(): void
    {
        $partner = (new Parser())->parse('Mr. and Mrs. Brad Smith')->getPartner();

        $this->assertNotNull($partner);
        $this->assertFalse($partner->isJoint());
        $this->assertSame(['Mrs.'], $partner->getSalutations());
        $this->assertNull($partner->getPartner());
    }

    /**
     * parts are cloned into the partner, so writing through one Name cannot
     * reach into the other
     */
    public function testPartnerDoesNotShareMutablePartsWithTheSource(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');
        $partner = $name->getPartner();

        $this->assertNotNull($partner);

        foreach ($partner->getParts() as $part) {
            if ($part instanceof Lastname) {
                $part->setValue('Jones');
            }
        }

        $this->assertSame('Jones', $partner->getLastname());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function jointProvider(): array
    {
        return [
            'and spelled out'   => ['Mr. and Mrs. Brad Smith', true],
            'ampersand'         => ['Mr. & Mrs. Brad Smith', true],
            'two doctors'       => ['Dr. & Dr. Chen', true],
            'comma form'        => ['Mr. and Mrs. Smith, Brad', true],
            'colliding surname' => ['Mr. and Mrs. Lord', true],

            'single title'      => ['Mr. Brad Smith', false],
            'no title'          => ['Brad Smith', false],
            'unabsorbed and'    => ['Mr. and Brad Smith', false],
            // no honorific to anchor the connector, so this stays undetected
            'bare two givens'   => ['Brad and Jane Smith', false],
            'credential-only remainder' => ['Mr. and Mrs. MD', false],
            'nickname-only remainder' => ['Mr. and Mrs. (Bob)', false],
        ];
    }
}
