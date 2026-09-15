# Contributing to json-to-xml

Thanks for your interest in contributing! This document explains how to set up the project, the conventions used in the codebase, and how to submit changes.

## Getting started

```bash
git clone https://github.com/A35G/json-to-xml.git
cd json-to-xml
composer install
```

Requirements:

- PHP >= 8.1
- `dom` and `json` extensions

## Running the tests

The project uses PHPUnit 10.5. Run the full suite with:

```bash
composer test
```

or directly:

```bash
vendor/bin/phpunit --colors=always
```

All new features and bug fixes must be covered by tests. The test suite is organized as follows:

- `JsonToXmlConverterTest.php` — unit tests for JSON → XML conversion
- `XmlToJsonConverterTest.php` — unit tests for XML → JSON conversion
- `LibraryIntegrityTest.php` — cross-cutting tests: project structure, round-trip conversion, XXE protection, temp-file handling, edge cases

If you fix a bug, please add a regression test that would have failed before your fix. If you add a new JSON/XML convention or edge-case behavior, add it to `LibraryIntegrityTest.php` with a comment explaining why it matters, following the existing style.

## Code style and conventions

- All PHP files start with `declare(strict_types=1);` and live under the `A35G\JsonToXml` namespace (or `A35G\JsonToXml\Tests` for tests).
- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style.
- Public methods should have PHPDoc blocks describing parameters, return values, and thrown exceptions, consistent with the existing classes.
- Keep method names and internal logic in English going forward; note that **existing inline comments and docblocks are written in Italian** — this is intentional and reflects the project's origin. New comments can be written in English or Italian; consistency within a single file is preferred over forcing a rewrite of unrelated code.
- Favor small, focused private methods over long conditional blocks, matching the current structure of `JsonToXmlConverter` and `XmlToJsonConverter`.

## Conventions to preserve

This library defines a specific, documented mapping between JSON and XML (`@` for attributes, `#text` for mixed content, list repetition without wrappers, automatic CDATA, etc.). Any change to this mapping is a **breaking change** and must:

1. Be discussed in an issue before implementation.
2. Update `README.md` (both the English README and any translated docs, if present).
3. Include new tests in `LibraryIntegrityTest.php` documenting the new behavior, especially if it affects round-trip conversion.

## Security-sensitive changes

Changes to `XmlToJsonConverter::loadXml()` (XML parsing, `LIBXML_NONET`, entity handling) are security-sensitive. Please read [SECURITY.md](SECURITY.md) before touching this code, and make sure the existing XXE regression tests still pass.

## Submitting a pull request

1. Fork the repository and create a branch from `main`.
2. Make your changes, with tests.
3. Run `composer test` and make sure everything passes.
4. Update `README.md` if you changed or added public behavior.
5. Open a pull request describing:
   - What the change does and why
   - Any breaking changes
   - Which tests cover the new behavior

Small, focused PRs are easier to review than large ones — feel free to open a draft PR early if you want feedback on direction.

## Reporting bugs

Please open a GitHub issue with:

- The JSON or XML input you used
- The output you got vs. the output you expected
- Your PHP version

For security vulnerabilities, do **not** open a public issue — see [SECURITY.md](SECURITY.md) instead.
