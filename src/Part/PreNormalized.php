<?php

namespace Iliaal\NameParser\Part;

/**
 * Prefixes use this trait to preserve their Lastname/Middlename inheritance.
 * The rendered form is fixed at construction; the legacy setValue() API
 * changes only the raw value (see AbstractPart::setValue()).
 *
 * @see PreNormalizedPart
 */
trait PreNormalized
{
    protected string $normalized;

    public function __construct(string $value, ?string $normalized = null)
    {
        $this->normalized = $normalized ?? $value;

        parent::__construct($value);
    }

    #[\Override]
    public function normalize(): string
    {
        return $this->normalized;
    }
}
