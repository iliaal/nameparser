<?php

namespace Iliaal\NameParser\Part;

class Initial extends GivenNamePart
{
    /**
     * uppercase the initial
     */
    #[\Override]
    public function normalize(): string
    {
        return mb_strtoupper($this->getValue(), 'UTF-8');
    }
}
