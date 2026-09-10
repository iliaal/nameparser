<?php

namespace Iliaal\NameParser;

enum TokenCredentialClass: int
{
    case Name = 0;

    case DictionaryCredential = 1;

    /**
     * an all-caps unknown-credential candidate (>= 2 letters, only when the
     * input is not uniform-uppercase)
     */
    case UnknownCandidate = 2;
}
