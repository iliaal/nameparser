<?php

namespace Tests\Iliaal\NameParser;

/**
 * The lone-salutation lock shared by the comma-segment and
 * salutation-collision suites: a surname segment that is nothing but a
 * salutation stays a salutation (never promoted to a last name), and the
 * single-letter-plus-credential shape reads as an initial. One provider so
 * the two suites cannot drift apart.
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
            // 'O' beside a credential is an initial, not a surname: John keeps
            // no lastname here rather than gaining a one-letter surname, the
            // same reading the pipeline gives 'John A PhD'.
            'single letter beside credential is an initial' => ['John O MD', '', 'John', '', 'O', 'MD'],
        ];
    }
}
