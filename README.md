<div align="center">

# JSON to XML

[![Tests](https://github.com/A35G/json-to-xml/actions/workflows/tests.yml/badge.svg)](https://github.com/A35G/json-to-xml/actions/workflows/tests.yml) ![GitHub Release](https://img.shields.io/github/v/release/A35G/JSON-to-XML) ![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg) ![PHP](https://img.shields.io/badge/PHP-^8.1-777bb4.svg) [![Wiki](https://img.shields.io/badge/docs-read-172224)](https://github.com/A35G/JSON-to-XML/wiki) ![Packagist Downloads](https://img.shields.io/packagist/dt/a35g/json-to-xml)

</div>

PHP library to convert a JSON string into a valid XML document (and vice versa), with support for attributes, automatic CDATA, and mixed content.

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

## Tests

```bash
composer install
composer test
```

## License

MIT — see [LICENSE](LICENSE).