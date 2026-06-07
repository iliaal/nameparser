# iliaal/nameparser

Parse a string containing a full name into its parts (salutation, first name,
middle names, initials, last name with prefixes, suffix, nickname).

> **Fork lineage.** This is a fork of
> [theiconic/name-parser](https://github.com/theiconic/name-parser) (dormant
> since ~2020), built on top of the modernization done by
> [codebyzach/name-parser](https://github.com/CodeByZach/name-parser). It adds
> **casing- and credential-aware parsing** and a **confidence/ambiguity signal**,
> and targets PHP 8.3+.

## Why this fork

The upstream parser keys every token through `strtolower()` before matching it
against its salutation/suffix dictionaries, so it cannot tell an all-caps
credential from a same-spelled name. Two failure modes follow, both common in
professional and clinician name lists:

1. A trailing credential without a comma swallows the surname:
   `"Jane Doe DDS"` parsed to last name **"Dds"** (the real surname lost).
2. A short credential token that is also a real name is mis-stripped:
   the Vietnamese surname **"Do"** and given name **"Vi"** were consumed as the
   credentials DO / VI.

This fork fixes both and adds an advisory confidence pass for the genuinely
ambiguous cases.

### What changed

- **Casing as a signal.** An ambiguous token (`Do`, `Vi`, `Ma`, roman numerals,
  two-letter credentials) is treated as a credential only when written ALL-CAPS
  (`DO`, `VI`); Title- or lower-case keeps it as a name part. People write
  credentials in caps and names in title case, so the original casing carries
  the signal that lowercasing discarded.
- **Terminal-token guard.** A lone name-colliding token in a comma given-name
  segment is kept as a name rather than emptied into a credential, unless its
  casing reads as a credential.
- **Confidence assessor.** When a token matches a credential but the casing is
  uninformative (uniform-case input, or a lowercase token), `Confidence::assess()`
  flags the input so you can route it to manual review instead of trusting the
  split.
- **Expanded English dictionary** (inherited from the CodeByZach fork): DDS, DO,
  DVM, PsyD, LCSW, MSW, MBA, EMBA, Esq, roman numerals VI–X, `Hon.`, and more.

## Requirements

- PHP 8.3+ (tested through 8.5)
- `ext-mbstring`

## Installation

Not yet published to Packagist. Install from the Git repository:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/iliaal/nameparser" }
    ],
    "require": {
        "iliaal/nameparser": "^1.0"
    }
}
```

```bash
composer require iliaal/nameparser
```

## Usage

```php
use Iliaal\NameParser\Parser;

$parser = new Parser();
$name = $parser->parse('Dr. Jane A. Doe DDS');

$name->getSalutation();   // "Dr."
$name->getFirstname();    // "Jane"
$name->getInitials();     // "A."
$name->getLastname();     // "Doe"
$name->getSuffix();       // "DDS"
$name->getFullName();     // "Jane A. Doe"
```

The full getter surface (`getMiddlename()`, `getNickname()`, `getLastnamePrefix()`,
`getGivenName()`, `getAll()`) is unchanged from upstream.

### Confidence / ambiguity

For batch imports where a wrong split is a data-integrity problem, check whether
the input was decidable from its casing:

```php
use Iliaal\NameParser\Confidence;

$result = Confidence::assess('NGUYEN, VI');
// ['ambiguous' => true, 'notes' => ["'VI' could be a name or a credential; input casing is uniform"]]

if ($result['ambiguous']) {
    // queue the row for manual review instead of trusting the parse
}
```

A mixed-case input like `"Nguyen, Vi"` stays unflagged; the title-case `Vi`
resolves to the given name.

## Development

```bash
composer install
composer test     # phpunit
composer analyse  # phpstan (level 9)
composer lint     # php-cs-fixer (dry run)
```

## Credits

Original library by [The Iconic](https://github.com/theiconic). Modernization to
PHP 8.3+ by [Zachary Miller](https://github.com/CodeByZach). Casing/credential
parsing and confidence signal in this fork by Ilia Alshanetsky.

## License

MIT. See [LICENSE](LICENSE). Upstream copyright notices are retained.
