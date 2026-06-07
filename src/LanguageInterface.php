<?php

namespace Iliaal\NameParser;

interface LanguageInterface
{
    /**
     * @return array<string, string>
     */
    public function getSuffixes(): array;

    /**
     * @return array<string, string>
     */
    public function getLastnamePrefixes(): array;

    /**
     * @return array<string, string>
     */
    public function getSalutations(): array;
}
