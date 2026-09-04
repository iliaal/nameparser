<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\GivenNamePart;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\LastnamePrefix;
use Iliaal\NameParser\Part\Middlename;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\SalutationConnector;
use Iliaal\NameParser\Part\Suffix;

class Name
{
    private const string PARTS_NAMESPACE = 'Iliaal\NameParser\Part';

    /**
     * Short export/isType names to part classes. Dispatching on ::class keeps
     * renames and typos visible to static analysis; unknown strings still fall
     * back to the namespace lookup so custom part types keep working.
     *
     * @var array<string, class-string<AbstractPart>>
     */
    private const array TYPE_MAP = [
        'Firstname' => Firstname::class,
        'GivenNamePart' => GivenNamePart::class,
        'Initial' => Initial::class,
        'Lastname' => Lastname::class,
        'LastnamePrefix' => LastnamePrefix::class,
        'Middlename' => Middlename::class,
        'Nickname' => Nickname::class,
        'Salutation' => Salutation::class,
        'Suffix' => Suffix::class,
    ];

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
     * Parser nickname-delimiter configuration, retained so getConfidence()
     * decorates exactly like the parse did instead of defaulting.
     *
     * @var array<string, string>|null
     */
    protected ?array $confidenceNicknameDelimiters = null;

    /**
     * Parser whitespace configuration, retained so getConfidence() splits
     * exactly like the parse did instead of defaulting.
     */
    protected ?string $confidenceWhitespace = null;

    /**
     * constructor takes the array of parts this name consists of
     *
     * raw string parts are retained in getParts() but ignored by every getter
     * and by export(): the getters only ever read AbstractPart instances.
     *
     * The trailing confidence parameters are all optional and additive: older
     * call sites constructing with three or fewer arguments are unaffected.
     *
     * @param  array<int, AbstractPart|string>|null  $parts
     * @param  array<int|string, string>|null  $confidenceSuffixes
     * @param  array<int|string, string>|null  $confidenceSalutations
     * @param  array<string, string>|null  $confidenceNicknameDelimiters
     */
    public function __construct(
        ?array $parts = null,
        ?array $confidenceSuffixes = null,
        ?array $confidenceSalutations = null,
        ?array $confidenceNicknameDelimiters = null,
        ?string $confidenceWhitespace = null,
    ) {
        $this->confidenceSuffixes = $confidenceSuffixes;
        $this->confidenceSalutations = $confidenceSalutations;
        $this->confidenceNicknameDelimiters = $confidenceNicknameDelimiters;
        $this->confidenceWhitespace = $confidenceWhitespace;

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
     * record the parser nickname-delimiter configuration, so getConfidence()
     * decorates exactly like the parse did
     *
     * @param  array<string, string>|null  $delimiters
     * @return $this
     */
    public function setConfidenceNicknameDelimiters(?array $delimiters): Name
    {
        $this->confidenceNicknameDelimiters = $delimiters;

        return $this;
    }

    /**
     * the stored parser nickname-delimiter configuration, or null when none
     * was recorded (getConfidence() then uses defaults)
     *
     * @return array<string, string>|null
     */
    public function getConfidenceNicknameDelimiters(): ?array
    {
        return $this->confidenceNicknameDelimiters;
    }

    /**
     * record the parser whitespace configuration, so getConfidence() splits
     * exactly like the parse did
     *
     * @return $this
     */
    public function setConfidenceWhitespace(?string $whitespace): Name
    {
        $this->confidenceWhitespace = $whitespace;

        return $this;
    }

    /**
     * the stored parser whitespace configuration, or null when none was
     * recorded (getConfidence() then uses defaults)
     */
    public function getConfidenceWhitespace(): ?string
    {
        return $this->confidenceWhitespace;
    }

    /**
     * advisory confidence signal for this parse, derived from the same input
     * the parser saw and the same nickname-delimiter/whitespace configuration;
     * falls back to the reconstructed name with default configuration when
     * nothing was recorded (e.g. a manually constructed Name). parse() is
     * unaffected: this is a read-only check the caller opts into.
     *
     * The reconstruction fallback sees normalized casing, so it generally
     * cannot flag uniform-case ambiguity; parse via Parser (which records the
     * source and configuration) when that signal matters.
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
            $this->confidenceNicknameDelimiters,
            $this->confidenceWhitespace,
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
        // static key => first-class-callable map: renames and typos fail at
        // analysis time instead of at runtime via dynamic method strings
        $getters = [
            'salutation' => $this->getSalutation(...),
            'firstname' => $this->getFirstname(...),
            'nickname' => fn(): string => $this->getNickname($format),
            'middlename' => $this->getMiddlename(...),
            'initials' => $this->getInitials(...),
            'lastname' => $this->getLastname(...),
            'suffix' => $this->getSuffix(...),
        ];

        $results = [];

        foreach ($getters as $key => $getter) {
            $value = $getter();
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
     * entries with " and " reproduces getSalutation() only under the default
     * English connectors: a custom ConnectorsInterface language renders its
     * own join word ("Herr und Frau"), so join with the configured rendering
     * there instead of a hardcoded " and ".
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

        $partner = new Name(
            $parts,
            $this->confidenceSuffixes,
            $this->confidenceSalutations,
            $this->confidenceNicknameDelimiters,
            $this->confidenceWhitespace,
        );

        // the partner derives from the same parse, so it shares the
        // source-backed confidence input; guarded for manually constructed
        // Names (no source or tokens recorded), which keep the reconstruction
        // fallback documented on getConfidence()
        if ($this->source !== null || $this->confidenceTokens !== null) {
            $partner->setSource($this->source ?? (string) $this, $this->confidenceTokens);
        }

        return $partner;
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
        $className = self::TYPE_MAP[$type] ?? self::PARTS_NAMESPACE . '\\' . $type;

        if ($strict) {
            if ($type === 'Lastname') {
                return $part instanceof Lastname && ! $part instanceof LastnamePrefix;
            }

            return $part::class === $className;
        }

        return $part instanceof $className;
    }
}
