<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CtypeIndependenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, string, string}>
     */
    public static function names(): iterable
    {
        yield 'ordinary surname' => ['John Smith', false, 'John', 'Smith'];
        yield 'empty input' => ['', false, '', ''];
        yield 'ASCII punctuation' => ['John Smith !!!', false, 'John', 'Smith'];
        yield 'copyright symbol' => ['John Smith ©', false, 'John', 'Smith'];
        yield 'trademark symbol' => ['John Smith ™', false, 'John', 'Smith'];
        yield 'emoji' => ['John Smith 😀', false, 'John', 'Smith'];
        yield 'accented surname' => ['John Élan', false, 'John', 'Élan'];
        yield 'Han surname' => ['John 李', false, 'John', '李'];
        yield 'Unicode numeral' => ['John ²', false, 'John', '²'];
        yield 'ASCII numeral' => ['John 123', false, 'John', '123'];
        yield 'leading punctuation with letters' => ["John 'Élan", false, 'John', "'Élan"];
        yield 'comma surname' => ['Smith, John', false, 'John', 'Smith'];
        yield 'comma surname with symbol' => ['Smith ©, John', false, 'John', 'Smith'];
        yield 'surname-first' => ['Kim Jong Un', true, 'Jong', 'Kim'];
        yield 'mapped suffix' => ['John Smith MD', false, 'John', 'Smith'];
        yield 'mapped nickname' => ['John Smith (Bob)', false, 'John', 'Smith'];
    }

    #[DataProvider('names')]
    public function testSurnameContent(string $input, bool $surnameFirst, string $firstname, string $lastname): void
    {
        $name = (new Parser())->setSurnameFirst($surnameFirst)->parse($input);

        $this->assertSame($firstname, $name->getFirstname());
        $this->assertSame($lastname, $name->getLastname());
    }

    public function testProductionAutoloadDoesNotNeedCtype(): void
    {
        $cases = var_export(array_values(iterator_to_array(self::names())), true);
        $code = <<<'PHP_CODE'
            // Do not load vendor/autoload.php: development dependencies may polyfill ctype.
            spl_autoload_register(static function (string $class): void {
                $prefix = 'Iliaal\\NameParser\\';
                if (str_starts_with($class, $prefix)) {
                    require 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                }
            });
            if (function_exists('ctype_alnum')) {
                throw new RuntimeException('ctype_alnum must be unavailable for this regression.');
            }
            $results = [];
            PHP_CODE;
        $code .= "\n";
        $code .= 'foreach (' . $cases . ' as [$input, $surnameFirst]) {'
            . '$name = (new Iliaal\\NameParser\\Parser())->setSurnameFirst($surnameFirst)->parse($input);'
            . '$results[] = [$name->getFirstname(), $name->getLastname()];'
            . '} echo json_encode($results, JSON_THROW_ON_ERROR);';

        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=' . ini_get('disable_functions') . ',ctype_alnum', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
        );
        if (! is_resource($process)) {
            self::fail('Unable to start the production-only PHP process.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $status, $stdout);
        $expected = [];
        foreach (self::names() as [, , $firstname, $lastname]) {
            $expected[] = [$firstname, $lastname];
        }
        $this->assertSame(json_encode($expected, JSON_THROW_ON_ERROR), $stdout);
    }
}
