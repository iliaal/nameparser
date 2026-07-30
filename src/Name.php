<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\LastnamePrefix;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\SalutationConnector;

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
     * @var array<int|string, string>|null
     */
    protected ?array $confidenceSuffixes = null;

    /**
     * @var array<int|string, string>|null
     */
    protected ?array $confidenceSalutations = null;

    /**
     * @var list<string>|null
     */
    protected ?array $confidenceTokens = null;

    /**
     * constructor takes the array of parts this name consists of
     *
     * raw string parts are retained in getParts() but ignored by every getter
     * and by export(): the getters only ever read AbstractPart instances.
     *
     * @param  array<int, AbstractPart|string>|null  $parts
     * @param  array<int|string, string>|null  $confidenceSuffixes
     * @param  array<int|string, string>|null  $confidenceSalutations
     */
    public function __construct(
        ?array $parts = null,
        ?array $confidenceSuffixes = null,
        ?array $confidenceSalutations = null,
    ) {
        $this->confidenceSuffixes = $confidenceSuffixes;
        $this->confidenceSalutations = $confidenceSalutations;

        if ($parts !== null) {
            $this->setParts($parts);
        }
    }

    /**
     * the rendered string drops the comma structure and is not guaranteed to
     * re-parse to the same fields (e.g. a surname-plus-credential row); it is a
     * display form, not a round-trippable serialization
     */
    public function __toString(): string
    {
        return implode(' ', $this->getAll(true));
    }

    /**
     * set the parts this name consists of
     *
     * raw string parts are retained in getParts() but ignored by every getter
     * and by export(): the getters only ever read AbstractPart instances.
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
     * @param  list<string>|null  $confidenceTokens
     * @return $this
     */
    public function setSource(string $source, ?array $confidenceTokens = null): Name
    {
        $this->source = $source;
        $this->confidenceTokens = $confidenceTokens;

        return $this;
    }

    /**
     * the normalized input this name was parsed from, or null when none was
     * recorded (e.g. a manually constructed Name)
     */
    public function getSource(): ?string
    {
        return $this->source;
    }

    /**
     * advisory confidence signal for this parse, derived from the same input
     * the parser saw; falls back to the reconstructed name when no source was
     * recorded (e.g. a manually constructed Name). parse() is unaffected: this
     * is a read-only check the caller opts into.
     *
     * The reconstruction fallback sees normalized casing, so it generally
     * cannot flag uniform-case ambiguity; parse via Parser (which records the
     * source) when that signal matters.
     *
     * @return array{ambiguous: bool, notes: list<string>}
     */
    public function getConfidence(): array
    {
        return Confidence::assess(
            $this->source ?? $this->__toString(),
            $this->confidenceSuffixes,
            $this->confidenceSalutations,
            $this->confidenceTokens,
        );
    }

    /**
     * machine-readable view of every part with a stable key set: each key is
     * always present, empty string when the part is absent. Unlike getAll(),
     * which omits empties and varies its keys, this is safe to consume without
     * existence checks.
     *
     * Note the `lastname` value already contains any prefix; `lastname_prefix`
     * is a convenience extract, not a component to prepend to `lastname`.
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
            $value = $callable(...$args);
            if ($value !== '') {
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
     *
     * like __toString(), the rendered string drops comma structure and is not
     * guaranteed to re-parse to the same fields (e.g. a surname-plus-credential
     * row); it is a display form, not a round-trippable serialization
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
     * the honorific split into one entry per person addressed, for callers with
     * a single prefix field per contact: "Mr. and Mrs. Brad Smith" gives
     * ['Mr.', 'Mrs.']. Stacked titles for one person stay together
     * ("Rev. Dr John Doe" gives ['Rev. Dr.']), and a name with no honorific
     * gives an empty list, so read the first entry as `[0] ?? ''`. Joining the
     * entries with " and " reproduces getSalutation().
     *
     * @return list<string>
     */
    public function getSalutations(): array
    {
        return array_map(
            static function (array $group): string {
                $values = array_map(
                    static fn(Salutation $part): string => $part->normalize(),
                    $group,
                );

                return implode(' ', $values);
            },
            $this->getSalutationGroups(),
        );
    }

    /**
     * the second person a joint honorific addresses ("Mr. and Mrs. Brad Smith"
     * gives Mrs. Smith), or null when the honorific covers one person. The
     * partner shares the surname but has no given name of her own, since the
     * parsed first name belongs to the person actually named.
     *
     * A Name rather than a rendered string, so the caller decides between
     * "Mrs. Smith" and "Mrs. Brad Smith". Titles beyond the second addressee
     * are not reachable this way; a joint honorific in practice names two.
     */
    public function getPartner(): ?Name
    {
        $groups = $this->getSalutationGroups();

        // groups only split at a connector, so a second group is exactly the
        // condition isJoint() reports
        if (count($groups) < 2) {
            return null;
        }

        $parts = [];

        foreach ($groups[1] as $salutation) {
            $parts[] = clone $salutation;
        }

        // LastnamePrefix extends Lastname, so a particle surname comes across
        // whole and in order ("van der Berg")
        foreach ($this->parts as $part) {
            if ($part instanceof Lastname) {
                $parts[] = clone $part;
            }
        }

        return new Name($parts, $this->confidenceSuffixes, $this->confidenceSalutations);
    }

    /**
     * the salutation parts grouped at connector boundaries, one group per
     * person addressed. Parts that normalize to an empty string are dropped for
     * the same reason export() drops them, and a group left empty by that is
     * dropped with them.
     *
     * @return list<list<Salutation>>
     */
    private function getSalutationGroups(): array
    {
        $groups = [];
        $current = [];

        foreach ($this->parts as $part) {
            if (!$part instanceof Salutation) {
                continue;
            }

            if ($part instanceof SalutationConnector) {
                if ($current !== []) {
                    $groups[] = $current;
                    $current = [];
                }

                continue;
            }

            if ($part->normalize() !== '') {
                $current[] = $part;
            }
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * whether the honorific covers two people ("Mr. and Mrs. Brad Smith"). The
     * parsed given and family name belong to the person actually named; the
     * partner is implied by the title alone, so a caller importing households
     * should branch on this rather than treat the row as one individual.
     *
     * Only a title-anchored form is detected. A bare "Brad and Jane Smith"
     * carries no honorific to attach the connector to and reports false.
     */
    public function isJoint(): bool
    {
        return count($this->getSalutationGroups()) >= 2;
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
                $normalized = $part->normalize();
                // skip empty normalized values so a blank token cannot inject a
                // stray space into given_name / full_name joins
                if ($normalized !== '') {
                    $matched[] = $normalized;
                }
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
            if ($type === 'Lastname') {
                return $part instanceof Lastname && ! $part instanceof LastnamePrefix;
            }

            return $part::class === $className;
        }

        return $part instanceof $className;
    }
}
