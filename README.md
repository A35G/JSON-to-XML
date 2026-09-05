# json-to-xml

[![Tests](https://github.com/A35G/json-to-xml/actions/workflows/tests.yml/badge.svg)](https://github.com/A35G/json-to-xml/actions/workflows/tests.yml)

Libreria PHP per convertire una stringa JSON in un documento XML valido (e viceversa), con supporto per attributi, CDATA automatico e mixed content.

## Requisiti

- PHP >= 8.1
- estensioni `dom` e `json`

## Installazione

```bash
composer require a35g/json-to-xml
```

## Uso base

```php
use A35G\JsonToXml\JsonToXmlConverter;

$converter = new JsonToXmlConverter(rootName: 'root', itemNodeName: 'item', useCdata: true);

$xml = $converter->jsonToXmlString($jsonString);
// oppure, per salvare direttamente su file:
$converter->jsonToXmlFile($jsonString, 'output.xml');

// oppure, per stampare l'XML direttamente in output (es. risposta HTTP):
header('Content-Type: application/xml');
$converter->jsonToXmlStdOut($jsonString);
```

## Convenzioni supportate nel JSON

| Chiave JSON | Risultato XML |
|---|---|
| `"nome": "valore"` | elemento figlio `<nome>valore</nome>` |
| `"@nome": "valore"` | attributo `nome="valore"` sul nodo corrente |
| `"#text": "valore"` | testo diretto del nodo (va comunque in CDATA se il contenuto lo richiede) |
| `"nome": [ {...}, {...} ]` | il nodo `<nome>` viene ripetuto una volta per elemento, **senza** wrapper |

### Attributi

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

### CDATA automatico

Un valore va in CDATA solo se il contenuto lo richiede (contiene `< > & ' "` o un a-capo). Non esiste una chiave per forzarlo indipendentemente dal valore:

```json
{ "descrizione": "Testo con <tag> speciali" }
```
```xml
<descrizione><![CDATA[Testo con <tag> speciali]]></descrizione>
```

```json
{ "partitaIva": "12345678901" }
```
```xml
<partitaIva>12345678901</partitaIva>
```

### Mixed content (attributi + testo)

```json
{ "areaCedi": { "@codArea": "XXX", "#text": "contiene <tag>" } }
```
```xml
<areaCedi codArea="XXX"><![CDATA[contiene <tag>]]></areaCedi>
```

### Liste ripetute senza wrapper

```json
{ "Note": { "Nota": [ {"Principale": "0"}, {"Principale": "1"} ] } }
```
```xml
<Note>
  <Nota><Principale>0</Principale></Nota>
  <Nota><Principale>1</Principale></Nota>
</Note>
```

## Gestione dei file temporanei

`jsonToXmlStdOut()` crea internamente un file temporaneo per poi stamparlo con `readfile()`; il file viene sempre eliminato al termine, anche in caso di eccezione. Se serve una directory temporanea diversa da quella di sistema:

```php
$converter->setTempDir('/percorso/scrivibile');
```

## Da XML a JSON: XmlToJsonConverter

La libreria include anche il processo inverso, tramite una classe separata che condivide le stesse convenzioni (`@attributo`, `#text`, liste di elementi ripetuti).

```php
use A35G\JsonToXml\XmlToJsonConverter;

$converter = new XmlToJsonConverter();

$array = $converter->xmlToArray($xmlString);
$json  = $converter->xmlToJsonString($xmlString);
$converter->xmlFileToJsonFile('input.xml', 'output.json');
```

### ⚠️ Non è un round-trip perfetto

Convertire XML in JSON è intrinsecamente ambiguo in alcuni casi, quindi il risultato non sarà mai identico bit-per-bit all'origine:

- **Elemento singolo vs lista**: `<Nota>...</Nota>` che compare una sola volta diventa un oggetto singolo, non un array con un elemento. Se il tuo tracciato prevede che un certo tag sia *sempre* una lista (anche con un solo elemento), usa `forceArrayTags`:

  ```php
  $converter = new XmlToJsonConverter(forceArrayTags: ['Nota']);
  ```

  Così `<Note><Nota>...</Nota></Note>` produce comunque `{"Note": {"Nota": [ {...} ]}}`, evitando che il JSON cambi forma (da oggetto ad array) il giorno in cui quel tag inizia a ripetersi.

- **CDATA vs testo semplice**: `<x>a</x>` e `<x><![CDATA[a]]></x>` producono lo stesso valore JSON `"a"` — l'informazione "era in CDATA" non è recuperabile.
- **Elemento vuoto**: `<note/>` diventa `null`. Se ha solo attributi (es. `<areaCedi codArea="XXX"/>`) diventa `{"@codArea": "XXX"}`, senza chiave `#text`.
- **Commenti e processing instruction** vengono ignorati.
- Per sicurezza, il parsing XML usa `LIBXML_NONET` (nessun accesso di rete per entità esterne), a mitigazione di attacchi XXE — utile perché l'XML in input potrebbe provenire da fonti non fidate.

## Test

```bash
composer install
composer test
```

## Licenza

MIT — vedi [LICENSE](LICENSE).
