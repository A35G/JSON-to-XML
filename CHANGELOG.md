# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Regression tests in `LibraryIntegrityTest.php` covering:
  - entity-expansion attacks (Billion Laughs, recursive entities, quadratic blowup in element content and in attribute values), run in a separate PHP subprocess with a reduced `memory_limit` and a timeout, so a regression cannot crash or hang the whole test suite;
  - resource budgets for large documents (100k sibling elements, 20k attributes, 5k namespace declarations, an 11 MB text node) and nesting depth limits in both directions;
  - XML → JSON behavior of namespaces, and JSON → XML edge cases (duplicate keys, name collisions after sanitization);
  - markup and namespace injection through JSON values, keys, and the root/item names;
  - additional temporary-file and file I/O error cases.

### Changed
- `JsonToXmlConverter` now decodes JSON integers beyond the int64 range as strings (`JSON_BIGINT_AS_STRING`), so they are written to XML in full instead of being rounded to a float in scientific notation (e.g. `1.2345678901235E+19`).
- `XmlToJsonConverter` now rejects documents containing references to entities declared in the DOCTYPE (see Security).
- `SECURITY.md` and `README.md`: documented what the new tests do and do not guarantee, the dependency on the bundled libxml2 version, and the library's limits (no size/time limits of its own, unvalidated file paths, namespaces not preserved).

### Fixed
- `JsonToXmlConverter` now throws `InvalidArgumentException` for values containing characters that are not allowed in XML 1.0 (control characters other than tab, LF and CR), instead of silently producing a malformed document.

### Security
- `XmlToJsonConverter` now rejects (`InvalidArgumentException`) references to entities declared in the DOCTYPE, both in attribute values and in element content. In attribute values, reading the value expanded the entity with quadratic cost that neither `loadXML()` nor libxml2's own limits prevented (on libxml2 2.10.4, 16 MB of expanded content took about 14 s, and a ~250 KB crafted document did not finish within a 15 s test timeout). In element content, such references used to be silently dropped, losing data. Documents that rely on custom entities are no longer accepted; predefined entities (`&amp;`, `&lt;`, ...) and character references are unaffected.

## [1.2.0] - 2026-09-18

_Tag committed 2026-09-18 01:02:52 +0200; GitHub Release published 2026-09-18 01:10_

### Added
- `JsonToXmlConverter::setStylesheet()` / `clearStylesheet()`: associate an XSLT or CSS stylesheet with generated XML documents via an `<?xml-stylesheet?>` processing instruction, placed before the root element.
- `--stylesheet` and `--stylesheet-type` options for the `to-xml` CLI command.

## [1.1.0] - 2026-09-16

_Tag committed 2026-09-16 01:51:11 +0200; GitHub Release published 2026-09-16 02:01_

### Added
- `bin/json-to-xml` CLI tool for converting between JSON and XML directly from the shell.
- `CliTest.php`: test suite covering the CLI as a real subprocess.
- PHPStan (level 8) and PHP_CodeSniffer (PSR-12) configuration, plus corresponding `composer` scripts for static analysis and code style checks.
- Dedicated GitHub Actions workflow for static analysis and code style, run alongside the existing test workflow.
- `CONTRIBUTING.md`, `SECURITY.md`, and `CODE_OF_CONDUCT.md` for contributors.

### Fixed
- `sanitizeTagName()` now handles the case where `preg_replace()` returns `null` on a regex engine error, instead of passing `null` on to code expecting a string.

### Changed
- Simplified redundant type checks flagged by static analysis.

## [1.0.1] - 2026-09-11

_Tag committed 2026-09-11 01:48:39 +0200; GitHub Release published 2026-09-11 17:37._

### Fixed
- Removed a temporary-file leak in `jsonToXmlStdOut()`: the temp file is now
  always deleted in a `finally` block, even if conversion fails.
- `tempFilename()` now throws a clear `RuntimeException` when the configured
  temp directory is not writable, instead of silently falling back to the
  system temp directory.
- Cross-platform fixes to the test suite (e.g. skipping the non-writable-
  directory test on Windows, where POSIX permissions can't be simulated
  reliably).

### Added
- `LibraryIntegrityTest.php`: integrity/regression test suite covering
  XXE protection, PSR-4 autoload consistency, round-trip conversion, and
  tag-name sanitization edge cases.

## [1.0.0] - 2026-09-05

_Tag committed 2026-09-05 14:44:55 +0200; GitHub Release published 2026-09-10 21:13._

### Added
- Initial public release of `json-to-xml`.
- `JsonToXmlConverter`: JSON → XML conversion with support for attributes
  (`@key`), mixed content (`#text`), automatic CDATA based on content, and
  list repetition without an intermediate wrapper.
- `XmlToJsonConverter`: XML → JSON conversion with the inverse conventions,
  `forceArrayTags` to preserve list shape for single-element lists, and
  XXE protection via `LIBXML_NONET`.

<!--
When cutting a new release, move entries from [Unreleased] into a new dated
section above, e.g.:

## [1.2.0] - YYYY-MM-DD

### Added
### Changed
### Fixed
### Deprecated / Removed / Security
-->