# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Fork of [theiconic/name-parser](https://github.com/theiconic/name-parser) v1.2.11,
incorporating the modernization work from
[codebyzach/name-parser](https://github.com/CodeByZach/name-parser), plus
casing- and credential-aware parsing on top.

### Added

- **Casing as a disambiguation signal** in `SuffixMapper`: tokens that collide
  with real names (`Do`, `Vi`, `Ma`, roman numerals, two-letter credentials) are
  read as a credential only when written ALL-CAPS; Title- or lower-case keeps
  them as name parts. Resolves false-positive suffix matches on names such as
  the Vietnamese surname "Do" and given name "Vi".
- **Terminal-token guard** in `SuffixMapper`: a lone name-colliding token in a
  comma given-name segment is never consumed as a credential when doing so would
  empty out the name (unless its casing reads as a credential).
- **`Confidence` assessor**: an advisory pass that flags inputs where a token
  matches a credential but the casing signal is uninformative (uniform-case
  input or a lowercase token), so callers can route the row to manual review
  instead of trusting a silently-chosen split.
- Expanded English suffix/salutation dictionary (healthcare and professional
  credentials: DDS, DO, DVM, PsyD, LCSW, MSW, MBA, EMBA, Esq, etc.; roman
  numerals VI–X; `Hon.`/`Honorable`), inherited from the CodeByZach fork.
- `ii`/`iii`/`iv`/`mba` added to the ambiguous-suffix set: US Census surnames
  (Ii, Iv, Mba) that the suffix dictionary otherwise stripped to an empty first
  name in comma form. Casing still strips the genuine credential.

### Fixed

- **Unclosed nickname delimiter no longer swallows the surname.** An opening
  `(`, `"`, `[`, etc. with no matching close now reverts the affected parts
  instead of mapping them as a nickname. `"John (Bob Smith"` keeps the last
  name `Smith`. Ported from
  [tobyberster/name-parser](https://github.com/tobyberster/name-parser).

### Changed

- Namespace is `Iliaal\NameParser` (was `TheIconic\NameParser`).
- Requires PHP 8.3+ and `ext-mbstring`. Tested through PHP 8.5.
- Tooling: PHPUnit 12, PHPStan 2 (level 9), PHP-CS-Fixer, GitHub Actions.
