<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\FirstnameMapper;
use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Mapper\MiddlenameMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;

class Parser
{
    protected string $whitespace = " \r\n\t";

    /**
     * @var array<int, \Iliaal\NameParser\Mapper\AbstractMapper>
     */
    protected array $mappers = [];

    /**
     * @var array<int, LanguageInterface>
     */
    protected array $languages = [];

    /**
     * @var array<string, string>
     */
    protected array $nicknameDelimiters = [];

    protected int $maxSalutationIndex = 0;

    protected int $maxCombinedInitials = 2;

    /**
     * memoized merge of all languages' lastname prefixes
     *
     * @var array<string, string>|null
     */
    private ?array $prefixes = null;

    /**
     * memoized merge of all languages' suffixes
     *
     * @var array<string, string>|null
     */
    private ?array $suffixes = null;

    /**
     * memoized merge of all languages' salutations
     *
     * @var array<string, string>|null
     */
    private ?array $salutations = null;

    /**
     * memoized sub-parsers for the comma-separated segments; built once per
     * instance so a batch of comma names does not re-merge the dictionaries
     * on every row
     */
    private ?Parser $firstSegmentParser = null;

    private ?Parser $secondSegmentParser = null;

    /**
     * @param  array<int, LanguageInterface>  $languages
     */
    public function __construct(array $languages = [])
    {
        if (empty($languages)) {
            $languages = [new English()];
        }

        $this->languages = $languages;
    }

    /**
     * split full names into the following parts:
     * - prefix / salutation  (Mr., Mrs., etc)
     * - given name / first name
     * - middle initials
     * - surname / last name
     * - suffix (II, Phd, Jr, etc)
     */
    public function parse(string $name): Name
    {
        $name = $this->normalize($name);

        $segments = explode(',', $name);

        if (count($segments) > 1) {
            // everything after the first comma is the given-name segment: this
            // keeps trailing comma-separated credentials ("Smith, John, MD, PhD")
            // and a comma-separated middle name ("Smith, John, Robert") instead
            // of dropping any third+ segment
            $given = implode(' ', array_slice($segments, 1));

            return $this->parseSplitName($segments[0], $given)->setSource($name);
        }

        $parts = explode(' ', $name);

        foreach ($this->getMappers() as $mapper) {
            $parts = $mapper->map($parts);
        }

        return (new Name($parts))->setSource($name);
    }

    /**
     * handles split-parsing of comma-separated name parts: the surname segment
     * before the first comma, and the given-name segment (first/middle names
     * plus any trailing credentials) after it
     */
    protected function parseSplitName(string $surname, string $given): Name
    {
        $parts = array_merge(
            $this->getFirstSegmentParser()->parse($surname)->getParts(),
            $this->getSecondSegmentParser()->parse($given)->getParts(),
        );

        return new Name($parts);
    }

    protected function getFirstSegmentParser(): Parser
    {
        return $this->firstSegmentParser ??= (new Parser())->setMappers([
            new SalutationMapper($this->getSalutations(), $this->getMaxSalutationIndex()),
            new SuffixMapper($this->getSuffixes(), false, 2),
            new LastnameMapper($this->getPrefixes(), true),
            new FirstnameMapper(),
            new MiddlenameMapper(),
        ]);
    }

    protected function getSecondSegmentParser(): Parser
    {
        return $this->secondSegmentParser ??= (new Parser())->setMappers([
            new SalutationMapper($this->getSalutations(), $this->getMaxSalutationIndex()),
            new SuffixMapper($this->getSuffixes(), true, 0),
            new NicknameMapper($this->getNicknameDelimiters()),
            new InitialMapper($this->getMaxCombinedInitials(), true),
            new FirstnameMapper(),
            new MiddlenameMapper(true),
        ]);
    }

