<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InitialSurnamePrefixTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>, bool}>
     */
    public static function nameRows(): iterable
    {
        $dutch = ['firstname' => 'J.', 'initials' => '', 'lastname' => 'van Gogh', 'lastname_prefix' => 'van', 'middlename' => ''];

        yield 'dotted initial' => ['J. van Gogh', $dutch, false];
        yield 'bare initial' => ['J van Gogh', array_replace($dutch, ['firstname' => 'J']), false];
        yield 'two initials' => ['J. R. van Gogh', array_replace($dutch, ['initials' => 'R.']), false];
        yield 'combined initials' => ['JR van Gogh', array_replace($dutch, ['firstname' => 'J', 'initials' => 'R']), false];
        yield 'salutation' => ['Dr. J. van Gogh', $dutch + ['salutation' => 'Dr.'], false];
        yield 'nickname before particle' => ['J. (Jack) van Gogh', $dutch + ['nickname' => 'Jack'], false];
        yield 'nickname after particle' => ['J. van (Jack) Gogh', $dutch + ['nickname' => 'Jack'], false];
        yield 'credential' => ['J. van Gogh MD', $dutch + ['suffix' => 'MD'], false];
        yield 'empty comma tail' => ['J. van Gogh,', $dutch, false];
        yield 'credential comma tail' => ['J. van Gogh, MD', $dutch + ['suffix' => 'MD'], false];
        yield 'explicit surname segment' => ['van Gogh, J.', array_replace($dutch, ['firstname' => '', 'initials' => 'J.']), false];
        yield 'surname-first explicit comma' => ['van Gogh, J.', array_replace($dutch, ['firstname' => '', 'initials' => 'J.']), true];
        yield 'surname-first token' => ['Gogh J.', array_replace($dutch, ['firstname' => '', 'initials' => 'J.', 'lastname' => 'Gogh', 'lastname_prefix' => '']), true];
        yield 'uniform uppercase' => ['J. VAN GOGH', $dutch, false];
        yield 'two-letter particle' => ['J. de Vries', array_replace($dutch, ['lastname' => 'de Vries', 'lastname_prefix' => 'de']), false];
        yield 'Irish particle' => ['É. Ó Cuív', array_replace($dutch, ['firstname' => 'É.', 'lastname' => 'Ó Cuív', 'lastname_prefix' => 'Ó']), false];
        yield 'multiple particles' => ['J. van der Berg', array_replace($dutch, ['lastname' => 'van der Berg', 'lastname_prefix' => 'van der']), false];
        yield 'unmapped given name' => ['John van Gogh', array_replace($dutch, ['firstname' => 'John']), false];
        yield 'particle-like given name' => ['Della Smith', ['firstname' => 'Della', 'lastname' => 'Smith', 'lastname_prefix' => '', 'initials' => ''], false];
        yield 'nickname alone is not a given name' => ['(Jack) Della Smith', ['firstname' => 'Della', 'lastname' => 'Smith', 'lastname_prefix' => '', 'nickname' => 'Jack'], false];
    }

    /**
     * @param  array<string, string>  $expected
     */
    #[DataProvider('nameRows')]
    public function testInitialKeepsFollowingSurnameParticle(string $input, array $expected, bool $surnameFirst): void
    {
        $fresh = (new Parser())->setSurnameFirst($surnameFirst)->parse($input);
        $parser = (new Parser())->setSurnameFirst($surnameFirst);
        $parser->parse('Smith, JO, MD');
        $parser->parse('John (Bob) Jones');
        $reused = $parser->parse($input);

        foreach ($expected as $field => $value) {
            $this->assertSame($value, $fresh->toArray()[$field], $field . ' for ' . $input);
        }

        $this->assertSame($fresh->toArray(), $reused->toArray());
        $this->assertSame($fresh->getConfidence(), $reused->getConfidence());
        $this->assertSame($input, $fresh->getSource());
    }
}
