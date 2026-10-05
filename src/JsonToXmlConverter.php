<?php

declare(strict_types=1);

namespace A35G\JsonToXml;

use DOMDocument;
use DOMElement;
use DOMProcessingInstruction;
use InvalidArgumentException;
use RuntimeException;

/**
 * JsonToXmlConverter
 *
 * Converte una stringa JSON in un documento XML valido.
 *
 * Convenzioni supportate nelle chiavi dell'array/oggetto JSON:
 *  - "chiave": valore              -> elemento figlio <chiave>valore</chiave>
 *  - "@chiave": valore             -> attributo chiave="valore" sul nodo corrente
 *  - "#text": valore                -> testo diretto del nodo corrente (mixed content);
 *                                       va comunque in CDATA se il contenuto lo richiede
 *                                       (stessa logica automatica degli altri valori)
 *  - "chiave": [ ... ] (lista JSON) -> il nodo <chiave> viene ripetuto una volta
 *                                       per ogni elemento della lista, senza wrapper
 *
 * Il CDATA viene applicato solo automaticamente, in base al contenuto (vedi needsCdata()):
 * non esiste una chiave per forzarlo indipendentemente dal valore.
 *
 * È inoltre possibile associare al documento generato un foglio di stile
 * (XSLT o CSS) tramite setStylesheet(): viene aggiunta una processing
 * instruction <?xml-stylesheet ...?> subito dopo la dichiarazione XML e
 * prima dell'elemento radice. Questa impostazione non ha una chiave JSON
 * corrispondente: è una proprietà del documento, non del contenuto, e va
 * quindi configurata sul converter stesso (come setTempDir()).
 */
class JsonToXmlConverter
{
    /** Identifica una chiave come attributo XML anziché elemento figlio */
    private const ATTRIBUTE_PREFIX = '@';

    /** Chiave per il testo diretto di un nodo (mixed content) */
    private const TEXT_KEY = '#text';

    private DOMDocument $dom;
    private string $rootName;
    private string $itemNodeName;
    private bool $useCdata;

    /** Href del foglio di stile associato al documento (null = nessuno) */
    private ?string $stylesheetHref = null;

    /** Tipo MIME del foglio di stile (es. "text/xsl", "text/css") */
    private string $stylesheetType = 'text/xsl';

    private ?int $maxInputBytes;

    protected ?string $tempdir = null;
    private ?string $baseDir = null;

    /**
     * @param string $rootName      Nome del nodo radice del documento XML
     * @param string $itemNodeName  Nome usato per gli elementi quando il JSON radice
     *                              è esso stesso una lista, oppure per liste-di-liste.
     *                              Per le liste "normali" (una chiave -> array di oggetti)
     *                              viene invece ripetuto il nome della chiave stessa.
     * @param bool   $useCdata      Se true, avvolge automaticamente in CDATA i valori
     *                              che contengono caratteri speciali XML (< > & ' ") o newline.
     *                              Se false, il testo viene serializzato utilizzando la normale
     *                              codifica di escape XML.
     * @param int|null $maxInputBytes Dimensione massima (in byte) del JSON accettato; null = nessun limite.
     */
    public function __construct(
        string $rootName = 'data',
        string $itemNodeName = 'item',
        bool $useCdata = true,
        ?int $maxInputBytes = null
    ) {
        $this->rootName       = $rootName;
        $this->itemNodeName   = $this->sanitizeTagName($itemNodeName);
        $this->useCdata       = $useCdata;
        $this->maxInputBytes  = self::assertValidLimit($maxInputBytes);

        if (!$this->isTempDirWritable()) {
            self::log('Warning: tempdir ' . $this->resolveTempDir() . ' non scrivibile, usa ->setTempDir()');
        }
    }

    /**
     * Imposta (o rimuove, con null) il limite di dimensione del JSON in ingresso.
     *
     * @throws InvalidArgumentException se il limite non è un intero positivo valido
     */
    public function setMaxInputBytes(?int $maxInputBytes): void
    {
        $this->maxInputBytes = self::assertValidLimit($maxInputBytes);
    }

