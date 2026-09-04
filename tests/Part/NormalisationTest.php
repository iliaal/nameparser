<?php

namespace Tests\Iliaal\NameParser\Part;

use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\TestCase;

class NormalisationTest extends TestCase
{
    public function testCamelcasingNormalizesUnicodeNames(): void
    {
        $part = new Lastname('McDonald');
        $this->assertSame('McDonald', $part->normalize());

        $part = new Lastname('übel');
        $this->assertSame('Übel', $part->normalize());

        $part = new Firstname('Anne-Marie');
        $this->assertSame('Anne-Marie', $part->normalize());

        $part = new Firstname('etna');
        $this->assertSame('Etna', $part->normalize());

        $part = new Firstname('thái');
        $this->assertSame('Thái', $part->normalize());

        $part = new Lastname('nguyễn');
        $this->assertSame('Nguyễn', $part->normalize());
    }

    public function testCamelcasingTreatsDecomposedAccentAsOneWord(): void
    {
        $decomposed = "Rene\u{0301}e";

        $this->assertSame($decomposed, (new Firstname($decomposed))->normalize());
        $this->assertSame($decomposed, (new Firstname("rene\u{0301}e"))->normalize());
    }

    /**
     * Long-token fast paths: at most 1024 bytes the mixed-case shape is
     * preserved verbatim (a McDonald-shaped 300-char token survives), past
     * it the token takes the one-pass title-case (no per-run callback), so
     * the 1100-char token folds. The multibyte tail proves the fold is
     * character-safe rather than byte-truncating.
     */
    public function testLongMixedCaseTokenNormalizeFastPaths(): void
    {
        $short = str_repeat('aB', 150);
        $this->assertSame(300, strlen($short));
        $this->assertSame($short, (new Lastname($short))->normalize());

        $long = str_repeat('aB', 550);
        $this->assertSame(1100, strlen($long));
        $this->assertSame(mb_convert_case($long, MB_CASE_TITLE, 'UTF-8'), (new Lastname($long))->normalize());
        $this->assertSame('Ab' . str_repeat('ab', 549), (new Lastname($long))->normalize());

        $multibyteTail = str_repeat('aB', 548) . "\u{00E9}X";
        $this->assertSame(mb_convert_case($multibyteTail, MB_CASE_TITLE, 'UTF-8'), (new Lastname($multibyteTail))->normalize());
    }
}
