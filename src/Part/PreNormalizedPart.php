<?php

namespace Iliaal\NameParser\Part;

/**
 * thin base-class facade over the PreNormalized trait (np-cr-024), the
 * single pre-normalized mechanism. Salutation and Suffix line up here;
 * particle prefixes (LastnamePrefix, MiddlenamePrefix) use the trait
 * directly to keep their name-part instanceof lineage.
 *
 * @see PreNormalized
 */
abstract class PreNormalizedPart extends AbstractPart
{
    use PreNormalized;
}