    /**
     * @throws InvalidArgumentException se il limite è < 1 o troppo grande
     */
    private static function assertValidLimit(?int $limit): ?int
    {
        if ($limit !== null && ($limit < 1 || $limit > PHP_INT_MAX - 1)) {
            throw new InvalidArgumentException('maxInputBytes deve essere un intero >= 1 oppure null.');
        }

        return $limit;
    }

    public function setTempDir(?string $tempdir = null): void
    {
        $this->tempdir = $tempdir;
    }

    /**
     * Confina in una directory i percorsi accettati da jsonToXmlFile()
     * (null = nessun confinamento, comportamento predefinito).
     *
     * Il percorso viene risolto con realpath(): ".." e symlink che escono dalla
     * directory vengono rifiutati. I percorsi relativi sono risolti rispetto alla
     * directory di lavoro corrente. jsonToXmlStdOut() non è interessato, perché
     * usa solo file temporanei interni.
     *
     * @throws InvalidArgumentException se la directory non esiste
     */
    public function setBaseDir(?string $baseDir): void
    {
        $this->baseDir = FileGuard::normalizeBaseDir($baseDir);
    }

    /**
     * Associa un foglio di stile (XSLT o CSS) ai documenti XML generati dalle
     * chiamate successive a jsonToXmlString()/jsonToXmlFile()/jsonToXmlStdOut().
     *
     * Aggiunge una processing instruction <?xml-stylesheet type="..." href="..."?>
     * subito dopo la dichiarazione XML e prima dell'elemento radice, come da
     * specifica W3C "Associating Style Sheets with XML documents".
     *
     * Nota: essendo una processing instruction, viene intenzionalmente ignorata
     * da XmlToJsonConverter (che già ignora commenti e PI in generale): non è
     * quindi recuperabile in un eventuale round-trip XML -> JSON.
     *
     * @param string $href Percorso o URL del foglio di stile. Non può essere vuoto.
     * @param string $type Tipo MIME del foglio di stile (default "text/xsl").
     *
     * @throws InvalidArgumentException se $href è vuoto, se $type contiene apici,
     *                                  o se $href contiene sia apici singoli che
     *                                  doppi (impossibile quotarlo in modo sicuro
     *                                  all'interno della processing instruction)
     */
    public function setStylesheet(string $href, string $type = 'text/xsl'): void
    {
        if (trim($href) === '') {
            throw new InvalidArgumentException('L\'href del foglio di stile non può essere vuoto.');
        }

        if (str_contains($type, '"') || str_contains($type, "'")) {
            throw new InvalidArgumentException('Il "type" del foglio di stile non può contenere apici.');
        }

        if (str_contains($href, '"') && str_contains($href, "'")) {
            throw new InvalidArgumentException(
                'L\'href del foglio di stile non può contenere sia apici singoli che doppi: '
                . 'non è possibile quotarlo in modo sicuro all\'interno della processing instruction.'
            );
        }

        $this->stylesheetHref = $href;
        $this->stylesheetType = $type;
    }

    /**
     * Rimuove un foglio di stile precedentemente impostato con setStylesheet().
     * I documenti generati dalle chiamate successive non includeranno più la
     * processing instruction <?xml-stylesheet ...?>.
     */
    public function clearStylesheet(): void
    {
        $this->stylesheetHref = null;
    }

    /**
     * Directory usata per i file temporanei (quella impostata con setTempDir(),
     * oppure la temp dir di sistema).
     */
    private function resolveTempDir(): string
    {
        return !empty($this->tempdir) ? $this->tempdir : sys_get_temp_dir();
    }

    /**
     * Verifica che la directory temporanea sia scrivibile, SENZA creare alcun file.
     * (In precedenza questo controllo chiamava tempnam(), lasciando un file
     * orfano su disco a ogni istanziazione della classe: fix del leak.)
     */
    private function isTempDirWritable(): bool
    {
        $dir = $this->resolveTempDir();

        return is_dir($dir) && is_writable($dir);
    }

