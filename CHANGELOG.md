# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Joint salutations parse as one honorific, so `Mr. and Mrs. Brad Smith` keeps `Brad` as the first name instead of reading the connector as one (`Mr. & Mrs.` normalizes to the same value). Any pairing works (`Dr. and Mrs.`, `Ms. & Ms.`), and the connector needs a title on both sides, so `Mr. and Brad Smith` is left alone. Fixes #4.
- `Name::isJoint()` reports a name whose honorific covers two people, for callers importing households. The parsed given and family name belong to the person actually named. Only the title-anchored form is detected: a bare `Brad and Jane Smith` has no honorific to anchor the connector and reports `false`.
- `Name::getSalutations()` returns the honorific split one entry per person addressed, so a contact record with a single prefix field can take the first: `Mr. and Mrs. Brad Smith` gives `['Mr.', 'Mrs.']`, and the partner is that second title plus `getLastname()`. Stacked titles for one person stay in one entry (`Rev. Dr John Doe` gives `['Rev. Dr.']`), and a name with no honorific gives an empty list.
- `Name::getPartner()` returns the second person a joint honorific addresses as a `Name` of her own, so a household import can build the partner contact without assembling strings: `Mr. and Mrs. Brad Smith` gives a partner with salutation `Mrs.` and lastname `Smith`. A particle surname crosses over whole (`van der Berg`); the given name, initials and any credential stay with the person actually named. Returns `null` when the honorific covers one person.
- Irish surname particles `Ó`, `Ní`, `Nic`, `Uí`, `Ua`, and `Mhic`, so `Éamon Ó Cuív` keeps `Ó Cuív` as the surname instead of reading `Ó` as a middle initial. They render capitalised, unlike the continental particles, and the fada survives uniform-caps input (`ÉAMON Ó CUÍV` → `Ó Cuív`). Only the fada-bearing `Ó` counts: anglicised `Eamon O Cuiv` is indistinguishable from a middle initial (`John F Kennedy`), so bare `O` stays an initial.
- English honorifics `Dame`, `Lady`, `Lord`, `Pastor`, `Professor`, `Reverend`, and `Rt Hon` (`Lord Ashcroft` → salutation `Lord`, surname `Ashcroft`). `Rt Hon` also matches its abbreviated and article-led forms (`Rt. Hon. Boris Johnson`, `The Rt Hon Boris Johnson`).

### Changed

- `Confidence::assess()` flags a two-token input led by an honorific that is also a real name (`Lord Ashcroft`, `Pastor Gonzalez`, `Hon Chan`), where the title reading leaves no given name and nothing in the input decides between the two. A comma resolves it structurally (`Lord, Jack`) and a third token leaves a given name either way (`Lady Diana Spencer`), so neither is flagged.
- `parse()` and standalone `Confidence::assess()` now reject inputs over 1,048,576 bytes or 65,536 non-empty tokens with `LengthException` before structural allocation.
- `setMaxCombinedInitials()` now accepts only 0 through 64 and combined-token expansion is capped at 131,072 output parts per parse, preventing hostile configuration from creating an unbounded part list. Nickname delimiter configuration is similarly bounded to 32 pairs of at most 64 bytes each.
- Large runs of combined initials, repeated salutations, and surname-first honorifics now parse in linear time instead of repeatedly reindexing the token array.
- Comma-heavy malformed input no longer retains duplicate segment projections, cutting peak working memory for a 1 MB row from hundreds of megabytes to a linear bound.
- Maximum-size interior tokens no longer materialize an array containing every grapheme, so a valid 1 MB row stays within a 32 MB PHP memory limit.
- Camelcase detection remains linear without PCRE JIT at the 1,024-byte boundary, and maximum-size common-prefix nickname-delimiter configurations no longer multiply the structural-comma scan.
- A pure all-caps unknown-candidate segment with no prior dictionary anchor is kept as a name rather than promoted to a suffix when a later credential appears (`Smith, JOHN, MD` → first `John`, suffix `MD`). Unknown candidates still ride after a known credential (`MD, FACS`) and peel from a mixed segment onto a later dictionary segment (`John FACS, MD`). Prefer `Smith, MD, FACS` when the unknown stands alone before the known credential.
- `getConfidence()` now uses the parser's configured suffixes, salutations, and token boundaries. Standalone `Confidence::assess($string)` retains English defaults.

### Fixed