    /**
     * get the mappers for this parser
     *
     * @return array<int, \Iliaal\NameParser\Mapper\AbstractMapper>
     */
    public function getMappers(): array
    {
        if (empty($this->mappers)) {
            $this->setMappers([
                new NicknameMapper($this->getNicknameDelimiters()),
                new SalutationMapper($this->getSalutations(), $this->getMaxSalutationIndex()),
                new SuffixMapper($this->getSuffixes()),
                new InitialMapper($this->getMaxCombinedInitials()),
                new LastnameMapper($this->getPrefixes()),
                new FirstnameMapper(),
                new MiddlenameMapper(),
            ]);
        }

        return $this->mappers;
    }

    /**
     * set the mappers for this parser.
     *
     * Only the single-segment (non-comma) pipeline uses this list. Comma input
     * ("Last, First") is parsed by dedicated surname/given-name sub-parsers
     * (getFirstSegmentParser/getSecondSegmentParser) that build their own mapper
     * lists, so a custom list set here does not affect comma forms. The language
     * dictionaries do propagate to those sub-parsers.
     *
     * @param  array<int, \Iliaal\NameParser\Mapper\AbstractMapper>  $mappers
     */
    public function setMappers(array $mappers): Parser
    {
        $this->mappers = $mappers;

        return $this;
    }

    /**
     * drop the memoized mapper pipeline and comma-segment sub-parsers so the
     * next parse() rebuilds them from the current configuration. Config setters
     * call this; without it, changing a setting after the first parse() has no
     * effect on a reused instance.
     */
    private function invalidateMapperCache(): void
    {
        $this->mappers = [];
        $this->firstSegmentParser = null;
        $this->secondSegmentParser = null;
    }

    /**
     * normalize the name
     */
    protected function normalize(string $name): string
    {
        $whitespace = $this->getWhitespace();

        $name = trim($name);

        // preg_replace returns null on regex compile error; user-set whitespace
        // characters might produce an invalid pattern, so fall back to the input.
        $name = preg_replace('/[' . preg_quote($whitespace, '/') . ']+/', ' ', $name) ?? $name;

        // trim again: custom whitespace at the edges becomes a space above and
        // the leading trim() (default charset) would not have removed it.
        return trim($name);
    }

    /**
     * get a string of characters that are supposed to be treated as whitespace
     */
    public function getWhitespace(): string
    {
        return $this->whitespace;
    }

    /**
     * set the string of characters that are supposed to be treated as whitespace
     */
    public function setWhitespace(string $whitespace): Parser
    {
        $this->whitespace = $whitespace;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    protected function getPrefixes(): array
    {
        return $this->prefixes ??= $this->mergeFromLanguages('getLastnamePrefixes');
    }

    /**
     * @return array<string, string>
     */
    protected function getSuffixes(): array
    {
        return $this->suffixes ??= $this->mergeFromLanguages('getSuffixes');
    }

    /**
     * @return array<string, string>
     */
    protected function getSalutations(): array
    {
        return $this->salutations ??= $this->mergeFromLanguages('getSalutations');
    }

    /**
     * @param  'getSuffixes'|'getSalutations'|'getLastnamePrefixes'  $method
     * @return array<string, string>
     */
    private function mergeFromLanguages(string $method): array
    {
        $merged = [];

        foreach ($this->languages as $language) {
            $merged += $language->$method();
        }

        return $merged;
    }

    /**
     * @return array<string, string>
     */
    public function getNicknameDelimiters(): array
    {
        return $this->nicknameDelimiters;
    }

    /**
     * @param  array<string, string>  $nicknameDelimiters
     */
    public function setNicknameDelimiters(array $nicknameDelimiters): Parser
    {
        $this->nicknameDelimiters = $nicknameDelimiters;
        $this->invalidateMapperCache();

        return $this;
    }

    public function getMaxSalutationIndex(): int
    {
        return $this->maxSalutationIndex;
    }

    public function setMaxSalutationIndex(int $maxSalutationIndex): Parser
    {
        $this->maxSalutationIndex = $maxSalutationIndex;
        $this->invalidateMapperCache();

        return $this;
    }

    public function getMaxCombinedInitials(): int
    {
        return $this->maxCombinedInitials;
    }

    public function setMaxCombinedInitials(int $maxCombinedInitials): Parser
    {
        $this->maxCombinedInitials = $maxCombinedInitials;
        $this->invalidateMapperCache();

        return $this;
    }
}
