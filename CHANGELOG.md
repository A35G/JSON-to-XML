# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `CONTRIBUTING.md`, `SECURITY.md`, and `CODE_OF_CONDUCT.md` for contributors.

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

## [1.1.0] - 2026-09-16

### Added
### Changed
### Fixed
### Deprecated / Removed / Security
-->
