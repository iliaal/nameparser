<?php

namespace Iliaal\NameParser\Part;

class MiddlenamePrefix extends Middlename
{
    protected string $normalized;

    public function __construct(string $value, ?string $normalized = null)
    {
        $this->normalized = $normalized ?? $value;

        parent::__construct($value);
    }

    /**
     * a particle in a compound given name ("Maria del Carmen") renders in its
     * lowercase dictionary form, matching how LastnamePrefix normalizes a
     * surname particle, instead of being title-cased like a plain middle name
     */
    #[\Override]
    public function normalize(): string
    {
        return $this->normalized;
    }
}
