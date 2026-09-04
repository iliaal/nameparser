<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Mapper\AbstractMapper;
use Iliaal\NameParser\Mapper\FirstnameMapper;
use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Mapper\MiddlenameMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;

/**
 * sub-parser and mapper-pipeline construction for Parser (np-cr-026): every
 * comma-segment pipeline and its element mappers are built here from explicit
 * configuration, so adding, removing, or reordering a stage happens once.
 * Parser keeps the memoization, the invalidation, and the protected getters
 * (thin router); this factory owns the construction.
 */
final class SegmentParserFactory
{
    /**
     * the default single-segment mapper pipeline, also the base the
     * comma-segment builders derive from (np-o-13): adding, removing, or
     * reordering a stage happens here and in the element factories below, not
     * in four inline lists drifting in lockstep.
     *
     * @param  array<int|string, string>  $salutations
     * @param  array<int|string, string>  $suffixes
     * @param  array<string, string>  $delimiters
     * @param  array<string, string>  $connectors
     * @param  array<int|string, string>  $prefixes
     * @return array<int, AbstractMapper>
     */
    public static function newDefaultPipeline(
        bool $surnameSegmentBias,
        array $salutations,
        int $maxSalutationIndex,
        array $suffixes,
        array $delimiters,
        array $connectors,
        int $maxCombinedInitials,
        array $prefixes,
    ): array {
        return [
            self::newSalutationMapper($salutations, $maxSalutationIndex, false, $suffixes, $delimiters, $connectors),
            self::newSuffixMapper($suffixes, false, 2, $delimiters),
            self::newNicknameMapper($delimiters),
            self::newSuffixMapper($suffixes, false, 2, $delimiters),
            self::newInitialMapper($maxCombinedInitials, false, $prefixes),
            self::newLastnameMapper($prefixes, $surnameSegmentBias),
            new FirstnameMapper(),
            self::newMiddlenameMapper(false, $prefixes),
        ];
    }

    /**
     * @param  array<int|string, string>  $salutations
     * @param  array<int|string, string>  $suffixes
     * @param  array<string, string>  $delimiters
     * @param  array<string, string>  $connectors
     */
    public static function newSalutationMapper(
        array $salutations,
        int $maxSalutationIndex,
        bool $requireRemainder,
        array $suffixes,
        array $delimiters,
        array $connectors,
    ): SalutationMapper {
        return new SalutationMapper(
            $salutations,
            $maxSalutationIndex,
            $requireRemainder,
            $suffixes,
            $delimiters,
            $connectors,
        );
    }

    /**
     * @param  array<int|string, string>  $suffixes
     * @param  array<string, string>  $delimiters
     */
    public static function newSuffixMapper(
        array $suffixes,
        bool $matchSinglePart,
        int $reservedParts,
        array $delimiters,
    ): SuffixMapper {
        return new SuffixMapper(
            $suffixes,
            $matchSinglePart,
            $reservedParts,
            $delimiters,
        );
    }

    /**
     * @param  array<string, string>  $delimiters
     */
    public static function newNicknameMapper(array $delimiters): NicknameMapper
    {
        return new NicknameMapper($delimiters);
    }

    /**
     * @param  array<int|string, string>  $prefixes
     */
    public static function newInitialMapper(
        int $maxCombinedInitials,
        bool $matchLastPart,
        array $prefixes,
    ): InitialMapper {
        return new InitialMapper(
            $maxCombinedInitials,
            $matchLastPart,
            $prefixes,
        );
    }

    /**
     * @param  array<int|string, string>  $prefixes
     */
    public static function newLastnameMapper(
        array $prefixes,
        bool $matchSinglePart,
        bool $surnameOnly = false,
    ): LastnameMapper {
        return new LastnameMapper($prefixes, $matchSinglePart, $surnameOnly);
    }

    /**
     * @param  array<int|string, string>  $prefixes
     */
    public static function newMiddlenameMapper(bool $mapWithoutLastname, array $prefixes): MiddlenameMapper
    {
        return new MiddlenameMapper($mapWithoutLastname, $prefixes);
    }

    /**
     * sub-parsers re-enter parse() on already-split segments, so they must
     * inherit both whitespace and nickname delimiters: the structural-comma
     * mask keys off the parser's nicknameDelimiters, not the mapper
     * constructor arg
     *
     * @param  array<string, string>  $delimiters
     */
    public static function newSegmentParser(string $whitespace, array $delimiters): Parser
    {
        return (new Parser())
            ->setWhitespace($whitespace)
            ->setNicknameDelimiters($delimiters);
    }

    private function __construct() {}
}
