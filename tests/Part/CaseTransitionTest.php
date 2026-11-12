<?php

namespace Tests\Iliaal\NameParser\Part;

use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Middlename;
use Iliaal\NameParser\Part\Nickname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CaseTransitionTest extends TestCase
{
    #[DataProvider('caseTransitionProvider')]
    public function testCaseTransitionsPreserveNamePartNormalization(string $input, string $expected): void
    {
        foreach ([Firstname::class, Middlename::class, Lastname::class, Nickname::class] as $class) {
            $part = new $class($input);
            $this->assertSame($expected, $part->normalize(), $class);
            $this->assertSame($expected, $part->normalize(), $class . ' cached');
            $part->setValue('smith');
            $this->assertSame('Smith', $part->normalize(), $class . ' replaced');
            $part->setValue($input);
            $this->assertSame($expected, $part->normalize(), $class . ' restored');
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function caseTransitionProvider(): iterable
    {
        yield 'lowercase' => ['mcdonald', 'Mcdonald'];
        yield 'uppercase' => ['MCDONALD', 'Mcdonald'];
        yield 'ordinary title case' => ['Mcdonald', 'Mcdonald'];
        yield 'internal capital' => ['McDonald', 'McDonald'];
        yield 'internal lower case' => ['ABc', 'ABc'];
        yield 'first transition is not internal' => ['jOHN', 'John'];
        yield 'second transition is internal' => ['iPhone', 'iPhone'];
        yield 'count characters not bytes' => ['éA', 'Éa'];
        yield 'multibyte internal transition' => ['éAb', 'éAb'];
        yield 'multibyte uppercase run' => ['ÉAb', 'ÉAb'];
        yield 'uncased letter resets previous case' => ['ab山C', 'Ab山C'];
        yield 'case transition after uncased reset' => ['ab山cD', 'ab山cD'];
        yield 'uncased prefix counts toward position' => ['山aB', '山aB'];
        yield 'uncased middle counts toward position' => ['a山Bc', 'a山Bc'];
        yield 'titlecase letter resets uppercase' => ['ABǅc', 'Abǆc'];
        yield 'titlecase letter resets lowercase' => ['abǅC', 'Abǆc'];
        yield 'titlecase prefix counts toward position' => ['ǅAb', 'ǅAb'];
        yield 'sharp s expands in uppercase' => ['aßB', 'aßB'];
        yield 'expanded uppercase length does not set position' => ['ßA', 'Ssa'];
        yield 'transition after expanding lowercase letter' => ['ßAc', 'ßAc'];
        yield 'ligature expands in uppercase' => ['aaﬀB', 'aaﬀB'];
        yield 'transition after ligature' => ['ﬀAb', 'ﬀAb'];
        yield 'punctuation resets letter run' => ['Aa-bC', 'Aa-Bc'];
        yield 'uppercase run after punctuation' => ['Aa-BB', 'Aa-Bb'];
        yield 'digit resets letter run' => ['a1Bc', 'A1Bc'];
        yield 'lowercase run after digit' => ['AA1b', 'Aa1B'];
        yield 'Greek final sigma' => ['aΣς', 'aΣς'];
        yield 'Greek uppercase run' => ['ΑΣa', 'ΑΣa'];
        yield 'modifier letter resets previous case' => ['aᵃB', 'Aᵃb'];
        yield 'Kelvin sign case mapping' => ['aKb', 'aKb'];
        yield 'combining mark removed for transition positions' => ["é\u{0301}A", "É\u{0301}a"];
        yield 'combining mark retained in preserved spelling' => ["McDo\u{0301}nald", "McDo\u{0301}nald"];
        yield 'malformed standalone part' => ["\xFFMcDonald", "\xFFMcDonald"];
    }
}