- A conjunction the honorific could not absorb is no longer title-cased into a name part, so `Andrew and Sally Smith` reports the middle name `Sally` instead of `And Sally`, and `Mr. and Brad Smith` reports the first name `Brad` instead of `And`. A title directly after such a conjunction addresses a second person, so it is dropped from the getters too (`Mr. Andrew and Mrs Sally Smith` no longer carries `Mrs` as a middle name). Both are marked as `Part\Ignored`, which no getter exports, so the text is still reachable through `Name::getParts()`. The parser still does not identify the second person: `isJoint()` and `getPartner()` need a title on both sides of the conjunction, and the second given name stays where it lands.
- Every word of an unattributed multi-word title is ignored after a conjunction, so `Mr. Andrew and His Honour Sally Smith` keeps only `Sally` as the middle name.
- A leading credential in a comma given-name segment no longer authorizes a later uppercase given name as an unknown credential (`Smith, MD John PAUL` keeps middle name `Paul` and suffix `MD`).
- `Name::isJoint()` now requires two non-empty salutation groups rather than reporting true for a manually constructed orphan connector.
- Invalid-UTF-8 confidence input now enforces the same 65,536-token ceiling before returning its ambiguity note.
- A two-letter surname particle written in caps inside mixed-case input is no longer shredded into initials, so `Jean DE Vries` keeps `de Vries` and `Mary LE Blanc` keeps `le Blanc` instead of reporting initials `D E` and `L E` with the particle dropped. Three-letter particles were never affected (`VON` exceeds the combined-initial limit).
- Decomposed Unicode accents stay attached to their base letter during casing, initial splitting, and short-surname detection.
- Credentials before a trailing nickname are retained in both Western and comma forms.
- German-only and other custom language dictionaries no longer throw when an English ambiguous suffix key is absent.
- Comma-separated credential tails now drop punctuation and `Unknown` placeholders under the same rules as space-separated tails.
- Nickname delimiters ignored by the 32-pair and 64-byte limits no longer shield structural commas.
- Every stock mapper now accepts sparse integer-keyed input under its public array contract.
- Custom `SuffixMapper` subclasses can override the protected uppercase check for ambiguous credentials again.
- Canonical mixed-case credentials such as `LAc` and `L.Ac.` are recognized without making the title-case name `Lac` ambiguous.
- Comma parsing once again dispatches through the protected `parseSplitName()` extension hook.
- Caller-owned mapper objects retain their dictionaries and state after parser configuration changes, and sparse custom mapper output is normalized before the next mapper runs.
- Invalid UTF-8 is reported as ambiguous by `Confidence::assess()`, and repeated ambiguity reasons are emitted once.
- Pure lastname export includes consumer subclasses of `Lastname` without folding `LastnamePrefix` into the value.
- Stacked and joint salutations use the same named-person boundary in comma and surname-first forms; nickname- or credential-only tails do not satisfy that boundary.
- A salutation that is also a real name no longer swallows the comma form's surname segment, so `Lord, Jack` keeps `Lord` as the surname (previously it became a salutation and the surname was dropped entirely). This also covers the pre-existing `Master, John` and `Hon, John`. An unambiguous title is unchanged: `Dr., John` still reads as a salutation.
- A dictionary salutation that follows a real name token stays part of the name rather than being promoted and dropped from the getters, so `John Lord Smith Jr` keeps `Lord` as the middle name and `Blair, Kathleen MASTER OF SOCIAL WOR` no longer reports a `Mr.` salutation. Only a leading article may sit before an honorific (`The Rev. Mark Williams`); an explicit `setMaxSalutationIndex()` still scans past leading name tokens.
- Comma credentials retain source order, and an unknown all-caps candidate cannot cross a preserved name segment to consume a given name.
- Custom unclosed nickname delimiters are removed exactly without `ltrim()` character-mask warnings, and nested delimiters use stack semantics so a mismatched closer cannot terminate an outer nickname span.
- Comma and surname-first subparsers honor custom whitespace, and parsed confidence uses the parser's configured suffix dictionaries.
- Segment sub-parsers inherit nickname delimiters, so a custom multi-character pair shields commas on the left segment when a structural comma follows (`John %%Bob, Jr%% Smith, MD`).
- Surname-side nicknames are extracted when the given segment is a real name (`John (Bob) Smith, Jane`).
- `setWhitespace('')` no longer leaves empty tokens that pollute `getGivenName()` / `full_name` with a stray space on double-spaced input.
- Empty nickname closer strings are dropped with empty openers, so invalid delimiter config degrades to a no-op instead of an unclosable span.
- Uniform-uppercase detection on comma input splits on commas as well as whitespace, so comma-dense rows do not force a full-string Unicode letters scan.
- Comma-separated non-empty segments count toward the parser's 65,536-token ceiling.
- Credential-only comma tails no longer reinterpret given names such as `Della` and `Van` as surname particles.
- A quoted nickname whose closing quote immediately precedes a structural comma stays intact.
- Connector-heavy salutation input is validated in linear time, and title-only connector chains no longer create a phantom joint partner.
- Joint honorifics retain a terminal title-colliding shared surname (`Mr. and Mrs. Lord`) while still rejecting multi-word and connector-led title-only chains.
- Promoted stock mapper pipelines refresh surname-particle dictionaries after a mutable language configuration changes.
- Negative `setMaxSalutationIndex()` values now use the same leading-title semantics as the default value `0`.
- Caller-supplied confidence tokens no longer bypass the byte or token ceilings.

