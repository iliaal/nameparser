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
  (`DO`, `VI`); Title- or lower-case keeps it as a name part, and normal name
  casing turns lowercase `vi` into `Vi` in the output. People write credentials
  in caps and names in title case, so the original casing carries the signal
  that lowercasing discarded.
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
- **Particles short enough to read as initials.** A surname particle of one or
  two letters was claimed as an initial before the surname mapper could bind it,
  dropping it from the name: Irish `"Éamon Ó Cuív"` returned surname `Cuív`, and
  a capitalised continental particle (`"Jean DE Vries"`, `"Mary LE Blanc"`) split
  into initials `D E` and `L E`. Both keep the particle now. Irish `Ó`, `Ní`,
  `Nic`, `Uí`, `Ua`, and `Mhic` are in the default dictionary and render
  capitalised.

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

Beyond the example above, `Name` also exposes `getMiddlename()`, `getNickname()`,
`getLastnamePrefix()`, `getGivenName()`, `getAll()`, `toArray()`,
`getSalutations()`, `isJoint()`, `getPartner()`, `getConfidence()`, and `getSource()`. `getLastname(true)` returns the surname
without any particle prefix; the default `getLastname()` already includes
prefixes.

### Structured output

`toArray()` returns every part under a fixed key set, with an empty string for
any part that is absent. Unlike `getAll()`, which omits empty parts and varies
its keys, this shape is safe to consume without existence checks:

```php
$parser->parse('Dr. Jane A. Doe DDS')->toArray();
// [
//   'salutation' => 'Dr.', 'firstname' => 'Jane', 'initials' => 'A.',
//   'middlename' => '', 'lastname_prefix' => '', 'lastname' => 'Doe',
//   'suffix' => 'DDS', 'nickname' => '', 'given_name' => 'Jane A.',
//   'full_name' => 'Jane A. Doe',
// ]
```

Note that `lastname` already includes any particle prefix (`de la Torre`);
`lastname_prefix` is a convenience extract, not a component to prepend.

### Joint names

An honorific can cover two people. The parser still returns one `Name`, and the
given and family name belong to the person actually named, so `isJoint()` tells
you when the row implies a second contact:

```php
$name = $parser->parse('Mr. and Mrs. Brad Smith');

$name->isJoint();         // true
$name->getSalutation();   // "Mr. and Mrs."
$name->getSalutations();  // ['Mr.', 'Mrs.']
$name->getFirstname();    // "Brad"
$name->getLastname();     // "Smith"
```

`getSalutation()` renders the honorific the input carried. `getSalutations()`
splits it one entry per person, for a contact record that holds a single prefix:

```php
$salutations  = $name->getSalutations();
$prefix       = $salutations[0] ?? '';                                    // "Mr."
$partnerTitle = $salutations[1] ?? '';                                    // "Mrs."
$partner      = $partnerTitle === '' ? '' : $partnerTitle . ' ' . $name->getLastname();
```

The partner shares the surname, not the given name. Stacked titles address one
person and stay in one entry (`Rev. Dr John Doe` gives `['Rev. Dr.']`), and a
name with no honorific gives an empty list. `Mr. & Mrs.` normalizes to the same
value as `Mr. and Mrs.`.

`getPartner()` hands back that second person as a `Name` instead, so you can read
the parts you need rather than assembling them:

```php
$partner = $name->getPartner();     // Name, or null when isJoint() is false

$partner->getSalutation();          // "Mrs."
$partner->getLastname();            // "Smith"
$partner->getFirstname();           // "", Brad's given name is not hers
(string) $partner;                  // "Mrs. Smith"
```

A particle surname crosses over whole (`Mr. and Mrs. van der Berg` gives a
partner with `van der Berg`), while the given name, initials and any credential
stay with the person actually named.

Only the title-anchored form is detected. A bare `Brad and Jane Smith` has no
honorific for the connector to attach to and reports `isJoint() === false`.
Two people each given a name is the other undetected form:

```php
$name = $parser->parse('Mr. Andrew and Mrs Sally Smith');
$name->toArray();
// salutation "Mr.", firstname "Andrew", middlename "Sally", lastname "Smith"
```

The conjunction and the second title are kept out of every getter rather than
title-cased into a name, so `getMiddlename()` gives `Sally` and not
`And Mrs Sally`. The parser does not decide that Sally is a second person, so her
given name stays where it lands. Both tokens remain visible as `Ignored` parts in
`getParts()` if you want to recover the structure yourself:

```php
use Iliaal\NameParser\Part\Ignored;

$household = array_values(array_filter(
    $name->getParts(),
    static fn($part): bool => $part instanceof Ignored,
));
```

### Confidence / ambiguity

For batch imports where a wrong split is a data-integrity problem, check whether
the input was decidable from its casing. The signal is available two ways: as a
standalone pre-check on a raw string, or on the parsed result itself.

```php
use Iliaal\NameParser\Confidence;

// pre-check, before parsing (default English ambiguous-key set)
$result = Confidence::assess('NGUYEN, VI');
// ['ambiguous' => true, 'notes' => ["'VI' could be a name or a credential; input casing is uniform"]]

// or read it off the parse; uses the parser's tokens and dictionaries
$result = $parser->parse('NGUYEN, VI')->getConfidence();

if ($result['ambiguous']) {
    // queue the row for manual review instead of trusting the parse
}
```

`getConfidence()` is read-only and does not change what `parse()` returns; it is
an advisory pass you opt into. A mixed-case input like `"Nguyen, Vi"` stays
unflagged; the title-case `Vi` resolves to the given name.