    /**
     * Crea un nuovo file temporaneo vuoto e ne restituisce il percorso.
     * Chi chiama questo metodo è responsabile di eliminarlo quando non serve più.
     *
     * @throws RuntimeException se non è possibile creare il file temporaneo
     */
    protected function tempFilename(): string
    {
        // Controllo esplicito: tempnam() su una directory non scrivibile NON
        // restituisce false, ma fa fallback silenzioso sulla temp dir di
        // sistema. Senza questo controllo, setTempDir() con un percorso non
        // scrivibile fallirebbe silenziosamente invece di segnalare l'errore.
        if (!$this->isTempDirWritable()) {
            throw new RuntimeException(
                'La directory temporanea "' . $this->resolveTempDir() . '" non è scrivibile: usa ->setTempDir().'
            );
        }

        $filename = tempnam($this->resolveTempDir(), 'xml_writer_');
        if ($filename === false) {
            throw new RuntimeException(
                'Impossibile creare un file temporaneo: controlla i limiti di file handle o i permessi della directory.'
            );
        }

        return $filename;
    }

    /**
     * Converte il JSON in XML e lo stampa direttamente in output (es. per una risposta HTTP).
     * Il file temporaneo usato internamente viene sempre eliminato, anche in caso di errore.
     */
    public function jsonToXmlStdOut(string $jsonString): void
    {
        $tempFile = $this->tempFilename();

        try {
            $this->writeXmlFile($jsonString, $tempFile, true);
            readfile($tempFile);
        } finally {
            // Fix del leak: il file temporaneo va sempre ripulito, non solo nel percorso "felice".
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Converte una stringa JSON in una stringa XML.
     *
     * @throws InvalidArgumentException se il JSON non è valido
     */
    public function jsonToXmlString(string $jsonString, bool $prettyPrint = true): string
    {
        $data = $this->decodeJson($jsonString);

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = $prettyPrint;

        if ($this->stylesheetHref !== null) {
            $this->dom->appendChild($this->createStylesheetProcessingInstruction());
        }

        $root = $this->dom->createElement($this->sanitizeTagName($this->rootName));
        $this->dom->appendChild($root);

        $this->arrayToXml($data, $root);

        $xml = $this->dom->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Errore durante la generazione del documento XML.');
        }

        return $xml;
    }

    /**
     * Converte il JSON e salva il risultato su file, in modo atomico: in caso di
     * errore un eventuale file preesistente non viene alterato.
     *
     * Rifiuta percorsi vuoti, con byte NUL o con stream wrapper (php://, phar://,
     * ftp://, data: ...), e, se impostata con setBaseDir(), quelli fuori dalla
     * directory base. Un symlink come destinazione viene sostituito, non seguito.
     *
     * @throws InvalidArgumentException se il JSON o il percorso non sono validi
     * @throws RuntimeException se il salvataggio del file fallisce
     */
    public function jsonToXmlFile(string $jsonString, string $fileName, bool $prettyPrint = true): bool
    {
        $path = FileGuard::resolve($fileName, $this->baseDir, false);

        return $this->writeXmlFile($jsonString, $path, $prettyPrint);
    }

    /**
     * Converte e scrive senza validare il percorso: usato anche per i file
     * temporanei interni di jsonToXmlStdOut(), che stanno fuori da baseDir.
     */
    private function writeXmlFile(string $jsonString, string $path, bool $prettyPrint): bool
    {
        FileGuard::writeAtomic($path, $this->jsonToXmlString($jsonString, $prettyPrint));

        return true;
    }

    /**
     * Decodifica il JSON in array, con validazione esplicita degli errori.
     */
    private function decodeJson(string $jsonString): array
    {
        if ($this->maxInputBytes !== null && strlen($jsonString) > $this->maxInputBytes) {
            throw new InvalidArgumentException(
                sprintf('Il JSON supera il limite consentito di %d byte.', $this->maxInputBytes)
            );
        }

        if (trim($jsonString) === '') {
            throw new InvalidArgumentException('La stringa JSON è vuota.');
        }

        $decoded = json_decode($jsonString, true, 512, JSON_BIGINT_AS_STRING);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Errore nella decodifica del JSON: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            $decoded = ['value' => $decoded];
        }

        return $decoded;
    }

