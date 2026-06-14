<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfidenceTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function ambiguousProvider(): array
    {
        return [
            'all caps, DO collides'        => ['ANH TRAN DO'],
            'all lower, do collides'       => ['anh tran do'],
            'all lower comma, do collides' => ['smith, do'],
            'all caps comma, VI collides'  => ['NGUYEN, VI'],
            // all-caps Census-surname colliders: casing carries no signal, so
            // the stripped roman numeral / MBA could equally be a surname
            'all caps surname-collider II'  => ['JOHN SMITH II'],
            'all caps surname-collider MBA' => ['JANE DOE MBA'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decidableProvider(): array
    {
        return [
            'title-case surname Do'    => ['Anh Tran Do'],
            'title-case given Vi'      => ['Nguyen, Vi'],
            'all-caps credential DDS'  => ['Jane Doe DDS'],
            'comma credential DO'      => ['Robert Brown, DO'],
            'plain name'               => ['John Doe'],
            // uppercase credential-leaning keys must not flag (data is often
            // all-caps; RN/PT strip cleanly and aren't name-leaning)
            'all-caps credential RN'   => ['DONNA BARRETT, RN'],
            'all-caps credential PT'   => ['MARY JONES, PT'],
        ];
    }

    #[DataProvider('ambiguousProvider')]
    public function testFlagsUninformativeCasing(string $input): void
    {
        $result = Confidence::assess($input);

        $this->assertTrue($result['ambiguous'], "expected '$input' to be flagged ambiguous");
        $this->assertNotEmpty($result['notes']);
    }

    #[DataProvider('decidableProvider')]
    public function testDoesNotFlagDecidableInput(string $input): void
    {
        $result = Confidence::assess($input);

        $this->assertFalse($result['ambiguous'], "expected '$input' to be decidable");
        $this->assertSame([], $result['notes']);
    }
}
