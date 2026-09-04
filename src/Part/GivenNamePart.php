<?php

namespace Iliaal\NameParser\Part;

/**
 * marker for given-name parts (np-cr-024): shares NamePart::normalize()
 * exactly, and stays a distinct type so instanceof still separates given
 * names from surnames/nicknames. Collapse candidates must keep this class
 * importable; folding it into NamePart would widen hasGivenNameParts().
 */
abstract class GivenNamePart extends NamePart {}
