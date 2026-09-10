<?php

namespace Tests\Iliaal\NameParser;

/**
 * Shared salutation and initial cases keep comma and collision suites consistent.
 */
trait LoneSalutationCases
{
    /**
     * @return array<string, array{string, string, string, string, string, string}>
     */
    public static function loneSalutationProvider(): array
    {
        // input, salutation, firstname, lastname, initials, suffix
        return [
            'lone salutation before given name' => ['Dr., John', 'Dr.', 'John', '', '', ''],
            // A single letter beside a credential is an initial even when no surname remains.
            'single letter beside credential is an initial' => ['John O MD', '', 'John', '', 'O', 'MD'],
        ];
    }
}
