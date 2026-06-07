<?php

namespace Iliaal\NameParser\Part;

abstract class AbstractPart
{
    /**
     * the wrapped value
     */
    protected string $value = '';

    /**
     * constructor allows passing the value to wrap
     */
    public function __construct(string|AbstractPart $value)
    {
        $this->setValue($value);
    }

    /**
     * set the value to wrap
     * (can take string or part instance)
     */
    public function setValue(string|AbstractPart $value): static
    {
        if ($value instanceof AbstractPart) {
            $value = $value->getValue();
        }

        $this->value = $value;

        return $this;
    }

    /**
     * get the wrapped value
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * get the normalized value
     */
    public function normalize(): string
    {
        return $this->getValue();
    }

    /**
     * helper for camelization of values
     * to be used during normalize
     */
    protected function camelcase(string $word): string
    {
        if (preg_match('/\p{L}(\p{Lu}*\p{Ll}\p{Ll}*\p{Lu}|\p{Ll}*\p{Lu}\p{Lu}*\p{Ll})\p{L}*/u', $word)) {
            return $word;
        }

        // preg_replace_callback returns null on regex error; fall back to the input.
        return preg_replace_callback('/[\p{L}0-9]+/ui', $this->camelcaseReplace(...), $word) ?? $word;
    }

    /**
     * camelcasing callback
     *
     * @param  array<int, string>  $matches
     */
    protected function camelcaseReplace(array $matches): string
    {
        return mb_convert_case($matches[0], MB_CASE_TITLE, 'UTF-8');
    }
}
