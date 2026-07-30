<?php

namespace Iliaal\NameParser\Part;

abstract class AbstractPart
{
    /**
     * the wrapped value
     */
    protected string $value = '';

    /**
     * memoized camelcase result, keyed by the word it was computed for; parts
     * are effectively immutable after mapping, so this is computed at most once
     * per value and cleared whenever the value changes
     */
    private ?string $camelcaseCache = null;

    private ?string $camelcaseCacheWord = null;

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
        $this->camelcaseCache = null;
        $this->camelcaseCacheWord = null;

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
        if ($this->camelcaseCache !== null && $this->camelcaseCacheWord === $word) {
            return $this->camelcaseCache;
        }

        $this->camelcaseCacheWord = $word;

        $caseShape = preg_replace('/\p{M}/u', '', $word) ?? $word;
        $isMixedCase = strlen($caseShape) <= 1024
            && $caseShape !== mb_strtoupper($caseShape, 'UTF-8')
            && $caseShape !== mb_strtolower($caseShape, 'UTF-8')
            && $this->hasInternalCaseTransition($caseShape);

        if ($isMixedCase) {
            return $this->camelcaseCache = $word;
        }

        // hostile long tokens: one title-case pass, no per-run callback
        if (strlen($word) > 256) {
            return $this->camelcaseCache = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }

        // preg_replace_callback returns null on regex error; fall back to the input.
        return $this->camelcaseCache = preg_replace_callback('/[\p{L}\p{M}0-9]+/ui', $this->camelcaseReplace(...), $word) ?? $word;
    }

    private function hasInternalCaseTransition(string $word): bool
    {
        $matches = [];
        if (preg_match_all('/\p{L}+/u', $word, $matches) === false) {
            return false;
        }

        foreach ($matches[0] as $run) {
            $caseRuns = [];
            if (preg_match_all('/\p{Lu}+|\p{Ll}+|\p{L}/u', $run, $caseRuns) === false) {
                continue;
            }

            $consumed = 0;
            $previousCase = null;

            foreach ($caseRuns[0] as $caseRun) {
                $upper = mb_strtoupper($caseRun, 'UTF-8') === $caseRun
                    && mb_strtolower($caseRun, 'UTF-8') !== $caseRun;
                $lower = mb_strtolower($caseRun, 'UTF-8') === $caseRun
                    && mb_strtoupper($caseRun, 'UTF-8') !== $caseRun;
                $currentCase = $upper ? true : ($lower ? false : null);

                if (
                    $consumed >= 2
                    && $previousCase !== null
                    && $currentCase !== null
                    && $previousCase !== $currentCase
                ) {
                    return true;
                }

                $consumed += mb_strlen($caseRun, 'UTF-8');
                $previousCase = $currentCase;
            }
        }

        return false;
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
