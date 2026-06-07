<?php

namespace Iliaal\NameParser\Part;

class Nickname extends AbstractPart
{
    /**
     * camelcase the nickname for normalization
     */
    #[\Override]
    public function normalize(): string
    {
        return $this->camelcase($this->getValue());
    }
}
