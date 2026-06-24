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
            'comma suffix Jr'                 => ['Williams, Hank, Jr.', 'Hank', '', 'Williams', 'Jr'],
            'comma initial + suffix'          => ['Miller, Walter M., Jr.', 'Walter', '', 'Miller', 'Jr'],
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
}