### For contributors

- CI now enforces the README punctuation rule, rejects empty PHPUnit suites, deprecations, notices, skipped tests, and incomplete tests, and no longer carries the obsolete mbstring-absence mock dependency.
- `composer analyse` enforces the empty PHPStan baseline before running level 9 analysis, matching CI.

## [1.3.0] - 2026-07-10

### Added

- Dutch honorifics (Dhr., Mevr., Mw.) in the default parser, so "Dhr. Jan de Vries" reads the title as a salutation instead of a first name.
- Legal credential JD, so "King, Michelle JD, LPC" keeps both credentials in the suffix.
- `Name::getSource()` returns the normalized input the name was parsed from (null for a manually constructed Name), the same string `getConfidence()` assesses.

### Changed

- `Name::toArray()['given_name']` is now documented as first, middle, and initials only. Use `full_name` when you need the given name plus surname.
- Custom mapper lists set with `setMappers()` now survive `setMaxCombinedInitials()`, `setMaxSalutationIndex()`, and `setNicknameDelimiters()`. Passing an empty list resets the parser to the default pipeline.
- Hostile inputs (kilobytes of unmatched quotes, 100 KB tokens of any case shape, megabyte rows) now parse in linear time and bounded memory.
- `LanguageInterface` documents the dictionary key format: keys must already be normalized (lowercase, periods removed, no edge punctuation) as `Text::key()` produces, and may be int or string, so a numeric ordinal like the German "2." keys under the bare digit.
- Export is faster on large batches; a full parse-plus-`toArray()` row is net faster than 1.2.0, while raw `parse()` alone is slightly slower from the new credential and comma safeguards.

### Fixed