For a non-default language set, standalone `Confidence::assess($string)` still
uses the English salutation scope and the full ambiguous-suffix table.
`Name::getConfidence()` uses the parser's configured suffixes, salutations, and
token boundaries. This includes custom whitespace rules. Prefer that method
when you need confidence for an actual parse. Standalone callers can scope the
dictionaries with `Confidence::assess($string, $parser->getSuffixes(),
$parser->getSalutations())`; standalone tokenization still splits on whitespace
and commas.

> **Uniform-case limitation.** Disambiguation keys off casing, so both all-caps
> legacy data and all-lowercase input are ambiguous to the confidence pass. The
> parser treats an ambiguous ALL-CAPS trailing token as a credential, but keeps
> the lowercase form as a name part and normalizes its casing. Confidence flags
> either form when the token is name-leaning (`Do`, `Vi`, `Ma`, `Ba`, `Lac`) or a
> Census surname collision (`II`, `III`, `IV`, `MBA`). Clean credentials that are
> not also names (`RN`, `PT`, `OD`, and other roman numerals such as `VII`) are
> left unflagged to keep review volume manageable on all-caps datasets.

### Languages

`new Parser()` uses the English dictionary. Passing languages **replaces** that
list entirely (salutations, suffixes, and surname particles), it does not merge
onto English. `new Parser([new German()])` gives German honorifics and ordinals
only, not English professional credentials or English particles such as `van`.

Compose dictionaries when you need both:

```php
use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Language\German;

$parser = new Parser([new English(), new German()]);
```

Dictionary keys merge in constructor order, and the first language wins on
collisions. With English first, `Fr.` resolves to `Fr.`. With German first, it
resolves to `Frau`.

### Configuration

Fluent setters on `Parser`:

- `setSurnameFirst(true)` reads comma-less space-separated names in CJK order
  (`Mao Zedong` → last `Mao`). Opt-in; romanized order cannot be auto-detected.
- `setNicknameDelimiters(['<<' => '>>'])` **replaces** the default pairs
  (`()[]{}<>` and quotes). An empty array restores the defaults; it does not
  disable nicknames. At most 32 valid pairs are used; opener and closer strings
  longer than 64 bytes are ignored.
- `setWhitespace` and `setMaxSalutationIndex` tune collapse and mapper gates.
- `setMaxCombinedInitials($limit)` accepts 0 through 64. Values outside that
  range throw `InvalidArgumentException`; combined-token expansion is also
  capped at 131,072 expanded initial parts per parse and throws
  `LengthException` above that expansion ceiling.
- `setMappers([...])` replaces the single-segment (Western, no-comma) pipeline
  only. Comma forms and `setSurnameFirst(true)` use dedicated sub-parsers that
  always build their own mapper lists from the language dictionaries. Pass `[]`
  to restore the default pipeline.

### Parsing limits

`parse()` accepts at most 1,048,576 input bytes and 65,536 non-empty tokens.
It throws `LengthException` before comma segmentation or mapper allocation when
either limit is exceeded. These bounds keep malformed import rows from
exhausting a PHP worker while retaining batch-scale inputs.

Standalone `Confidence::assess($string)` applies the same byte and token limits.
`Name::getConfidence()` reuses the tokens from the already validated parse
instead of tokenizing the source again.

Some inputs have no structural signal. A comma followed only by credentials can
mean full name plus credentials (`Jane Doe, MD`) or surname plus credentials
(`Hidalgo Castillo, MD`). The parser keeps the left side in Western order in
that case. Use an explicit given-name segment, for example
`Hidalgo Castillo, Maria, MD`, or post-process feeds where the left side is a
surname-only field.

An anglicised Irish surname with the fada dropped is undecidable the same way.
`Eamon O Cuiv` and `John F Kennedy` have identical structure, and casing offers
no tie-break since both are capital letters, so a bare `O` between spaces stays a
middle initial. The fada form `Ó` resolves as a particle, and the joined
apostrophe form (`O'Cuiv`) is a single token that never needed one.

Two-token surnames without particles are also ambiguous in space-separated names.
`Jennifer Chen Wu` and `Mary Jo Li` share the same token structure, but one wants
`Chen Wu` as a surname while the other wants `Jo` as a middle name. The parser
keeps the existing compound-surname heuristic for two-character terminal
surnames.

Unknown trailing credentials follow the same casing rule as the ambiguous
tokens. When a known credential anchors the tail and the input is mixed-case, an
adjacent unknown all-caps token is kept as a credential too: `John Smith MD FACS`
and `Smith, John, MD, FACS` keep both in the suffix. A pure all-caps segment
with no prior dictionary anchor is kept as a name (`Smith, JOHN, MD` → first
`John`, suffix `MD`), because it is indistinguishable from an all-caps given
name. Prefer the known credential first when the unknown stands alone
(`Smith, MD, FACS`). Uniform all-caps rows cannot recover unknown credentials;
with no case signal an unknown token could equally be a surname, so it stays in
the name.

`getFullName()` and `toArray()['full_name']` are the given name plus surname only
(no salutation, nickname, or suffix). `__toString()` is the richer display line
from `getAll(true)` (salutation through suffix, nickname wrapped). Both drop
comma structure and are not guaranteed to re-parse to the same fields, so treat
them as output, not as a round-trippable serialization.

### Performance

Reuse one `Parser` across a batch rather than constructing a new one per row.
The parser memoizes its merged dictionaries, its mapper pipeline, and the
comma-segment sub-parsers on first use, so a shared instance amortizes that setup
across every `parse()` call.

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
