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
     * @param  array<int, AbstractPart|string>  $expectation
     * @param  array<int|string, mixed>  $arguments
     */
    #[DataProvider('provider')]
    public function testMap(array $input, array $expectation, array $arguments = []): void
    {
        $mapper = $this->getMapper(...$arguments);

        $this->assertSame(
            self::canonicalParts($expectation),
            self::canonicalParts($mapper->map($input)),
        );
    }

    public function testMapAcceptsSparseIntegerKeys(): void
    {
        $dense = ['John', 'Smith'];
        $sparse = [5 => 'John', 9 => 'Smith'];

        $this->assertSame(
            self::canonicalParts($this->getMapper()->map($dense)),
            self::canonicalParts($this->getMapper()->map($sparse)),
        );
    }

    /**
     * Class + value + normalized-form descriptors for part output: part
     * objects are never identical instances across a mapping, so a bare
     * assertSame cannot pass, while assertEquals hides order/type drift
     * behind loose comparison. Canonicalizing first makes assertSame exact.
     * The normalized form pins dictionary rendering: a Suffix drift
     * (PHD/PHD vs PHD/PhD) shares class + raw value but renders differently,
     * so dropping normalize() lets it pass (np-r2-01).
     *
     * @param  array<int, AbstractPart|string>  $parts
     * @return list<string>
     */
    final protected static function canonicalParts(array $parts): array
    {
        return array_map(
            static fn(AbstractPart|string $part): string => $part instanceof AbstractPart
                ? $part::class . "\0" . $part->getValue() . "\0" . $part->normalize()
                : "\0" . $part,
            array_values($parts),
        );
    }

    abstract protected function getMapper(): AbstractMapper;
}
