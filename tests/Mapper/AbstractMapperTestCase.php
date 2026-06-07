<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Mapper\AbstractMapper;
use Iliaal\NameParser\Part\AbstractPart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

abstract class AbstractMapperTestCase extends TestCase
{
    /**
     * @param  array<int, AbstractPart|string>  $input
     * @param  array<int, mixed>  $expectation
     * @param  array<int|string, mixed>  $arguments
     */
    #[DataProvider('provider')]
    public function testMap(array $input, array $expectation, array $arguments = []): void
    {
        $mapper = $this->getMapper(...$arguments);

        $this->assertEquals($expectation, $mapper->map($input));
    }

    abstract protected function getMapper(): AbstractMapper;
}
