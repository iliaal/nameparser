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

    protected string $regexp;

    /**
     * @param  array<string, string>  $delimiters
     */
    public function __construct(array $delimiters = [])
    {
        if (! empty($delimiters)) {
            $this->delimiters = $delimiters;
        }

        $this->regexp = $this->buildRegexp();
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $isEncapsulated = false;

        $regexp = $this->regexp;

        $closingDelimiter = '';

        /** @var PartArray $pending parts mapped under the current still-open delimiter */
        $pending = [];

        foreach ($parts as $k => $part) {
            if ($part instanceof AbstractPart) {
                continue;
            }

            if (preg_match($regexp, $part, $matches)) {
                $isEncapsulated = true;
                $part = mb_substr($part, 1);
                $closingDelimiter = $this->delimiters[$matches[1]];
                $pending = [];
            }

            if (! $isEncapsulated) {
                continue;
            }

            $pending[$k] = $parts[$k];

            if ($closingDelimiter === mb_substr($part, -1, 1)) {
                $isEncapsulated = false;
                $part = mb_substr($part, 0, -1);
                $pending = [];
            }

            $parts[$k] = new Nickname(str_replace(['"', '\''], '', $part));
        }

        // an opening delimiter with no matching close is not a nickname: revert
        // the swallowed parts so the surname survives (e.g. "John (Bob Smith").
        if ($isEncapsulated) {
            foreach ($pending as $k => $original) {
                $parts[$k] = $original;
            }

            // the opening token still carries its unmatched delimiter char; drop
            // it so a stray "(" or quote does not leak into a name part
            // ("Bob Jones (" must not yield last name "Jones (").
            $open = array_key_first($pending);
            if ($open !== null && is_string($parts[$open])) {
                $cleaned = ltrim($parts[$open], implode('', array_keys($this->delimiters)));
                if ($cleaned === '') {
                    unset($parts[$open]);
                    $parts = array_values($parts);
                } else {
                    $parts[$open] = $cleaned;
                }
            }
        }

        return $parts;
    }

    protected function buildRegexp(): string
    {
        $regexp = '/^([';

        foreach (array_keys($this->delimiters) as $opening) {
            $regexp .= '\\' . $opening;
        }

        $regexp .= '])/';

        return $regexp;
    }
}
