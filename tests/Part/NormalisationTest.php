<?php

namespace Tests\Iliaal\NameParser\Part;

use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

class NormalisationTest extends TestCase
{
    use PHPMock;

    public static function setUpBeforeClass(): void
    {
        self::defineFunctionMock('Iliaal\NameParser\Part', 'function_exists');
    }

    /**
     * make sure we test both with and without mb_string support
     */
    public function testCamelcasingWorksWithMbString(): void
    {
        $functionExistsMock = $this->getFunctionMock(__NAMESPACE__, 'function_exists');
        $functionExistsMock->expects($this->any())
            ->with('mb_convert_case')
            ->willReturn(true);

        $part = new Lastname('McDonald');
        $this->assertEquals('McDonald', $part->normalize());

        $part = new Lastname('übel');
        $this->assertEquals('Übel', $part->normalize());

        $part = new Firstname('Anne-Marie');
        $this->assertEquals('Anne-Marie', $part->normalize());

        $part = new Firstname('etna');
        $this->assertEquals('Etna', $part->normalize());

        $part = new Firstname('thái');
        $this->assertEquals('Thái', $part->normalize());

        $part = new Lastname('nguyễn');
        $this->assertEquals('Nguyễn', $part->normalize());
    }

    /**
     * make sure we test both with and without mb_string support
     */
    public function testCamelcasingWorksWithoutMbString(): void
    {
        $functionExistsMock = $this->getFunctionMock(__NAMESPACE__, 'function_exists');
        $functionExistsMock->expects($this->any())
            ->with('mb_convert_case')
            ->willReturn(false);

        $part = new Lastname('McDonald');
        $this->assertEquals('McDonald', $part->normalize());

        $part = new Lastname('ubel');
        $this->assertEquals('Ubel', $part->normalize());

        $part = new Firstname('Anne-Marie');
        $this->assertEquals('Anne-Marie', $part->normalize());

        $part = new Firstname('etna');
        $this->assertEquals('Etna', $part->normalize());
    }
}
