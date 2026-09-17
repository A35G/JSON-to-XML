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

This is only supported in the JSON → XML direction. Since `<?xml-stylesheet?>` is a processing instruction, `XmlToJsonConverter` intentionally ignores it, just like any other comment or processing instruction (see "Comments and processing instructions are ignored" below) — it is therefore not recoverable in a round-trip XML → JSON conversion.

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

For security, XML parsing uses `LIBXML_NONET`, preventing network access via external entities and mitigating XXE attacks when the XML comes from untrusted sources.

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

Exit codes: `0` success, `1` invalid usage/unknown command, `2` I/O error, `3` invalid JSON/XML input, `4` internal conversion error.

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