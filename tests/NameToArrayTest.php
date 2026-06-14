<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\TestCase;

class NameToArrayTest extends TestCase
{
    private const array KEYS = [
        'salutation', 'firstname', 'initials', 'middlename', 'lastname_prefix',
        'lastname', 'suffix', 'nickname', 'given_name', 'full_name',
    ];

    public function testKeySetIsStableAndComplete(): void
    {
        $array = (new Parser())->parse('Dr. Jane A. Doe DDS')->toArray();

        $this->assertSame(self::KEYS, array_keys($array));
    }

    public function testPopulatedParts(): void
    {
        $array = (new Parser())->parse('Dr. Jane A. Doe DDS')->toArray();

        $this->assertSame('Dr.', $array['salutation']);
        $this->assertSame('Jane', $array['firstname']);
        $this->assertSame('A.', $array['initials']);
        $this->assertSame('Doe', $array['lastname']);
        $this->assertSame('DDS', $array['suffix']);
        $this->assertSame('Jane A. Doe', $array['full_name']);
    }

    public function testAbsentPartsAreEmptyStringsNotMissingKeys(): void
    {
        $array = (new Parser())->parse('John Doe')->toArray();

        // every key present even when the part is absent
        $this->assertSame(self::KEYS, array_keys($array));
        $this->assertSame('', $array['salutation']);
        $this->assertSame('', $array['initials']);
        $this->assertSame('', $array['middlename']);
        $this->assertSame('', $array['suffix']);
        $this->assertSame('', $array['nickname']);
        $this->assertSame('', $array['lastname_prefix']);
        $this->assertSame('John', $array['firstname']);
        $this->assertSame('Doe', $array['lastname']);
    }
}
