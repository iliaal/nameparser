<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Nickname;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class NicknameMapper extends AbstractMapper
{
    /**
     * @var array<string, string>
     */
    protected array $delimiters = [
        '[' => ']',
        '{' => '}',
        '(' => ')',
        '<' => '>',
        '"' => '"',
        '\'' => '\'',
    ];

    /**
     * @param  array<string, string>  $delimiters
     */
    public function __construct(array $delimiters = [])
    {
        if (! empty($delimiters)) {
            $this->delimiters = $delimiters;
        }
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $isEncapsulated = false;

        $regexp = $this->buildRegexp();

        $closingDelimiter = '';

        foreach ($parts as $k => $part) {
            if ($part instanceof AbstractPart) {
                continue;
            }

            if (preg_match($regexp, $part, $matches)) {
                $isEncapsulated = true;
                $part = substr($part, 1);
                $closingDelimiter = $this->delimiters[$matches[1]];
            }

            if (! $isEncapsulated) {
                continue;
            }

            if ($closingDelimiter === substr($part, -1)) {
                $isEncapsulated = false;
                $part = substr($part, 0, -1);
            }

            $parts[$k] = new Nickname(str_replace(['"', '\''], '', $part));
        }

        return $parts;
    }

    protected function buildRegexp(): string
    {
        $regexp = '/^([';

        foreach ($this->delimiters as $opening => $closing) {
            $regexp .= sprintf('\\%s', $opening);
        }

        $regexp .= '])/';

        return $regexp;
    }
}
