<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Confidence;
use Iliaal\NameParser\ConnectorsInterface;
use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Language\German;
use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfidenceCommaConnectorsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, array<string, string>}>
     */
    public static function tailProvider(): iterable
    {
        yield 'bare' => ['%s', true, []];
        yield 'credential after' => ['%s MD', true, ['suffix' => 'MD']];
        yield 'credential before' => ['MD %s', true, ['suffix' => 'MD']];
        yield 'nickname' => ['%s (Bob)', true, ['nickname' => 'Bob']];
        yield 'title' => ['%s Rev.', true, []];
        yield 'title and credential' => ['%s Rev. MD', true, ['suffix' => 'MD']];
        yield 'split title' => ['%s, Dr.', true, []];
        yield 'split credential' => ['%s, MD', true, ['suffix' => 'MD']];
        yield 'credential then split title' => ['MD, %s, Rev.', true, ['suffix' => 'MD']];
        yield 'split nickname and title' => ['%s, (Bob), Rev.', true, ['nickname' => 'Bob']];
        yield 'given name' => ['%s Jack', false, ['firstname' => 'Jack']];
        yield 'colliding title' => ['%s Lady', false, ['firstname' => 'Lady']];
        yield 'colliding title and credential' => ['%s Lady MD', false, ['firstname' => 'Lady', 'suffix' => 'MD']];
        yield 'nickname and colliding title' => ['%s (Bob) Lady', false, ['firstname' => 'Lady', 'nickname' => 'Bob']];
        yield 'split colliding title' => ['%s, Lady', false, ['firstname' => 'Lady']];
        yield 'split given name' => ['%s, Jack, MD', false, ['firstname' => 'Jack', 'suffix' => 'MD']];
        // Generational suffix promotion is a parse heuristic, not evidence that
        // the original title/name collision has been resolved.
        yield 'promoted junior' => ['%s Jr', true, ['firstname' => 'Jr']];
        yield 'promoted senior' => ['%s, Sr', true, ['firstname' => 'Sr']];
        yield 'roman suffix' => ['%s, V', true, ['suffix' => 'V']];
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[DataProvider('tailProvider')]
    public function testConnectorTailsDoNotInventNameBearingContent(string $template, bool $ambiguous, array $extra): void
    {
        $custom = new class extends English implements ConnectorsInterface {
            public function getConnectors(): array
            {
                return ['plus' => '+'];
            }
        };
        foreach ([
            [new Parser(), 'and'],
            [new Parser([new English(), new German()]), 'und'],
            [new Parser([$custom]), 'plus'],
        ] as [$parser, $connector]) {
            $input = 'Lord Ashcroft, ' . sprintf($template, $connector);
            $name = $parser->parse($input);
            $this->assertEquals(['salutation' => 'Lord', 'lastname' => 'Ashcroft'] + $extra, $name->getAll(), $input);
            $expected = [
                'ambiguous' => $ambiguous,
                'notes' => $ambiguous ? ["'Lord' could be a name or a salutation; nothing in the input decides it"] : [],
            ];
            $this->assertSame($expected, $name->getConfidence(), $input);
            $this->assertSame($expected, Confidence::assess(
                $input,
                $parser->getSuffixes(),
                $parser->getSalutations(),
                connectors: $parser->getConnectors(),
            ), $input);
        }
    }

    public function testDefaultScopeAndNicknameWrappingRemainDistinct(): void
    {
        foreach (['and', '&', 'AND', 'and.'] as $connector) {
            $name = (new Parser())->parse('Lord Ashcroft, ' . $connector);
            $this->assertSame(['salutation' => 'Lord', 'lastname' => 'Ashcroft'], $name->getAll());
            $this->assertTrue($name->getConfidence()['ambiguous']);
        }
        foreach (['und', 'plus'] as $tail) {
            $input = 'Lord Ashcroft, ' . $tail;
            $this->assertFalse(Confidence::assess($input)['ambiguous']);
            $this->assertSame(Confidence::assess($input), Confidence::assess($input, connectors: []));
        }
        $this->assertSame(Confidence::assess('Lord Ashcroft, and'), Confidence::assess('Lord Ashcroft, and', connectors: []));
        $name = (new Parser())->parse('Lord Ashcroft, (and)');
        $this->assertTrue($name->getConfidence()['ambiguous']);
        $this->assertSame('And', $name->getNickname());
    }
}