- Credential-only rows such as "Jane DDS" and initial-plus-credential rows such as "John A. MD" now keep the credential in the suffix instead of rewriting it as the last name.
- All-caps short given names stay intact beside mixed-case salutations or credentials, so "JO ANDERSON PhD" keeps first name Jo.
- Parenthetical credentials such as "Jane Doe (MD)" now parse as suffixes instead of nicknames.
- Comma surname segments with real given names keep non-particle compound surnames and left-side suffixes, so "Hidalgo Castillo, Maria" and "Doe Jr, John" parse as expected.
- Interrupted credential tails keep recognized credentials in the suffix while preserving name-like bridge tokens; placeholder/punctuation noise such as "Unknown" or "-" is stripped from the tail, including immediately before the first credential ("Jane Doe Unknown MD").
- Bare single-letter roman numerals stay part of the name instead of becoming a suffix, so "Malcolm X" keeps X as the last name. Multi-letter forms ("John III") still parse as suffixes.
- A trailing comma with nothing after it ("John Smith MD,") no longer appends a trailing space to the first name.
- Nicknames preserve internal apostrophes, so "John (O'Brien) Smith" keeps O'Brien.
- Single-token salutations such as "Mr" now parse as salutations under the default salutation scan.
- Trailing punctuation no longer blocks credential lookup for tokens such as "MD;" and "MD)".
- Unknown trailing credentials are kept when a known credential anchors the tail, so "John Smith MD FACS" keeps both MD and FACS in the suffix instead of leaking FACS into the name. Uniform all-caps rows still cannot recover an unknown credential (casing carries no signal there).
- A credential-only segment after the given name is pulled out to the suffix, so "Smith, MD, John" keeps first name John and credential MD instead of reading MD as a name. Mixed given-plus-credential tails ("John Smith, MD, FACS") keep all credentials.
- A leading credential run in the given segment maps to the suffix, so "Smith, MD John" keeps first name John and credential MD instead of shredding MD into initials.
- The confidence pass keys punctuation-wrapped tokens the same way the parser does, so "NGUYEN, VI;" is flagged as ambiguous instead of slipping past on the trailing semicolon.
- German ordinal suffixes are recognized, so "Friedrich Wilhelm 2." keeps 2. as the suffix (the ordinal keys under the bare digit).
- Caseless-script names are not split into initials, so "Wang, 李明" keeps 李明 as the first name instead of splitting it into two initials.
- Comma-form initials use the whole input's casing, so "Smith, JM" splits JM into J and initial M (the mixed-case surname proves the signal) while all-caps "SMITH, JM" keeps Jm as a first name.
- Surname-first parsing handles a leading salutation and a credential-only tail, so "Dr. Kim Jong Un" keeps surname Kim (not the title) and "Kim Jong Un, MD" keeps surname Kim with credential MD instead of falling back to Western order.
- A comma inside a delimited nickname no longer bisects the name and survives into the nickname, for bracketed, quoted, and custom multi-character forms alike: "John (Bob, Jr) Doe", "John 'Bob, Jr' Doe", and "John <<Bob, Jr>> Doe" (with `['<<' => '>>']`) keep nickname "Bob, Jr", and the given-side "Smith, John (Jack, Robert)" keeps "Jack, Robert" whole.
- An all-caps token behind a preserved name token stays combined initials, so "John Paul JM Smith MD" keeps initials J M; an unknown credential is only recognized inside the contiguous run at the tail.
- Surname-first input with both a leading salutation and a credential-only comma tail keeps the surname, so "Dr. Kim Jong Un, MD" gives surname Kim, salutation Dr., and credential MD.
- Multibyte custom whitespace (U+3000, NBSP) no longer corrupts unrelated glyphs that share its bytes; the collapse pattern matches whole characters. A whitespace set that is not valid UTF-8 keeps the old bytewise semantics instead of warning on every parse.
- Invalid-UTF-8 nickname delimiter keys are ignored instead of emitting a compile warning per token.
- Nickname delimiters accept multibyte and multi-character opener/closer pairs, and empty-string delimiter keys are ignored instead of emitting a warning per parse.
- An elided surname particle survives instead of being read as an unterminated nickname or an initial, so "'t Hooft" keeps the leading particle.
- Spaced parentheses yield a clean nickname, so "John ( Bob ) Smith" gives nickname Bob without stray spaces, and a delimiter pair that cleans to nothing emits no nickname at all.
- A name part that normalizes to "0" survives `getAll()` and the string cast, so "Jane 0" is not silently dropped.
- `setWhitespace('')` no longer emits a warning per parse; an empty whitespace set simply skips the collapse step.

## [1.2.0] - 2026-06-27

### Added

- Dutch and Spanish surname particles (den, ten, los, las), so "van den Heuvel" and "de los Santos" keep the full surname.
- German (vom, zu, zum, zur) and French (le, des) particles in the default parser, so "vom Bruch" and "le Pen" parse without a language class.
- Portuguese (do, dos, das), Filipino joined (dela, delos, delas), and Italian (lo) surname particles, so "Joao dos Santos", "Maria dela Cruz", and "lo Russo" keep the full surname instead of orphaning the particle into the middle name.
- `setSurnameFirst(true)` reads comma-less names in CJK order (surname first), so "Mao Zedong" gives last "Mao". Opt-in; auto-detection from romanized text is not possible.

### Fixed

- Comma form "Last, First" no longer leaks a leading surname particle into the first name ("van der Berg, Johan" gives last "van der Berg", not first "Van Johan").
- A multi-particle surname with no first name keeps its leading particle ("von der Heide", "Dr. de la Cruz"), instead of reading it as the first name.
- A particle in a compound given name renders lowercase, matching surname prefixes ("Maria del Carmen" gives middle "del Carmen").

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

[Unreleased]: https://github.com/iliaal/nameparser/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/iliaal/nameparser/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/iliaal/nameparser/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/iliaal/nameparser/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/iliaal/nameparser/releases/tag/v1.0.0
