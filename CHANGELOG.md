# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Dutch and Spanish multi-particle surname prefixes (den, ten, los, las), so "Sanne van den Heuvel", "Corrie ten Boom", and "Juan de los Santos" keep the full surname instead of dropping the inner particle.

### Fixed

- Comma form "Last, First" no longer leaks a leading surname particle into the first name. "van der Berg, Johan" returns last name "van der Berg" and first name "Johan", not "Van Johan" / "der Berg". This affected every prefixed surname, including the single-particle "de Vries, Jan".
- A surname particle inside a compound given name now renders lowercase to match how a surname prefix is normalized. "Maria del Carmen Fernandez" returns middle name "del Carmen", not "Del Carmen".

## [1.1.0] - 2026-06-24

### Added

- `Name::toArray()` returns every part under a fixed key set (empty string when absent), a machine-readable shape that is safe to consume without existence checks, unlike `getAll()`.
- `Name::getConfidence()` exposes the advisory confidence signal on the parsed result, derived from the same input the parser saw. `Parser::parse()` output is unchanged; the check is opt-in.
- Confidence now flags all-caps tokens that collide with Census surnames (II, III, IV, MBA) in uniform-case input, in addition to the existing name-leaning keys.
- Two-letter given names in all-caps input are kept as names instead of being split into initials; "JO ANDERSON" keeps first name Jo. Mixed-case combined initials like "JM Walker" still split.
- Comma input keeps a middle name after a second comma; "Smith, John, Robert" keeps Robert, while trailing and credential-only segments like "Smith, MD, PhD" still strip to suffixes.

### Changed

- Config setters (`setMaxCombinedInitials`, `setMaxSalutationIndex`, `setNicknameDelimiters`) take effect on a reused parser even when called after the first `parse()`, instead of using configuration cached on that first call.
- `getFullName()` and `toArray()['full_name']` no longer pad with a stray space when the first or last name is absent; "John" alone returns "John", not "John ".

### Fixed

- A lone bracket or quote token no longer crashes `parse()` with a TypeError; inputs like "(" or "Smith, (" return an empty Name instead of aborting the row.
- Multi-word salutation matching no longer accepts a partial tail, so "Smith, Her" keeps Her as the given name instead of reading it as "Her Honour", and no longer reads past the token list when a match shrinks it.

## [1.0.0] - 2026-06-07

### Added

- Casing-aware credential matching: an ALL-CAPS token reads as a credential, title or lower case as a name, so surnames like Do, Vi, Ma, and Ba no longer parse as suffixes.
- Nursing and allied-health credentials from the NPI registry (RN, NP, PharmD, APRN, PA-C, OTR/L, and 30+ more); first/last accuracy on 30k real names rose from 92.8% to 95.3%.
- `Confidence::assess()` flags names whose credential-vs-name split is undecidable from casing, for manual review.
- Expanded base credential and salutation dictionary (DDS, DO, DVM, PsyD, LCSW, Hon., roman numerals VI to X), from the CodeByZach fork.

### Changed

- Namespace is `Iliaal\NameParser` (was `TheIconic\NameParser`).
- Requires PHP 8.3+ and `ext-mbstring`. Tested through PHP 8.5.
- Tooling: PHPUnit 12, PHPStan 2 (level 9), PHP-CS-Fixer, GitHub Actions.

### Fixed

- Unclosed nickname delimiter no longer swallows the surname or leaks a stray bracket; "John (Bob Smith" keeps last name Smith (via tobyberster/name-parser).
- Multibyte initials are no longer corrupted; accented tokens like "É Durand" survive instead of becoming replacement characters.
- Trailing comma-separated credentials are no longer dropped; "Smith, John, MD, PhD" keeps both.
- Empty nickname no longer renders as "()" in the string cast of a name.
- `setWhitespace()` now trims the configured characters from the edges of the input.
- `setMaxSalutationIndex()` larger than the token count no longer emits undefined-array-key warnings.
