<?php

namespace Iliaal\NameParser;

/**
 * Optional companion to LanguageInterface: a language that joins two titles
 * into one honorific with its own conjunction ("Herr und Frau Schmidt")
 * declares the connector tokens here. Languages without it fall back to the
 * English defaults ("and", "&").
 */
interface ConnectorsInterface
{
    /**
     * Array keys are registry lookup keys and must already be in normalized
     * form: lowercase, periods removed, no leading/trailing punctuation
     * (same transform as Text::key). Values are the rendered output form.
     *
     * @return array<string, string>
     */
    public function getConnectors(): array;
}
