<?php

namespace Iliaal\NameParser\Part;

/**
 * the single pre-normalized mechanism (np-cr-024): parts that carry a
 * dictionary form decided at map time, rather than deriving one via camelcase
 * at render time. PreNormalizedPart is the thin base-class facade over this
 * trait for the salutation/suffix line; LastnamePrefix and MiddlenamePrefix
 * use the trait directly only because their instanceof lineage (Lastname,
 * Middlename) forbids a second base class. New pre-normalized types must
 * reuse this trait, never a parallel implementation.
 *
 * The dictionary form is fixed at construction: an inherited setValue() call
 * changes only the raw value, and normalize() keeps rendering the original
 * form. That asymmetry is inherited from the released 1.x API; these parts
 * are treated as immutable after mapping (see AbstractPart::setValue()).
 *
 * @see PreNormalizedPart
 */
trait PreNormalized
{
    /**
     * the dictionary form to render, or the raw value when none was supplied
     */
    protected string $normalized;

    public function __construct(string $value, ?string $normalized = null)
    {
        $this->normalized = $normalized ?? $value;

        parent::__construct($value);
    }

    /**
     * return the supplied dictionary form, falling back to the raw value
     * verbatim when none was given
     */
    #[\Override]
    public function normalize(): string
    {
        return $this->normalized;
    }
}
