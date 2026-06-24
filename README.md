# iliaal/nameparser

[![CI](https://github.com/iliaal/nameparser/actions/workflows/ci.yml/badge.svg)](https://github.com/iliaal/nameparser/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/iliaal/nameparser)](https://packagist.org/packages/iliaal/nameparser)
[![PHP Version](https://img.shields.io/packagist/php-v/iliaal/nameparser)](https://packagist.org/packages/iliaal/nameparser)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![Follow @iliaa](https://img.shields.io/badge/Follow-@iliaa-000000?style=flat&logo=x&logoColor=white)](https://x.com/intent/follow?screen_name=iliaa)

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
  DVM, PsyD, LCSW, MSW, MBA, EMBA, Esq, roman numerals VI to X, `Hon.`, and more.
- **Nursing and allied-health credentials.** RN, NP, PharmD, APRN, PA-C, OTR/L,
  and 30+ more, mined by frequency from the NPI registry, so a trailing
  credential no longer leaks into the first name.
- **Unclosed nickname delimiter.** An opening `(` or quote with no matching
  close no longer swallows the surname (`"John (Bob Smith"` keeps `Smith`).
- **All-caps short names.** Under uniform-uppercase input the caps cannot mark a
  token as initials, so a two-letter given name is kept as a name instead of
  being split (`"JO ANDERSON"` keeps `Jo`, not `J` + initial `O`). Mixed-case
  combined initials still split (`"JM Walker"` to `J` `M` Walker).
- **Comma middle names.** Everything after the first comma is the given-name
  segment, so a comma-separated middle name is retained (`"Smith, John, Robert"`
  keeps `Robert`) while trailing credentials are still stripped.

## Requirements

- PHP 8.3+ (tested through 8.5)
- `ext-mbstring`

## Installation

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

### Structured output

`toArray()` returns every part under a fixed key set, with an empty string for
any part that is absent. Unlike `getAll()`, which omits empty parts and varies
its keys, this shape is safe to consume without existence checks:

```php
$parser->parse('Dr. Jane A. Doe DDS')->toArray();
// [
//   'salutation' => 'Dr.', 'firstname' => 'Jane', 'initials' => 'A.',
//   'middlename' => '', 'lastname_prefix' => '', 'lastname' => 'Doe',
//   'suffix' => 'DDS', 'nickname' => '', 'given_name' => 'Jane A. Doe',
//   'full_name' => 'Jane A. Doe',
// ]
```

### Confidence / ambiguity

For batch imports where a wrong split is a data-integrity problem, check whether
the input was decidable from its casing. The signal is available two ways: as a
standalone pre-check on a raw string, or on the parsed result itself.

```php
use Iliaal\NameParser\Confidence;

// pre-check, before parsing
$result = Confidence::assess('NGUYEN, VI');
// ['ambiguous' => true, 'notes' => ["'VI' could be a name or a credential; input casing is uniform"]]

// or read it off the parse; same signal, derived from the same input
$result = $parser->parse('NGUYEN, VI')->getConfidence();

if ($result['ambiguous']) {
    // queue the row for manual review instead of trusting the parse
}
```

`getConfidence()` is read-only and does not change what `parse()` returns; it is
an advisory pass you opt into. A mixed-case input like `"Nguyen, Vi"` stays
unflagged; the title-case `Vi` resolves to the given name.

> **All-caps limitation.** Disambiguation keys off casing, so uniform-case input
> (all-caps legacy and registry data, or all-lowercase) carries no signal: an
> ambiguous trailing token reads as a credential by default. The confidence pass
> flags these when the token plausibly collides with a real name (`Do`, `Vi`,
> `Ma`, roman numerals, `MBA`), so you can route them to review. Clean
> credentials that are not also names (`RN`, `PT`, `OD`) are left unflagged to
> keep review volume manageable on all-caps datasets.

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
