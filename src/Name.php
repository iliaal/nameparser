<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Part\AbstractPart;

class Name
{
    private const PARTS_NAMESPACE = 'Iliaal\NameParser\Part';

    /**
     * @var array<int, AbstractPart|string> the parts that make up this name
     */
    protected array $parts = [];

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
            $method = sprintf('get%s', ucfirst($key));
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
        return sprintf('%s %s', $this->getGivenName(), $this->getLastname());
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
            return sprintf('(%s)', $nickname);
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
        $className = sprintf('%s\\%s', self::PARTS_NAMESPACE, $type);

        if ($strict) {
            return get_class($part) === $className;
        }

        return is_a($part, $className);
    }
}
