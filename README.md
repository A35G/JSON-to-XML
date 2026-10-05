<div align="center">

# JSON to XML

[![Tests](https://github.com/A35G/json-to-xml/actions/workflows/tests.yml/badge.svg)](https://github.com/A35G/json-to-xml/actions/workflows/tests.yml) ![GitHub Release](https://img.shields.io/github/v/release/A35G/JSON-to-XML) ![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg) ![PHP](https://img.shields.io/badge/PHP-^8.1-777bb4.svg) [![Wiki](https://img.shields.io/badge/docs-read-172224)](https://github.com/A35G/JSON-to-XML/wiki) ![Packagist Downloads](https://img.shields.io/packagist/dt/a35g/json-to-xml)

</div>

<p align="center">
    <a href="https://a35g.github.io/JSON-to-XML/"><strong>Try the live demo →</strong></a>
</p>

PHP library to convert a JSON string into a valid XML document (and vice versa), with support for attributes, automatic CDATA, and mixed content.
 
## Why this library?
 
Converting simple JSON to XML is easy. The problem starts when your XML needs:
 
- attributes
- mixed content (text + attributes + children on the same node)
- repeated elements without a wrapper
- CDATA, applied automatically only where it's actually needed
- predictable, symmetric JSON ↔ XML conventions
- secure XML parsing (no XXE)
Here's what that looks like in practice:
 
```json
{
  "request": {
    "@type": "ABC",
    "cliente": "Mario Rossi",
    "note": [
      {"#text": "Prima nota"},
      {"#text": "Seconda nota"}
    ]
  }
}
```
 
```xml
<request type="ABC">
    <cliente>Mario Rossi</cliente>
    <note>Prima nota</note>
    <note>Seconda nota</note>
</request>
```
 
One call, no manual `DOMDocument` wrangling, no wrapper elements around the repeated `note` nodes, and the XML parsing on the way back is hardened against XXE by default (`LIBXML_NONET`, see the security note further down).
For untrusted input, also consider setting a size limit (see [Limiting input size](#limiting-input-size)) and rejecting DOCTYPE declarations (see [Security of XML parsing](#security-of-xml-parsing)).

## Requirements

- PHP \>= 8.1
- `dom` and `json` extensions

## Installation

```bash
composer require a35g/json-to-xml
```

## Basic usage

```php
use A35G\JsonToXml\JsonToXmlConverter;

$converter = new JsonToXmlConverter(rootName: 'root', itemNodeName: 'item', useCdata: true);

$xml = $converter->jsonToXmlString($jsonString);

// or, to save the result directly to a file:
$converter->jsonToXmlFile($jsonString, 'output.xml');

// or, to print the XML directly to output (e.g. an HTTP response):
header('Content-Type: application/xml');
$converter->jsonToXmlStdOut($jsonString);
```

## Supported conventions in the JSON

| JSON key | XML result |
| --- | --- |
| `"name": "value"` | child element `<name>value</name>` |
| `"@name": "value"` | attribute `name="value"` on the current node |
| `"#text": "value"` | direct text of the node |
| `"name": [ {...}, {...} ]` | the `<name>` node is repeated once per element, **without** a wrapper |

### Scalar values

JSON scalar values are converted into their XML text representation:

| JSON | XML |
| --- | --- |
| `"hello"` | `<element>hello</element>` |
| `123` | `<element>123</element>` |
| `true` | `<element>true</element>` |
| `false` | `<element>false</element>` |
| `null` | `<element/>` |
| `12345678901234567890` | `<element>12345678901234567890</element>` |

Integers beyond the int64 range are written with all their digits (they are decoded as strings, not rounded to a float). Strings containing characters that are not allowed in XML 1.0 (control characters other than tab, line feed and carriage return) are rejected with an `InvalidArgumentException`: they cannot be represented in an XML 1.0 document, not even as character references.

### Attributes

```json
{
  "request": {
    "@code": "",
    "@typeReq": "ABC",
    "cliente": "Mario Rossi"
  }
}
```

```xml
<request code="" typeReq="ABC">
  <cliente>Mario Rossi</cliente>
</request>
```

An attribute must have a scalar value. Using an array as an attribute value throws an exception.

### Automatic CDATA

A value is wrapped in CDATA when the content requires it, for example when it contains special XML characters such as `<`, `>` or `&`, or a newline.

```json
{
  "descrizione": "Testo con <tag> speciali"
}
```

```xml
<descrizione><![CDATA[Testo con <tag> speciali]]></descrizione>
```

For a value that does not require CDATA:

```json
{
  "partitaIva": "12345678901"
}
```

```xml
<partitaIva>12345678901</partitaIva>
```

Automatic CDATA generation can be disabled via `useCdata: false`:

```php
$converter = new JsonToXmlConverter(rootName: 'root', itemNodeName: 'item', useCdata: false);
```

In this case special characters are handled through normal XML escaping:

```xml
<descrizione>Testo con &lt;tag&gt; speciali</descrizione>
```

There is no JSON `#cdata` key to manually force CDATA.

### Mixed content

Attributes, text, and child elements can be combined on the same node.

```json
{
  "operatore": {
    "@codArea": "XXX",
    "#text": "contiene <tag>"
  }
}
```

```xml
<operatore codArea="XXX"><![CDATA[contiene <tag>]]></operatore>
```

## Associating a stylesheet

You can associate an XSLT (or CSS) stylesheet with the generated XML document via `setStylesheet()`. This adds an `<?xml-stylesheet ...?>` processing instruction right after the XML declaration and before the root element, following the W3C convention for associating style sheets with XML documents.

```php
$converter = new JsonToXmlConverter(rootName: 'root');
$converter->setStylesheet('style.xsl'); // type defaults to "text/xsl"

$xml = $converter->jsonToXmlString($jsonString);
```

```xml
<?xml version="1.0" encoding="UTF-8"?>
<?xml-stylesheet type="text/xsl" href="style.xsl"?>
<root>
  ...
</root>
```

A CSS stylesheet can be used instead by passing an explicit type:

```php
$converter->setStylesheet('style.css', 'text/css');
```

To remove a previously configured stylesheet so subsequent conversions no longer include it:

```php
$converter->clearStylesheet();
```

`href` cannot be empty, and cannot contain both single and double quotes at the same time (the library needs to be able to quote it safely inside the processing instruction).

This is only supported in the JSON → XML direction. Since `<?xml-stylesheet?>` is a processing instruction, `XmlToJsonConverter` intentionally ignores it, just like any other comment or processing instruction (see "Not a perfect round-trip" below) — it is therefore not recoverable in a round-trip XML → JSON conversion.

**Security note:** `href` is written as-is into the processing instruction; it is not validated, sanitized, or resolved by this library. Do not pass untrusted/user-supplied input as `href` — see [SECURITY.md](SECURITY.md#stylesheet-association-jsontoxmlconvertersetstylesheet) for details.

## Repeated lists without a wrapper

Indexed JSON arrays are represented as repeated XML elements, without an additional wrapper element.

```json
{
  "Note": {
    "Nota": [
      {
        "Principale": "0"
      },
      {
        "Principale": "1"
      }
    ]
  }
}
```

```xml
<Note>
  <Nota><Principale>0</Principale></Nota>
  <Nota><Principale>1</Principale></Nota>
</Note>
```

Arrays of scalar values are also represented as repeated elements:

```json
{
  "item": ["one", "two", "three"]
}
```

```xml
<item>one</item>
<item>two</item>
<item>three</item>
```

An empty array does not generate any XML element.

## Temporary file handling

`jsonToXmlStdOut()` internally creates a temporary file and then prints it via `readfile()`; the file is always deleted at the end, even if an exception occurs.

If you need a temporary directory other than the system one:

```php
$converter->setTempDir('/writable/path');
```

## File paths and atomic writes

`jsonToXmlFile()` and `XmlToJsonConverter::xmlFileToJsonFile()` write their output **atomically**: the content goes to a temporary file in the destination directory, is flushed to disk and then renamed over the destination. If anything fails, an existing destination file is left untouched and no temporary file is left behind.

Things to know:

- The destination directory must be writable (not just the file itself), and it must already exist.
- A new file gets the permissions `file_put_contents()` would give it (`0666` filtered by the umask); an existing file keeps its permissions.
- A symlink used as destination is **replaced**, not followed, and a hard link is broken. A file without write permission can be replaced if its directory is writable.
- Atomicity relies on `rename()` within one filesystem; on network filesystems the guarantees depend on the filesystem.

Paths that are empty, contain NUL bytes or use a stream wrapper (`php://`, `phar://`, `ftp://`, `file://`, `data:` ...) are rejected with an `InvalidArgumentException`, for both reading and writing. To print the XML to the output, use `jsonToXmlStdOut()` instead of `php://output`.

If paths come from user input, confine them to a directory:

```php
$converter = new JsonToXmlConverter();
$converter->setBaseDir('/var/app/exports'); // null removes the restriction

$converter->jsonToXmlFile($json, '/var/app/exports/report.xml'); // ok
$converter->jsonToXmlFile($json, '/var/app/exports/../etc/x');   // InvalidArgumentException
```

`setBaseDir()` is available on both converters. Paths are resolved with `realpath()`, so `..` and symlinks pointing outside the directory are rejected; relative paths are resolved against the current working directory. It is a best-effort check: a short window remains between the check and the file operation, so don't rely on it where an attacker can modify the directory concurrently. The CLI options `--input` and `--output` are not restricted, because they are chosen by whoever runs the command (and the CLI writes its output without the atomic step).

## Limiting input size

By default the converters accept input of any size, so a very large JSON or XML document is only stopped by PHP's `memory_limit`. When the input comes from an untrusted source, set a limit in bytes:

```php
use A35G\JsonToXml\JsonToXmlConverter;
use A35G\JsonToXml\XmlToJsonConverter;

// Constructor argument (named parameter)...
$toXml  = new JsonToXmlConverter(rootName: 'root', maxInputBytes: 1_048_576);   // 1 MiB
$toJson = new XmlToJsonConverter(forceArrayTags: ['Nota'], maxInputBytes: 1_048_576);

// ...or later, with the setter (null removes the limit)
$toJson->setMaxInputBytes(2_097_152);
```

If the input is larger than the limit, an `InvalidArgumentException` is thrown **before** any parsing happens. An input exactly as large as the limit is accepted.

Things to know:

- The limit must be an integer >= 1, or `null` (no limit, the default). Any other value throws an `InvalidArgumentException`.
- The limit applies to the **input** size, not to the memory actually used. Converting builds a DOM, then a PHP array, then the output string, so memory usage can be many times the input size (roughly 10-50x). For untrusted input, choose a conservative limit and make sure `memory_limit` can accommodate it.
- `XmlToJsonConverter::xmlFileToJsonFile()` reads at most `limit + 1` bytes of the file, so huge files and endless streams (e.g. `/dev/zero`) are never loaded entirely into memory. If the limit is exceeded, the output file is not created or modified.
- The limit covers the size of the string passed to the converter. It does not replace validation at the transport level (e.g. `post_max_size`, a request body cap in your web server).

## From XML to JSON: XmlToJsonConverter

The library also includes the reverse process, through a separate class that shares the same conventions (`@attribute`, `#text`, lists of repeated elements).

```php
use A35G\JsonToXml\XmlToJsonConverter;

$converter = new XmlToJsonConverter();

$array = $converter->xmlToArray($xmlString);
$json  = $converter->xmlToJsonString($xmlString);

$converter->xmlFileToJsonFile('input.xml', 'output.json');
```

### Force array

By default, an XML element present only once is represented as a single value, while repeated elements are represented as an array.

If a given tag must always be represented as an array, you can use `forceArrayTags`:

```php
$converter = new XmlToJsonConverter(forceArrayTags: ['Nota']);
```

This way:

```xml
<Note>
  <Nota>Prima nota</Nota>
</Note>
```

produces:

```json
{
  "Nota": [
    "Prima nota"
  ]
}
```

The configuration can also be changed after the object has been constructed:

```php
$converter->setForceArrayTags(['Nota']);
```

### ⚠️ Not a perfect round-trip

Converting XML to JSON is inherently ambiguous in some cases, so the result will not necessarily be identical to the original representation.

In particular:

- **Single element vs. list**: `<Nota>...</Nota>` that appears only once becomes a single value, not a one-element array. Use `forceArrayTags` if a given tag must always be a list.
- **CDATA vs. plain text**: `<x>a</x>` and `<x><![CDATA[a]]></x>` produce the same JSON value `"a"`; the information "it was in CDATA" cannot be recovered.
- **Empty element**: `<note/>` becomes `null`. If it only has attributes, e.g. `<operatore codArea="XXX"/>`, it becomes `{"@codArea": "XXX"}`, with no `#text` key.
- **Comments and processing instructions** are ignored.
- **Mixed content**: text and child elements are represented separately in the JSON; the original order between the different nodes is not preserved as a sequence.
- **Unicode**: Unicode characters are preserved during conversion.
- **Entities**: references to entities declared in the DOCTYPE (in element content or attribute values) are rejected with an `InvalidArgumentException`. Predefined entities and character references work normally.

### Security of XML parsing

Parsing uses `LIBXML_NONET` (no network access for external entities) and deliberately **does not** enable `LIBXML_NOENT`, `LIBXML_DTDLOAD` or `LIBXML_PARSEHUGE`. Note that `LIBXML_NONET` alone does not block `file://`: reading local files through external entities is prevented because entities are never substituted. Entity references found in elements or attributes are rejected with an `InvalidArgumentException`.

For untrusted input, reject DOCTYPE declarations altogether:

```php
$converter = new XmlToJsonConverter(allowDoctype: false, maxInputBytes: 1_048_576);
// or later: $converter->setAllowDoctype(false);
```

With `allowDoctype: false`, any XML containing `<!DOCTYPE` is rejected **before** parsing. The check is textual, so a `<!DOCTYPE` inside a comment or CDATA section is rejected too. Inputs containing NUL bytes (e.g. UTF-16/32) are rejected in this mode. The default is `true` for backward compatibility.

Entity-expansion attacks (Billion Laughs and similar) are covered by regression tests, but the protection comes from libxml2's own built-in limits, which the library leaves enabled (it never passes `LIBXML_NOENT` or `LIBXML_PARSEHUGE`). It therefore depends on the libxml2 version bundled with your PHP build. The library can cap the input size (see [Limiting input size](#limiting-input-size)) and reject DOCTYPE declarations outright (`allowDoctype: false`), but it does not enforce any time limit. For fully untrusted input, use both options and add a timeout on your side. See [SECURITY.md](SECURITY.md) for details.

## CLI

The package also ships a small command-line tool, `bin/json-to-xml`, for quick conversions without writing any PHP:

```bash
# JSON -> XML, reading from a file and writing to another file
vendor/bin/json-to-xml to-xml --input=data.json --output=data.xml --root=root --item=entry

# XML -> JSON, reading from STDIN and writing to STDOUT
cat data.xml | vendor/bin/json-to-xml to-json --force-array=Nota
```

Options:

| Option | Applies to | Description |
| --- | --- | --- |
| `--input=FILE` | both | Read input from `FILE` instead of STDIN |
| `--output=FILE` | both | Write output to `FILE` instead of STDOUT |
| `--root=NAME` | `to-xml` | Root element name (default `data`) |
| `--item=NAME` | `to-xml` | Node name used for numeric/list items (default `item`) |
| `--no-cdata` | `to-xml` | Disable automatic CDATA wrapping |
| `--stylesheet=FILE` | `to-xml` | Add an `<?xml-stylesheet?>` processing instruction with this `href` |
| `--stylesheet-type=TYPE` | `to-xml` | MIME type for `--stylesheet` (default `text/xsl`) |
| `--force-array=Tag1,Tag2` | `to-json` | Tags always represented as JSON arrays |
| `--max-bytes=N` | both | Maximum input size in bytes (default `16777216`, i.e. 16 MiB; `0` disables the limit) |
| `--allow-doctype` | `to-json` | Accept XML with a DOCTYPE (rejected by default in the CLI; use only with trusted input) |

Input larger than the limit is rejected with exit code `3`; an invalid `--max-bytes` value is a usage error (exit code `1`).
A document with a DOCTYPE is rejected by `to-json` with exit code `3` unless `--allow-doctype` is given.

Exit codes: `0` success, `1` invalid usage/unknown command, `2` I/O error, `3` invalid JSON/XML input, `4` internal conversion error.

`--input` and `--output` paths are used as-is: they are not validated, symlinks are followed and existing files are overwritten. Do not pass user-controlled paths without validating them first.

## Tests

```bash
composer install
composer test
```

The suite includes a `CliTest` that exercises `bin/json-to-xml` as a real subprocess (argument parsing, STDIN/STDOUT, file I/O, exit codes).

## Static analysis & coding standards

```bash
composer analyse   # PHPStan, level 8
composer cs        # PHP_CodeSniffer, PSR-12
composer cs-fix     # auto-fix what PHPCS can fix automatically
composer check      # analyse + cs + test, in one go
```

If `composer analyse` reports pre-existing issues the first time you wire it into an older codebase, it's common to snapshot them into a baseline rather than fixing everything at once:

```bash
vendor/bin/phpstan analyse --generate-baseline
```

## License

MIT — see [LICENSE](LICENSE).