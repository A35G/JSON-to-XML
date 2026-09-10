<?php

declare(strict_types=1);

namespace A35G\JsonToXml;

use DOMDocument;
use DOMElement;
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

    protected ?string $tempdir = null;

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
     */
    public function __construct(string $rootName = 'data', string $itemNodeName = 'item', bool $useCdata = true)
    {
        $this->rootName     = $rootName;
        $this->itemNodeName = $this->sanitizeTagName($itemNodeName);
        $this->useCdata     = $useCdata;

        if (!$this->isTempDirWritable()) {
            self::log('Warning: tempdir ' . $this->resolveTempDir() . ' non scrivibile, usa ->setTempDir()');
        }
    }

    public function setTempDir(?string $tempdir = null): void
    {
        $this->tempdir = $tempdir;
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
            throw new RuntimeException('Impossibile creare un file temporaneo: controlla i limiti di file handle o i permessi della directory.');
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
            $this->jsonToXmlFile($jsonString, $tempFile);
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
     * Converte il JSON e salva direttamente il risultato su file.
     *
     * @throws InvalidArgumentException se il JSON non è valido
     * @throws RuntimeException se il salvataggio del file fallisce
     */
    public function jsonToXmlFile(string $jsonString, string $fileName, bool $prettyPrint = true): bool
    {
        $xmlString = $this->jsonToXmlString($jsonString, $prettyPrint);

        $bytesWritten = file_put_contents($fileName, $xmlString);
        if ($bytesWritten === false) {
            throw new RuntimeException("Errore durante il salvataggio del file: {$fileName}");
        }

        return true;
    }

    /**
     * Decodifica il JSON in array, con validazione esplicita degli errori.
     */
    private function decodeJson(string $jsonString): array
    {
        if (trim($jsonString) === '') {
            throw new InvalidArgumentException('La stringa JSON è vuota.');
        }

        $decoded = json_decode($jsonString, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Errore nella decodifica del JSON: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            $decoded = ['value' => $decoded];
        }

        return $decoded;
    }

    /**
     * Converte ricorsivamente un array PHP in nodi XML.
     *
     * Le chiavi che iniziano con "@" vengono trattate come attributi del nodo
     * $parentNode stesso (non generano un elemento figlio). Le chiavi speciali
     * "#text" e "#cdata" impostano il contenuto testuale diretto di $parentNode
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

        // Seconda passata: testo diretto, CDATA forzato, liste ed elementi figli.
        foreach ($data as $key => $value) {
            if ($this->isAttributeKey($key)) {
                continue; // già gestita sopra
            }

            if ($key === self::TEXT_KEY) {
                $this->appendText($parentNode, $value);
                continue;
            }

            $isNumericKey = is_int($key) || (is_string($key) && ctype_digit($key));
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

        return (string) $value;
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
        $key = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $key);

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
