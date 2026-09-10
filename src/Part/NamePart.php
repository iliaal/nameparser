<?php

namespace Iliaal\NameParser\Part;

abstract class NamePart extends AbstractPart
{
    #[\Override]
    public function normalize(): string
    {
        return $this->camelcase($this->getValue());
    }
}
