<?php

namespace Iliaal\NameParser;

/**
 * classification of a comma-tail token for the credential scan
 * (np-cr-025: replaces the 0/1/2 magic ints)
 */
enum TokenCredentialClass: int
{
    /**
     * a name token: neither a dictionary credential nor an unknown-candidate
     */
    case Name = 0;

    /**
     * a dictionary suffix under the casing rule
     */
    case DictionaryCredential = 1;

    /**
     * an all-caps unknown-credential candidate (>= 2 letters, only when the
     * input is not uniform-uppercase)
     */
    case UnknownCandidate = 2;
}