    /**
     * Costruisce la processing instruction <?xml-stylesheet type="..." href="..."?>.
     *
     * Usa gli apici doppi di default; se l'href contiene un apice doppio (e non
     * uno singolo, altrimenti setStylesheet() avrebbe già rifiutato il valore),
     * passa agli apici singoli per evitare di generare una PI malformata.
     */
    private function createStylesheetProcessingInstruction(): DOMProcessingInstruction
    {
        $quote = str_contains((string) $this->stylesheetHref, '"') ? "'" : '"';

        $data = sprintf(
            'type=%1$s%2$s%1$s href=%1$s%3$s%1$s',
            $quote,
            $this->stylesheetType,
            $this->stylesheetHref
        );

        // createProcessingInstruction() è tipizzato DOMProcessingInstruction|false nelle stub:
        // stesso pattern di controllo esplicito già usato per saveXML() più sotto.
        $processingInstruction = $this->dom->createProcessingInstruction('xml-stylesheet', $data);
        if ($processingInstruction === false) {
            throw new RuntimeException('Errore durante la creazione della processing instruction xml-stylesheet.');
        }

        return $processingInstruction;
    }

    /**
     * Converte ricorsivamente un array PHP in nodi XML.
     *
     * Le chiavi che iniziano con "@" vengono trattate come attributi del nodo
     * $parentNode stesso (non generano un elemento figlio). Le chiavi speciali
     * "#text" imposta il contenuto testuale diretto di $parentNode
     * (utile per il mixed content: nodo con attributi + testo).
     */
    private function arrayToXml(array $data, DOMElement $parentNode): void
    {
        // Prima passata: attributi, così compaiono nel tag di apertura
        // indipendentemente dall'ordine delle chiavi nel JSON.
        foreach ($data as $key => $value) {
            if ($this->isAttributeKey($key)) {
                $this->appendAttribute($parentNode, (string) $key, $value);
            }
        }

        // Seconda passata: testo diretto, liste ed elementi figli.
        foreach ($data as $key => $value) {
            if ($this->isAttributeKey($key)) {
                continue; // già gestita sopra
            }

            if ($key === self::TEXT_KEY) {
                $this->appendText($parentNode, $value);
                continue;
            }

            // Nel secondo operando dell'||, $key non può essere int (altrimenti
            // l'espressione si sarebbe già fermata al primo operando): è quindi
            // sempre una stringa, senza bisogno di un ulteriore is_string().
            $isNumericKey = is_int($key) || ctype_digit($key);
            $nodeName = $isNumericKey ? $this->itemNodeName : $this->sanitizeTagName((string) $key);

            if (is_array($value)) {
                // Lista JSON (array indicizzato sequenzialmente): ripete il nodo,
                // uno per elemento, senza creare un wrapper intermedio.
                if (array_is_list($value)) {
                    $this->appendList($parentNode, $nodeName, $value);
                    continue;
                }

                // Array associativo: singolo nodo figlio con la ricorsione dentro.
                $child = $this->dom->createElement($nodeName);
                $parentNode->appendChild($child);
                $this->arrayToXml($value, $child);
                continue;
            }

            $this->appendScalarNode($parentNode, $nodeName, $value);
        }
    }

    /**
     * Aggiunge una lista di elementi ripetendo lo stesso nome di nodo, senza wrapper.
     *
     * Esempio: {"Nota": [{...}, {...}]} diventa <Nota>...</Nota><Nota>...</Nota>
     */
    private function appendList(DOMElement $parentNode, string $nodeName, array $items): void
    {
        foreach ($items as $item) {
            $child = $this->dom->createElement($nodeName);
            $parentNode->appendChild($child);

            if (is_array($item)) {
                $this->arrayToXml($item, $child);
                continue;
            }

            if ($item !== null) {
                $this->appendText($child, $item);
            }
        }
    }

    /**
     * Verifica se una chiave rappresenta un attributo (prefisso "@").
     */
    private function isAttributeKey(int|string $key): bool
    {
        return is_string($key) && str_starts_with($key, self::ATTRIBUTE_PREFIX);
    }

