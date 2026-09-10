<?php

namespace Iliaal\NameParser\Part;

use Iliaal\NameParser\Text;

abstract class AbstractPart
{
    protected string $value = '';

    private ?string $camelcaseCache = null;

    private ?string $camelcaseCacheWord = null;

    public function __construct(string|AbstractPart $value)
    {
        $this->setValue($value);
    }

    /**
     * set the value to wrap
     * (can take string or part instance)
     *
     * Parts are frozen after mapping: the mapper fixes the rendered form at
     * map time (pre-normalized parts keep their dictionary form, camelcased
     * parts memoize off the mapped value), so mutating a mapped part leaves
     * normalize() out of sync with getValue(). The method stays public only
     * for the released 1.x API (external callers mutate unmapped parts);
     * Name::getPartner() defensively clones instead of re-pointing values.
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

    public function getValue(): string
    {
        return $this->value;
    }

    public function normalize(): string
    {
        return $this->getValue();
    }

    protected function camelcase(string $word): string
    {
        if ($this->camelcaseCache !== null && $this->camelcaseCacheWord === $word) {
            return $this->camelcaseCache;
        }

        $this->camelcaseCacheWord = $word;

        $caseShape = preg_replace('/\p{M}/u', '', $word) ?? $word;
        $isMixedCase = strlen($caseShape) <= 1024
            && ! Text::isUpperCase($caseShape)
            && ! Text::isLowerCase($caseShape)
            && $this->hasInternalCaseTransition($caseShape);

        if ($isMixedCase) {
            return $this->camelcaseCache = $word;
        }

        // hostile long tokens: one title-case pass, no per-run callback
        if (strlen($word) > 256) {
            return $this->camelcaseCache = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }

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
     * @param  array<int, string>  $matches
     */
    protected function camelcaseReplace(array $matches): string
    {
        return mb_convert_case($matches[0], MB_CASE_TITLE, 'UTF-8');
    }
}
