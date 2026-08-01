<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use Iliaal\NameParser\Language\German;
use Iliaal\NameParser\LanguageInterface;
use Iliaal\NameParser\Mapper\AbstractMapper;
use Iliaal\NameParser\Mapper\FirstnameMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Name;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    /**
     * @return array<int, array{string, array<string, string>}>
     */
    public static function provider(): array
    {
        return [
            [
                'James Norrington',
                [
                    'firstname' => 'James',
                    'lastname' => 'Norrington',
                ],

            ],
            [
                'Hans Christian Anderssen',
                [
                    'firstname' => 'Hans',
                    'lastname' => 'Anderssen',
                    'middlename' => 'Christian',
                ],
            ],
            [
                'Mr Anthony R Von Fange III',
                [
                    'salutation' => 'Mr.',
                    'firstname' => 'Anthony',
                    'initials' => 'R',
                    'lastname' => 'von Fange',
                    'suffix' => 'III',
                ],
            ],
            [
                'J. B. Hunt',
                [
                    'firstname' => 'J.',
                    'initials' => 'B.',
                    'lastname' => 'Hunt',
                ],
            ],
            [
                'J.B. Hunt',
                [
                    'firstname' => 'J',
                    'initials' => 'B',
                    'lastname' => 'Hunt',
                ],
            ],
            [
                'Edward Senior III',
                [
                    'firstname' => 'Edward',
                    'lastname' => 'Senior',
                    'suffix' => 'III',
                ],
            ],
            [
                'Edward Dale Senior II',
                [
                    'firstname' => 'Edward',
                    'lastname' => 'Dale',
                    'suffix' => 'Senior II',
                ],
            ],
            [
                'Dale Edward Jones Senior',
                [
                    'firstname' => 'Dale',
                    'middlename' => 'Edward',
                    'lastname' => 'Jones',
                    'suffix' => 'Senior',
                ],
            ],
            [
                'Jason Rodriguez Sr.',
                [
                    'firstname' => 'Jason',
                    'lastname' => 'Rodriguez',
                    'suffix' => 'Sr',
                ],
            ],
            [
                'Jason Senior',
                [
                    'firstname' => 'Jason',
                    'lastname' => 'Senior',
                ],
            ],
            [
                'Bill Junior',
                [
                    'firstname' => 'Bill',
                    'lastname' => 'Junior',
                ],
            ],
            [
                'Sara Ann Fraser',
                [
                    'firstname' => 'Sara',
                    'middlename' => 'Ann',
                    'lastname' => 'Fraser',
                ],
            ],
            [
                'Adam',
                [
                    'firstname' => 'Adam',
                ],
            ],
            [
                'OLD MACDONALD',
                [
                    'firstname' => 'Old',
                    'lastname' => 'Macdonald',
                ],
            ],
            [
                'Old MacDonald',
                [
                    'firstname' => 'Old',
                    'lastname' => 'MacDonald',
                ],
            ],
            [
                'Old McDonald',
                [
                    'firstname' => 'Old',
                    'lastname' => 'McDonald',
                ],
            ],
            [
                'Old Mc Donald',
                [
                    'firstname' => 'Old',
                    'middlename' => 'Mc',
                    'lastname' => 'Donald',
                ],
            ],
            [
                'Old Mac Donald',
                [
                    'firstname' => 'Old',
                    'middlename' => 'Mac',
                    'lastname' => 'Donald',
                ],
            ],
            [
                'James van Allen',
                [
                    'firstname' => 'James',
                    'lastname' => 'van Allen',
                ],
            ],
            [
                'Jimmy (Bubba) Smith',
                [
                    'firstname' => 'Jimmy',
                    'lastname' => 'Smith',
                    'nickname' => 'Bubba',
                ],
            ],
            [
                'Miss Jennifer Shrader Lawrence',
                [
                    'salutation' => 'Miss',
                    'firstname' => 'Jennifer',
                    'middlename' => 'Shrader',
                    'lastname' => 'Lawrence',
                ],
            ],
            [
                'Dr. Jonathan Smith',
                [
                    'salutation' => 'Dr.',
                    'firstname' => 'Jonathan',
                    'lastname' => 'Smith',
                ],
            ],
            [
                'Ms. Jamie P. Harrowitz',
                [
                    'salutation' => 'Ms.',
                    'firstname' => 'Jamie',
                    'initials' => 'P.',
                    'lastname' => 'Harrowitz',
                ],
            ],
            [
                'Mr John Doe',
                [
                    'salutation' => 'Mr.',
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                ],
            ],
            [
                'Rev. Dr John Doe',
                [
                    'salutation' => 'Rev. Dr.',
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                ],
            ],
            [
                'Dhr. Jan de Vries',
                [
                    'salutation' => 'Dhr.',
                    'firstname' => 'Jan',
                    'lastname' => 'de Vries',
                ],
            ],
            [
                'Mevr. Anna Bakker',
                [
                    'salutation' => 'Mevr.',
                    'firstname' => 'Anna',
                    'lastname' => 'Bakker',
                ],
            ],
            [
                'Mw. Anna Bakker',
                [
                    'salutation' => 'Mevr.',
                    'firstname' => 'Anna',
                    'lastname' => 'Bakker',
                ],
            ],
            [
                'Prof. Tyson J. Hirthe',
                [
                    'salutation' => 'Prof.',
                    'lastname' => 'Hirthe',
                    'firstname' => 'Tyson',
                    'initials' => 'J.',
                ],
            ],
            [
                'prof Eveline Aufderhar',
                [
                    'salutation' => 'Prof.',
                    'lastname' => 'Aufderhar',
                    'firstname' => 'Eveline',
                ],
            ],
            [
                'Anthony Von Fange III',
                [
                    'firstname' => 'Anthony',
                    'lastname' => 'von Fange',
                    'suffix' => 'III',
                ],
            ],
            [
                'Smarty Pants Phd',
                [
                    'firstname' => 'Smarty',
                    'lastname' => 'Pants',
                    'suffix' => 'PhD',
                ],
            ],
            [
                'Mark Peter Williams',
                [
                    'firstname' => 'Mark',
                    'middlename' => 'Peter',
                    'lastname' => 'Williams',
                ],
            ],
            [
                'Mark P Williams',
                [
                    'firstname' => 'Mark',
                    'lastname' => 'Williams',
                    'initials' => 'P',
                ],
            ],
            [
                'Mark P. Williams',
                [
                    'firstname' => 'Mark',
                    'initials' => 'P.',
                    'lastname' => 'Williams',
                ],
            ],
            [
                'M Peter Williams',
                [
                    'firstname' => 'Peter',
                    'initials' => 'M',
                    'lastname' => 'Williams',
                ],
            ],
            [
                'M. Peter Williams',
                [
                    'firstname' => 'Peter',
                    'initials' => 'M.',
                    'lastname' => 'Williams',
                ],
            ],
            [
                'M. P. Williams',
                [
                    'firstname' => 'M.',
                    'initials' => 'P.',
                    'lastname' => 'Williams',
                ],
            ],
            [
                'The Rev. Mark Williams',
                [
                    'salutation' => 'Rev.',
                    'firstname' => 'Mark',
                    'lastname' => 'Williams',
                ],
            ],
            [
                'Mister Mark Williams',
                [
                    'salutation' => 'Mr.',
                    'firstname' => 'Mark',
                    'lastname' => 'Williams',
                ],
            ],
            [
                'Fraser, Joshua',
                [
                    'firstname' => 'Joshua',
                    'lastname' => 'Fraser',
                ],
            ],
            [
                'Mrs. Brown, Amanda',
                [
                    'salutation' => 'Mrs.',
                    'firstname' => 'Amanda',
                    'lastname' => 'Brown',
                ],
            ],
            [
                "Mr.\r\nPaul\rJoseph\nMaria\tWinters",
                [
                    'salutation' => 'Mr.',
                    'firstname' => 'Paul',
                    'middlename' => 'Joseph Maria',
                    'lastname' => 'Winters',
                ],
            ],
            [
                'Van Truong',
                [
                    'firstname' => 'Van',
                    'lastname' => 'Truong',
                ],
            ],
            [
                'John Van',
                [
                    'firstname' => 'John',
                    'lastname' => 'Van',
                ],
            ],
            [
                'Mr. Van Truong',
                [
                    'salutation' => 'Mr.',
                    'firstname' => 'Van',
                    'lastname' => 'Truong',
                ],
            ],
            [
                'Anthony Von Fange III, PHD',
                [
                    'firstname' => 'Anthony',
                    'lastname' => 'von Fange',
                    'suffix' => 'III PhD',
                ],
            ],
            [
                'Jimmy (Bubba Junior) Smith',
                [
                    'nickname' => 'Bubba Junior',
                    'firstname' => 'Jimmy',
                    'lastname' => 'Smith',
                ],
            ],
            [
                'Jonathan Smith, MD',
                [
                    'firstname' => 'Jonathan',
                    'lastname' => 'Smith',
                    'suffix' => 'MD',
                ],
            ],
            [
                'Kirk, James T.',
                [
                    'firstname' => 'James',
                    'initials' => 'T.',
                    'lastname' => 'Kirk',
                ],
            ],
            [
                'James B',
                [
                    'firstname' => 'James',
                    'lastname' => 'B',
                ],
            ],
            [
                'Williams, Hank, Jr.',
                [
                    'firstname' => 'Hank',
                    'lastname' => 'Williams',
                    'suffix' => 'Jr',
                ],
            ],
            [
                'Sir James Reynolds, Junior',
                [
                    'salutation' => 'Sir',
                    'firstname' => 'James',
                    'lastname' => 'Reynolds',
                    'suffix' => 'Junior',
                ],
            ],
            [
                'Sir John Paul Getty Sr.',
                [
                    'salutation' => 'Sir',
                    'firstname' => 'John',
                    'middlename' => 'Paul',
                    'lastname' => 'Getty',
                    'suffix' => 'Sr',
                ],
            ],
            [
                'etna übel',
                [
                    'firstname' => 'Etna',
                    'lastname' => 'Übel',
                ],
            ],
            [
                'Markus Müller',
                [
                    'firstname' => 'Markus',
                    'lastname' => 'Müller',
                ],
            ],
            [
                'Charles Dixon (20th century)',
                [
                    'firstname' => 'Charles',
                    'lastname' => 'Dixon',
                    'nickname' => '20Th Century',
                ],
            ],
            [
                'Smith, John Eric',
                [
                    'lastname' => 'Smith',
                    'firstname' => 'John',
                    'middlename' => 'Eric',
                ],
            ],
            [
                'PAUL M LEWIS MR',
                [
                    'firstname' => 'Paul',
                    'initials' => 'M',
                    'lastname' => 'Lewis Mr',
                ],
            ],
            [
                'SUJAN MASTER',
                [
                    'firstname' => 'Sujan',
                    'lastname' => 'Master',
                ],
            ],
            [
                'JAMES J MA',
                [
                    'firstname' => 'James',
                    'initials' => 'J',
                    'lastname' => 'Ma',
                ],
            ],
            [
                'Tiptree, James, Jr.',
                [
                    'lastname' => 'Tiptree',
                    'firstname' => 'James',
                    'suffix' => 'Jr',
                ],
            ],
            [
                'Miller, Walter M., Jr.',
                [
                    'lastname' => 'Miller',
                    'firstname' => 'Walter',
                    'initials' => 'M.',
                    'suffix' => 'Jr',
                ],
            ],
            [
                'Tiptree, James Jr.',
                [
                    'lastname' => 'Tiptree',
                    'firstname' => 'James',
                    'suffix' => 'Jr',
                ],
            ],
            [
                'Miller, Walter M. Jr.',
                [
                    'lastname' => 'Miller',
                    'firstname' => 'Walter',
                    'initials' => 'M.',
                    'suffix' => 'Jr',
                ],
            ],
            [
                'Thái Quốc Nguyễn',
                [
                    'lastname' => 'Nguyễn',
                    'middlename' => 'Quốc',
                    'firstname' => 'Thái',
                ],
            ],
            [
                'Yumeng Du',
                [
                    'lastname' => 'Du',
                    'firstname' => 'Yumeng',
                ],
            ],
            [
                'Her Honour Mrs Judy',
                [
                    'lastname' => 'Judy',
                    'salutation' => 'Her Honour Mrs.',
                ],
            ],
            [
                'Etje Heijdanus-De Boer',
                [
                    'firstname' => 'Etje',
                    'lastname' => 'Heijdanus-De Boer',
                ],
            ],
            [
                'JB Hunt',
                [
                    'firstname' => 'J',
                    'initials' => 'B',
                    'lastname' => 'Hunt',
                ],
            ],
            [
                'Charles Philip Arthur George Mountbatten-Windsor',
                [
                    'firstname' => 'Charles',
                    'middlename' => 'Philip Arthur George',
                    'lastname' => 'Mountbatten-Windsor',
                ],
            ],
            [
                'Ella Marija Lani Yelich-O\'Connor',
                [
                    'firstname' => 'Ella',
                    'middlename' => 'Marija Lani',
                    'lastname' => 'Yelich-O\'Connor',
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
        $parser = new Parser();
        $name = $parser->parse($input);

        $this->assertInstanceOf(Name::class, $name);
        $this->assertEquals($expectation, $name->getAll());
    }

    public function testSetGetWhitespace(): void
    {
        $parser = new Parser();
        $parser->setWhitespace('abc');
        $this->assertSame('abc', $parser->getWhitespace());
        $parser->setWhitespace(' ');
        $this->assertSame(' ', $parser->getWhitespace());
        $parser->setWhitespace('   _');
        $this->assertSame('   _', $parser->getWhitespace());
    }

    public function testSplitParsersHonorConfiguredWhitespace(): void
    {
        $parser = (new Parser())->setWhitespace('');

        $plain = $parser->parse("John\tSmith");
        $comma = $parser->parse("Smith, John\tRobert");

        $this->assertSame("John\tSmith", $plain->getFirstname());
        $this->assertSame("John\tRobert", $comma->getFirstname());
        $this->assertSame('', $comma->getMiddlename());
        $this->assertSame('Smith', $comma->getLastname());
    }

    public function testSetGetNicknameDelimiters(): void
    {
        $parser = new Parser();
        $parser->setNicknameDelimiters(['[' => ']']);
        $this->assertSame(['[' => ']'], $parser->getNicknameDelimiters());
        $this->assertSame('Jim', $parser->parse('[Jim]')->getNickname());
        $this->assertNotSame('Jim', $parser->parse('(Jim)')->getNickname());
    }

    public function testMultibyteNicknameDelimiterExtractsNickname(): void
    {
        $parser = new Parser();
        $parser->setNicknameDelimiters(['«' => '»']);

        $name = $parser->parse('Charles «Bob» Dixon');

        $this->assertSame('Charles', $name->getFirstname());
        $this->assertSame('Dixon', $name->getLastname());
        $this->assertSame('Bob', $name->getNickname());
    }

    public function testMultiCharNicknameDelimiterExtractsNickname(): void
    {
        $parser = new Parser();
        $parser->setNicknameDelimiters(['<<' => '>>']);

        $name = $parser->parse('Charles <<Bob>> Dixon');

        $this->assertSame('Charles', $name->getFirstname());
        $this->assertSame('Dixon', $name->getLastname());
        $this->assertSame('Bob', $name->getNickname());
    }

    public function testEmptyStringNicknameDelimiterParsesWithoutWarning(): void
    {
        // an empty-string delimiter key would compile to a degenerate regexp and
        // warn per token; it must be filtered so parsing proceeds normally.
        // phpunit.xml sets failOnWarning, so a warning here fails the test.
        $parser = new Parser();
        $parser->setNicknameDelimiters(['' => '']);

        $name = $parser->parse('John Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testSetMaxSalutationIndex(): void
    {
        $parser = new Parser();
        $this->assertSame(0, $parser->getMaxSalutationIndex());
        $parser->setMaxSalutationIndex(1);
        $this->assertSame(1, $parser->getMaxSalutationIndex());
        $this->assertSame('', $parser->parse('Francis Mr')->getSalutation());

        $parser = new Parser();
        $this->assertSame(0, $parser->getMaxSalutationIndex());
        $parser->setMaxSalutationIndex(2);
        $this->assertSame(2, $parser->getMaxSalutationIndex());
        $this->assertSame('Mr.', $parser->parse('Francis Mr')->getSalutation());
    }

    public function testNegativeMaxSalutationIndexUsesDefaultSemantics(): void
    {
        $input = 'John Lord Smith Jr';
        $default = (new Parser())->parse($input);
        $negative = (new Parser())->setMaxSalutationIndex(-1)->parse($input);

        $this->assertSame($default->toArray(), $negative->toArray());
    }

    public function testSetMaxCombinedInitials(): void
    {
        $parser = new Parser();
        $this->assertSame(2, $parser->getMaxCombinedInitials());
        $parser->setMaxCombinedInitials(1);
        $this->assertSame(1, $parser->getMaxCombinedInitials());
        $this->assertSame('', $parser->parse('DJ Westbam')->getInitials());

        $parser = new Parser();
        $this->assertSame(2, $parser->getMaxCombinedInitials());
        $parser->setMaxCombinedInitials(3);
        $this->assertSame(3, $parser->getMaxCombinedInitials());
        $this->assertSame('P A G', $parser->parse('Charles PAG Mountbatten-Windsor')->getInitials());
    }

    public function testRejectsMaxCombinedInitialsAboveHardCeiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Parser())->setMaxCombinedInitials(65);
    }

    public function testRejectsNegativeMaxCombinedInitials(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Parser())->setMaxCombinedInitials(-1);
    }

    public function testConfigChangeAfterFirstParseTakesEffect(): void
    {
        // a reused parser must honor a setter called after the first parse(),
        // not the configuration cached on that first call

        $parser = new Parser();
        $parser->parse('Anne Jones');
        $parser->setMaxCombinedInitials(1);
        $this->assertSame('', $parser->parse('DJ Westbam')->getInitials());

        $parser = new Parser();
        $parser->parse('Anne Jones');
        $parser->setMaxSalutationIndex(2);
        $this->assertSame('Mr.', $parser->parse('Francis Mr')->getSalutation());

        $parser = new Parser();
        $parser->parse('John (Bob) Smith');
        $parser->setNicknameDelimiters(['<' => '>']);
        $this->assertSame('Bob', $parser->parse('John <Bob> Smith')->getNickname());
        $this->assertSame('', $parser->parse('John (Bob) Smith')->getNickname());
    }

    public function testConfigSettersPreserveCustomMappers(): void
    {
        $parser = (new Parser())->setMappers([new FirstnameMapper()]);
        $parser->setMaxCombinedInitials(3);
        $this->assertSame('', $parser->parse('John Smith')->getLastname());

        $parser = (new Parser())->setMappers([new FirstnameMapper()]);
        $parser->setMaxSalutationIndex(2);
        $this->assertSame('', $parser->parse('John Smith')->getLastname());

        $parser = (new Parser())->setMappers([new FirstnameMapper()]);
        $parser->setNicknameDelimiters(['<' => '>']);
        $this->assertSame('', $parser->parse('John Smith')->getLastname());
    }

    public function testConfigSetterPreservesCallerOwnedMapperState(): void
    {
        $suffixMapper = new SuffixMapper(['xyz' => 'XYZ']);
        $parser = (new Parser())->setMappers([$suffixMapper]);

        $this->assertSame('XYZ', $parser->parse('John XYZ')->getSuffix());

        $parser->setWhitespace(' ');

        $this->assertSame($suffixMapper, $parser->getMappers()[0]);
        $this->assertSame('XYZ', $parser->parse('John XYZ')->getSuffix());
    }

    public function testSparseCustomMapperOutputIsNormalizedForStockMappers(): void
    {
        $sparseMapper = new class extends AbstractMapper {
            #[\Override]
            public function map(array $parts): array
            {
                return [5 => $parts[0]];
            }
        };

        $name = (new Parser())
            ->setMappers([$sparseMapper, new FirstnameMapper()])
            ->parse('John');

        $this->assertSame('John', $name->getFirstname());
    }

    public function testCommaParsingUsesProtectedSplitHook(): void
    {
        $parser = new class extends Parser {
            public bool $splitHookCalled = false;

            #[\Override]
            protected function parseSplitName(string $surname, string $given): Name
            {
                $this->splitHookCalled = true;

                return parent::parseSplitName($surname, $given);
            }
        };

        $name = $parser->parse('Smith, John, MD');

        $this->assertTrue($parser->splitHookCalled);
        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testSubclassMayOverrideSettersWithConcreteReturnType(): void
    {
        // the released 1.x API declares concrete Parser returns; a subclass
        // overriding a fluent setter with ": Parser" must keep loading
        $parser = new class extends Parser {
            public bool $overrideRan = false;

            #[\Override]
            public function setWhitespace(string $whitespace): Parser
            {
                $this->overrideRan = true;

                return parent::setWhitespace($whitespace);
            }
        };

        $parser->setWhitespace('_');
        $this->assertTrue($parser->overrideRan);
        $this->assertSame('John', $parser->parse('_John_Smith_')->getFirstname());
    }

    public function testSubclassMayDeclareItsOwnCustomMappersProperty(): void
    {
        // internal bookkeeping stays private so this declaration cannot collide
        $parser = new class extends Parser {
            /**
             * @var array<int, string>
             */
            protected array $customMappers = [];
        };

        $this->assertSame('Smith', $parser->parse('John Smith')->getLastname());
    }

    public function testMultiCharDelimiterShieldsNicknameCommas(): void
    {
        $parser = (new Parser())->setNicknameDelimiters(['<<' => '>>']);

        $name = $parser->parse('John <<Bob, Jr>> Doe');
        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Doe', $name->getLastname());
        $this->assertSame('Bob, Jr', $name->getNickname());

        $comma = $parser->parse('Smith, John <<Jack, Rob>>');
        $this->assertSame('Smith', $comma->getLastname());
        $this->assertSame('Jack, Rob', $comma->getNickname());
    }

    /**
     * custom multi-char pairs must shield on the left segment when a structural
     * comma follows (segment sub-parsers re-enter parse() and must inherit the
     * delimiter map used by the structural-comma mask)
     */
    public function testCustomDelimiterLeftSegmentWithTrailingCredential(): void
    {
        foreach ([['%%' => '%%'], ['«' => '»'], ['<<' => '>>']] as $delimiters) {
            $parser = (new Parser())->setNicknameDelimiters($delimiters);
            $open = array_key_first($delimiters);
            $close = $delimiters[$open];
            $input = "John {$open}Bob, Jr{$close} Smith, MD";
            $name = $parser->parse($input);

            $this->assertSame('John', $name->getFirstname(), $input);
            $this->assertSame('Smith', $name->getLastname(), $input);
            $this->assertSame('Bob, Jr', $name->getNickname(), $input);
            $this->assertSame('MD', $name->getSuffix(), $input);
        }
    }

    public function testCustomDelimiterNicknameOnSurnameWithGivenSegment(): void
    {
        $parser = (new Parser())->setNicknameDelimiters(['%%' => '%%']);
        $name = $parser->parse('%%Bob, Jr%% Smith, John');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Bob, Jr', $name->getNickname());
    }

    public function testEmptyWhitespaceDropsEmptyTokensFromExports(): void
    {
        $name = (new Parser())->setWhitespace('')->parse('John  Smith');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('John', $name->getGivenName());
        $this->assertSame('John Smith', $name->getFullName());
    }

    public function testSetMappersDoesNotAffectCommaForm(): void
    {
        $parser = (new Parser())->setMappers([new FirstnameMapper()]);

        $this->assertSame('', $parser->parse('John Smith')->getLastname());
        $this->assertSame('Smith', $parser->parse('Smith, John')->getLastname());
        $this->assertSame('John', $parser->parse('Smith, John')->getFirstname());
    }

    public function testSetMappersDoesNotAffectSurnameFirst(): void
    {
        $parser = (new Parser())
            ->setMappers([new FirstnameMapper()])
            ->setSurnameFirst(true);

        $this->assertSame('', $parser->setSurnameFirst(false)->parse('John Smith')->getLastname());

        $parser->setSurnameFirst(true);
        $name = $parser->parse('Mao Zedong');
        $this->assertSame('Mao', $name->getLastname());
        $this->assertSame('Zedong', $name->getFirstname());
    }

    public function testPromotedDefaultMappersPickUpConfigChanges(): void
    {
        $parser = new Parser();
        $parser->setMappers($parser->getMappers());
        $parser->setMaxCombinedInitials(4);

        $this->assertSame('J M A B', $parser->parse('John JMAB Walker')->getInitials());
        $this->assertSame('J M A B', $parser->parse('Walker, JMAB John')->getInitials());
    }

    public function testRepromotedDefaultMappersKeepConfigResync(): void
    {
        // an identity re-set of the already-promoted list must not clear the
        // resync latch and silently detach config setters
        $parser = new Parser();
        $parser->setMappers($parser->getMappers());
        $parser->setMappers($parser->getMappers());
        $parser->setMaxCombinedInitials(4);

        $this->assertSame('J M A B', $parser->parse('John JMAB Walker')->getInitials());
    }

    public function testPromotedDefaultMappersRefreshMutableLanguagePrefixes(): void
    {
        $language = new class implements LanguageInterface {
            /**
             * @var array<string, string>
             */
            public array $prefixes = ['old' => 'old'];

            #[\Override]
            public function getSuffixes(): array
            {
                return [];
            }

            #[\Override]
            public function getLastnamePrefixes(): array
            {
                return $this->prefixes;
            }

            #[\Override]
            public function getSalutations(): array
            {
                return [];
            }
        };

        $parser = new Parser([$language]);
        $parser->setMappers($parser->getMappers());
        $language->prefixes = ['xyz' => 'xyz'];
        $parser->setWhitespace($parser->getWhitespace());

        $western = $parser->parse('John xyz Smith');
        $comma = $parser->parse('xyz Smith, John');

        $this->assertSame('xyz', $western->getLastnamePrefix());
        $this->assertSame('xyz Smith', $western->getLastname());
        $this->assertSame($comma->toArray(), $western->toArray());
    }

    public function testGetSuffixesIsPublicForConfidenceAssess(): void
    {
        $parser = new Parser();
        $suffixes = $parser->getSuffixes();

        $this->assertArrayHasKey('md', $suffixes);
        $this->assertFalse(Confidence::assess('ANH TRAN DO', [])['ambiguous']);
        $this->assertTrue(Confidence::assess('ANH TRAN DO', $suffixes)['ambiguous']);
    }

    public function testParsedNameRecordsNormalizedSource(): void
    {
        $name = (new Parser())->parse('  Jane Doe  ');

        $this->assertSame('Jane Doe', $name->getSource());
    }

    public function testMismatchedNestedCloserDoesNotLeakIntoNameParts(): void
    {
        $name = (new Parser())->parse('John (Bob [X) Y] Z)');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('', $name->getInitials());
        $this->assertSame('', $name->getLastname());
        $this->assertSame('Bob [X) Y] Z', $name->getNickname());
    }

    public function testSetMappersWithEmptyListRestoresDefaultPipeline(): void
    {
        $parser = (new Parser())->setMappers([new FirstnameMapper()]);
        $this->assertSame('', $parser->parse('John Smith')->getLastname());

        $parser->setMappers([]);
        $this->assertSame('Smith', $parser->parse('John Smith')->getLastname());
    }

    public function testParserAndSubparsersProperlyHandleLanguages(): void
    {
        $parser = new Parser([
            new German(),
        ]);

        $this->assertSame('Herr', $parser->parse('Herr Schmidt')->getSalutation());
        $this->assertSame('Herr', $parser->parse('Herr Schmidt, Bernd')->getSalutation());
    }

    public function testFluentSettersReturnSameInstance(): void
    {
        $parser = new Parser();

        $this->assertSame($parser, $parser->setWhitespace(" \t"));
        $this->assertSame($parser, $parser->setNicknameDelimiters(['(' => ')']));
        $this->assertSame($parser, $parser->setMaxSalutationIndex(3));
        $this->assertSame($parser, $parser->setMaxCombinedInitials(2));
        $this->assertSame($parser, $parser->setSurnameFirst(true));
        $this->assertSame($parser, $parser->setMappers([]));
    }
}