    /**
     * Imposta un attributo XML sul nodo, con validazione e sanitizzazione del nome.
     *
     * @throws InvalidArgumentException se il valore dell'attributo è un array
     */
    private function appendAttribute(DOMElement $node, string $key, mixed $value): void
    {
        if (is_array($value)) {
            throw new InvalidArgumentException(
                "L'attributo \"{$key}\" non può avere un array come valore."
            );
        }

        $attributeName = $this->sanitizeTagName(substr($key, strlen(self::ATTRIBUTE_PREFIX)));
        if ($attributeName === '') {
            throw new InvalidArgumentException("Nome di attributo non valido per la chiave \"{$key}\".");
        }

        $node->setAttribute($attributeName, $this->stringifyValue($value));
    }

    /**
     * Aggiunge testo diretto a un nodo, tipicamente usato per il mixed content ("#text")
     * o per i valori scalari di una lista. Il CDATA viene applicato solo se il
     * contenuto lo richiede (stessa logica automatica di appendScalarNode).
     */
    private function appendText(DOMElement $node, mixed $value): void
    {
        if (is_array($value)) {
            throw new InvalidArgumentException('La chiave "#text" non può avere un array come valore.');
        }

        if ($value === null) {
            return;
        }

        $stringValue = $this->stringifyValue($value);

        if ($this->useCdata && $this->needsCdata($stringValue)) {
            $node->appendChild($this->dom->createCDATASection($stringValue));
        } else {
            $node->appendChild($this->dom->createTextNode($stringValue));
        }
    }

    /**
     * Crea un nodo con un valore scalare, usando CDATA quando necessario.
     */
    private function appendScalarNode(DOMElement $parentNode, string $nodeName, mixed $value): void
    {
        $child = $this->dom->createElement($nodeName);
        $parentNode->appendChild($child);

        // Valori null: nodo vuoto (nessun figlio testo/CDATA)
        if ($value === null) {
            return;
        }

        $stringValue = $this->stringifyValue($value);

        if ($this->useCdata && $this->needsCdata($stringValue)) {
            $child->appendChild($this->dom->createCDATASection($stringValue));
        } else {
            $child->appendChild($this->dom->createTextNode($stringValue));
        }
    }

    /**
     * Converte un valore scalare PHP nella sua rappresentazione testuale per l'XML.
     */
    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = (string) $value;
        $this->assertValidXmlChars($string);

        return $string;
    }

    /**
     * XML 1.0 ammette solo #x9, #xA, #xD, #x20-#xD7FF, #xE000-#xFFFD e
     * #x10000-#x10FFFF: gli altri caratteri di controllo non sono validi
     * nemmeno come riferimenti numerici, quindi non si possono "escapare".
     *
     * @throws InvalidArgumentException se il valore contiene caratteri non ammessi
     */
    private function assertValidXmlChars(string $value): void
    {
        // Pattern negato: 1 = trovato un carattere non valido, 0 = tutto ok,
        // false = UTF-8 non valido (trattato come non valido).
        $invalid = preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value);

        if ($invalid !== 0) {
            throw new InvalidArgumentException(
                'Il valore contiene caratteri non ammessi in XML 1.0 (es. caratteri di controllo).'
            );
        }
    }

    /**
     * Determina se un valore necessita di CDATA (caratteri speciali XML o newline).
     */
    private function needsCdata(string $value): bool
    {
        return (bool) preg_match('/[<>&\'"]/', $value) || str_contains($value, "\n");
    }

    /**
     * Sanitizza una chiave per renderla un nome di tag XML valido:
     *  - sostituisce i caratteri non ammessi con "_"
     *  - un tag non può iniziare con un numero, un punto o un trattino
     *  - un tag non può iniziare con "xml" (riservato dalle specifiche XML)
     */
    private function sanitizeTagName(string $key): string
    {
        // preg_replace() ha una firma string|array|null: con un soggetto stringa
        // restituisce string|null, e torna null solo in caso di errore di regex
        // (es. backtrack limit superato su input patologici). Senza il fallback,
        // quel null si propagherebbe fino al return type "string" del metodo.
        $key = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $key) ?? '';

        if ($key === '' || preg_match('/^[0-9\-\.]/', $key)) {
            $key = 'n_' . $key;
        }

        if (preg_match('/^xml/i', $key)) {
            $key = '_' . $key;
        }

        return $key;
    }

    private static function log(string $message): void
    {
        error_log(date('Y-m-d H:i:s: ') . rtrim($message) . "\n");
    }
}
