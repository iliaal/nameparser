<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Ignored;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JointSalutationTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, expected salutation, expected first, expected last

            'and spelled out'     => ['Mr. and Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'ampersand'           => ['Mr. & Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'no periods'          => ['Mr and Mrs Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'surname only'        => ['Mr. and Mrs. Smith', 'Mr. and Mrs.', '', 'Smith'],

            'uppercase input'     => ['MR. AND MRS. BRAD SMITH', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'lowercase input'     => ['mr. and mrs. brad smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'title case and'      => ['Mr. And Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],

            'two Ms'              => ['Ms. & Ms. Jane Doe', 'Ms. and Ms.', 'Jane', 'Doe'],
            'two Mr'              => ['Mr. and Mr. John Smith', 'Mr. and Mr.', 'John', 'Smith'],
            'two Dr, no first'    => ['Dr. & Dr. Chen', 'Dr. and Dr.', '', 'Chen'],
            'mixed titles'        => ['Dr. and Mrs. Brad Smith', 'Dr. and Mrs.', 'Brad', 'Smith'],
            'Prof pairing'        => ['Prof. and Mrs. Alan Turing', 'Prof. and Mrs.', 'Alan', 'Turing'],
            'colliding surname'   => ['Mr. and Mrs. Lord', 'Mr. and Mrs.', '', 'Lord'],
            'second colliding surname' => ['Mr. and Mrs. Pastor', 'Mr. and Mrs.', '', 'Pastor'],
            'colliding surname after multi-word title' => ['Mr. and Rt Hon Lord', 'Mr. and Rt Hon.', '', 'Lord'],

            'with initial'        => ['Mr. and Mrs. Brad J. Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'with suffix'         => ['Mr. and Mrs. Brad Smith Jr', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'with credential'     => ['Mr. & Mrs. John Smith, MD', 'Mr. and Mrs.', 'John', 'Smith'],
            'with prefix surname' => ['Mr. and Mrs. van der Berg', 'Mr. and Mrs.', '', 'van der Berg'],
            'comma form'          => ['Mr. and Mrs. Smith, Brad', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'comma stacked titles' => ['Doe, Rev. Dr. John', 'Rev. Dr.', 'John', 'Doe'],

            'no title after'      => ['Mr. and Brad Smith', 'Mr.', 'Brad', 'Smith'],
            'no title before'     => ['Brad and Smith', '', 'Brad', 'Smith'],
            'doubled connector'   => ['Mr. and and Mrs. Smith', 'Mr.', '', 'Smith'],

            'surname Anderson'    => ['Anderson, Andrea', '', 'Andrea', 'Anderson'],
            'given Andre'         => ['Andre Smith', '', 'Andre', 'Smith'],
            'surname Andrews'     => ['Amanda Andrews', '', 'Amanda', 'Andrews'],

            'single title'        => ['Mr. Brad Smith', 'Mr.', 'Brad', 'Smith'],
            'stacked titles'      => ['Rev. Dr John Doe', 'Rev. Dr.', 'John', 'Doe'],
            // Without a named person, the trailing title remains unattributed.
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
     * The second given name stays where it lands; ignoring a title does not identify its owner.
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

    public function testNameCollidingTitleAfterConnectorStaysAName(): void
    {
        $name = (new Parser())->parse('John Lord Smith Jr');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Lord', $name->getMiddlename());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Jr', $name->getSuffix());
    }

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

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function threeTitleProvider(): array
    {
        // input, expected salutation, expected first, expected last
        return [
            'three single-word titles' => ['Mr. and Mrs. and Ms. Brad Smith', 'Mr. and Mrs. and Ms.', 'Brad', 'Smith'],
            'mixed connectors' => ['Mr. & Mrs. and Ms. Brad Smith', 'Mr. and Mrs. and Ms.', 'Brad', 'Smith'],
            'stacked first title' => ['Rev. Dr. and Mr. and Mrs. John Doe', 'Rev. Dr. and Mr. and Mrs.', 'John', 'Doe'],
        ];
    }

    #[DataProvider('threeTitleProvider')]
    public function testThreeTitleChainWithNameJoinsAllTitles(
        string $input,
        string $salutation,
        string $first,
        string $last,
    ): void {
        $name = (new Parser())->parse($input);

        $this->assertSame($salutation, $name->getSalutation(), "salutation for '$input'");
        $this->assertSame($first, $name->getFirstname(), "firstname for '$input'");
        $this->assertSame($last, $name->getLastname(), "lastname for '$input'");
        $this->assertTrue($name->isJoint(), "isJoint for '$input'");
        $this->assertSame(
            explode(' and ', $salutation),
            $name->getSalutations(),
            "getSalutations for '$input'",
        );
    }

    #[DataProvider('jointProvider')]
    public function testIsJointReportsTwoPersonRows(string $input, bool $joint): void
    {
        $this->assertSame($joint, (new Parser())->parse($input)->isJoint(), "isJoint for '$input'");
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('salutationsProvider')]
    public function testGetSalutationsSplitsPerPerson(string $input, array $expected): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($expected, $name->getSalutations(), "getSalutations for '$input'");

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

            'stacked titles'   => ['Rev. Dr John Doe', ['Rev. Dr.']],
            'stacked and joint' => ['Rev. Dr. and Mrs. John Doe', ['Rev. Dr.', 'Mrs.']],

            'single title'     => ['Mr. Brad Smith', ['Mr.']],
            'article led'      => ['The Rev. Mark Williams', ['Rev.']],
            'no honorific'     => ['Brad Smith', []],
            'unabsorbed and'   => ['Mr. and Brad Smith', ['Mr.']],
        ];
    }

    /**
     * CiviCRM imports need a separate prefix and surname for each contact.
     */
    public function testSalutationsDrivePerContactMapping(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');
        $salutations = $name->getSalutations();

        $this->assertTrue($name->isJoint());
        $this->assertSame('Mr.', $salutations[0]);
        $this->assertSame('Mrs. Smith', $salutations[1] . ' ' . $name->getLastname());
    }

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

            'prefix surname'     => ['Mr. and Mrs. van der Berg', 'Mrs.', 'van der Berg'],

            'stacked and joint'  => ['Rev. Dr. and Mrs. John Doe', 'Mrs.', 'Doe'],

            'single title'       => ['Mr. Brad Smith', null, null],
            'no honorific'       => ['Brad Smith', null, null],
            'unabsorbed and'     => ['Mr. and Brad Smith', null, null],
            'bare two givens'    => ['Brad and Jane Smith', null, null],
            'credential-only remainder' => ['Mr. and Mrs. MD', null, null],
            'nickname-only remainder' => ['Mr. and Mrs. (Bob)', null, null],
        ];
    }

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

    public function testPartnerIsNotItselfJoint(): void
    {
        $partner = (new Parser())->parse('Mr. and Mrs. Brad Smith')->getPartner();

        $this->assertNotNull($partner);
        $this->assertFalse($partner->isJoint());
        $this->assertSame(['Mrs.'], $partner->getSalutations());
        $this->assertNull($partner->getPartner());
    }

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
            // No honorific anchors the connector, so this remains undetected.
            'bare two givens'   => ['Brad and Jane Smith', false],
            'credential-only remainder' => ['Mr. and Mrs. MD', false],
            'nickname-only remainder' => ['Mr. and Mrs. (Bob)', false],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function trailingConnectorProvider(): array
    {
        return [
            'bare trailing and'       => ['John Smith and', 'John'],
            'trailing ampersand'      => ['John Smith &', 'John'],
        ];
    }

    #[DataProvider('trailingConnectorProvider')]
    public function testTrailingConnectorDoesNotBlockSurname(string $input, string $first): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getMiddlename());
    }

    public function testTrailingConnectorAfterSalutationKeepsSurname(): void
    {
        $name = (new Parser())->parse('Mr. Smith and');

        $this->assertSame('Mr.', $name->getSalutation());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('', $name->getFirstname());
    }

    public function testNicknameBetweenConnectorAndTitleStaysTransparent(): void
    {
        $name = (new Parser())->parse('Mr. and (Bob) Mrs. Smith');

        $this->assertSame('Mr.', $name->getSalutation());
        $this->assertSame('', $name->getFirstname());
        $this->assertSame('Bob', $name->getNickname());
        $this->assertSame('Smith', $name->getLastname());
    }
}
