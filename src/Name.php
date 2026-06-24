<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Part\AbstractPart;

class Name
{
    private const string PARTS_NAMESPACE = 'Iliaal\NameParser\Part';

    /**
     * @var array<int, AbstractPart|string> the parts that make up this name
     */
    protected array $parts = [];

    /**
     * the normalized input this name was parsed from, retained so the advisory
     * confidence signal can be derived from the same string the parser saw
     */
    protected ?string $source = null;

    /**
     * constructor takes the array of parts this name consists of
     *
     * @param  array<int, AbstractPart|string>|null  $parts
     */
    public function __construct(?array $parts = null)
    {
        if ($parts !== null) {
            $this->setParts($parts);
        }
    }

    public function __toString(): string
    {
        return implode(' ', $this->getAll(true));
    }

    /**
     * set the parts this name consists of
     *
     * @param  array<int, AbstractPart|string>  $parts
     * @return $this
     */
    public function setParts(array $parts): Name
    {
        $this->parts = $parts;

        return $this;
    }

    /**
     * get the parts this name consists of
     *
     * @return array<int, AbstractPart|string>
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * record the normalized input this name was parsed from
     *
     * @return $this
     */
    public function setSource(string $source): Name
    {
        $this->source = $source;

        return $this;
    }

    /**
     * advisory confidence signal for this parse, derived from the same input
     * the parser saw; falls back to the reconstructed name when no source was
     * recorded (e.g. a manually constructed Name). parse() is unaffected: this
     * is a read-only check the caller opts into.
     *
     * @return array{ambiguous: bool, notes: list<string>}
     */
    public function getConfidence(): array
    {
        return Confidence::assess($this->source ?? $this->__toString());
    }

    /**
     * machine-readable view of every part with a stable key set: each key is
     * always present, empty string when the part is absent. Unlike getAll(),
     * which omits empties and varies its keys, this is safe to consume without
     * existence checks.
     *
     * @return array{salutation: string, firstname: string, initials: string, middlename: string, lastname_prefix: string, lastname: string, suffix: string, nickname: string, given_name: string, full_name: string}
     */
    public function toArray(): array
    {
        return [
            'salutation' => $this->getSalutation(),
            'firstname' => $this->getFirstname(),
            'initials' => $this->getInitials(),
            'middlename' => $this->getMiddlename(),
            'lastname_prefix' => $this->getLastnamePrefix(),
            'lastname' => $this->getLastname(),
            'suffix' => $this->getSuffix(),
            'nickname' => $this->getNickname(),
            'given_name' => $this->getGivenName(),
            'full_name' => $this->getFullName(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAll(bool $format = false): array
    {
        $results = [];
        $keys = [
            'salutation' => [],
            'firstname' => [],
            'nickname' => [$format],
            'middlename' => [],
            'initials' => [],
            'lastname' => [],
            'suffix' => [],
        ];

        foreach ($keys as $key => $args) {
            $method = 'get' . ucfirst($key);
            /** @var callable(): string $callable */
            $callable = [$this, $method];
            if ($value = $callable(...$args)) {
                $results[$key] = $value;
            }
        }

        return $results;
    }

    /**
     * get the given name (first name, middle names and initials)
     * in the order they were entered while still applying normalisation
     */
    public function getGivenName(): string
    {
        return $this->export('GivenNamePart');
    }

    /**
     * get the given name followed by the last name (including any prefixes)
     */
    public function getFullName(): string
    {
        $parts = array_filter(
            [$this->getGivenName(), $this->getLastname()],
            static fn(string $part): bool => $part !== '',
        );

        return implode(' ', $parts);
    }

    /**
     * get the first name
     */
    public function getFirstname(): string
    {
        return $this->export('Firstname');
    }

    /**
     * get the last name
     */
    public function getLastname(bool $pure = false): string
    {
        return $this->export('Lastname', $pure);
    }

    /**
     * get the last name prefix
     */
    public function getLastnamePrefix(): string
    {
        return $this->export('LastnamePrefix');
    }

    /**
     * get the initials
     */
    public function getInitials(): string
    {
        return $this->export('Initial');
    }

    /**
     * get the suffix(es)
     */
    public function getSuffix(): string
    {
        return $this->export('Suffix');
    }

    /**
     * get the salutation(s)
     */
    public function getSalutation(): string
    {
        return $this->export('Salutation');
    }

    /**
     * get the nick name(s)
     */
    public function getNickname(bool $wrap = false): string
    {
        $nickname = $this->export('Nickname');

        if ($wrap && $nickname !== '') {
            return '(' . $nickname . ')';
        }

        return $nickname;
    }

    /**
     * get the middle name(s)
     */
    public function getMiddlename(): string
    {
        return $this->export('Middlename');
    }

    /**
     * helper method used by getters to extract and format relevant name parts
     */
    protected function export(string $type, bool $strict = false): string
    {
        $matched = [];

        foreach ($this->parts as $part) {
            if ($part instanceof AbstractPart && $this->isType($part, $type, $strict)) {
                $matched[] = $part->normalize();
            }
        }

        return implode(' ', $matched);
    }

    /**
     * helper method to check if a part is of the given type
     */
    protected function isType(AbstractPart $part, string $type, bool $strict = false): bool
    {
        $className = self::PARTS_NAMESPACE . '\\' . $type;

        if ($strict) {
            return $part::class === $className;
        }

        return is_a($part, $className);
    }
}
