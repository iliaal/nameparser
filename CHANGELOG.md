# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
