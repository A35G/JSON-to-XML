<?php

declare(strict_types=1);

namespace A35G\JsonToXml;

use DOMDocument;
use DOMElement;
use DOMText;
use InvalidArgumentException;
use RuntimeException;

/**
 * XmlToJsonConverter
 *
 * Converte un documento XML in un array PHP (o in una stringa JSON), usando
 * le stesse convenzioni di JsonToXmlConverter:
 *
 *  - un attributo XML diventa una chiave "@nome" => valore
 *  - il testo diretto di un nodo con figli e/o attributi (mixed content)
 *    diventa la chiave "#text" => valore
 *  - elementi figli con lo stesso nome, ripetuti come fratelli, diventano
 *    un array di valori sotto quella chiave
 *  - un elemento completamente vuoto (senza attributi, figli o testo)
 *    diventa null
 *
 * NOTA IMPORTANTE: questa conversione NON è un inverso perfetto di
 * JsonToXmlConverter. In particolare:
 *  - un elemento che compare una sola volta viene reso come oggetto singolo,
 *    non come array di un elemento — usa $forceArrayTags per i tag che nel
 *    tuo dominio devono essere SEMPRE trattati come liste, anche con un
 *    solo elemento, per evitare che il JSON cambi forma se in futuro
 *    quell'elemento inizia a ripetersi;
 *  - CDATA e testo normale producono lo stesso valore in JSON (l'informazione
 *    "era in CDATA" non è recuperabile);
 *  - commenti e processing instruction vengono ignorati.
 */
class XmlToJsonConverter
{
    /** Identifica una chiave come attributo XML (stessa convenzione di JsonToXmlConverter) */
    private const ATTRIBUTE_PREFIX = '@';

    /** Chiave per il testo diretto di un nodo con figli/attributi (mixed content) */
    private const TEXT_KEY = '#text';

    /**
     * @var string[] Nomi di tag (senza namespace) da trattare sempre come array,
     *               anche quando compaiono una sola volta come figlio.
     */
    private array $forceArrayTags;
    private ?int $maxInputBytes;
    private bool $allowDoctype;

    /**
     * @param string[] $forceArrayTags Tag da trattare sempre come lista (vedi sopra).
     * @param int|null $maxInputBytes  Dimensione massima (in byte) dell'XML accettato; null = nessun limite.
     * @param bool     $allowDoctype   Se false, qualsiasi XML con una dichiarazione DOCTYPE viene
     *                                 rifiutato prima del parsing (e le codifiche con byte NUL,
     *                                 come UTF-16, non sono accettate). Consigliato per input
     *                                 non fidato. Default true per retrocompatibilità.
     */
    public function __construct(
        array $forceArrayTags = [],
        ?int $maxInputBytes = null,
        bool $allowDoctype = true
    ) {
        $this->forceArrayTags = $forceArrayTags;
        $this->maxInputBytes = self::assertValidLimit($maxInputBytes);
        $this->allowDoctype = $allowDoctype;
    }

    public function setAllowDoctype(bool $allowDoctype): void
    {
        $this->allowDoctype = $allowDoctype;
    }

    /**
     * @throws InvalidArgumentException se il limite non è un intero positivo valido
     */
    public function setMaxInputBytes(?int $maxInputBytes): void
    {
        $this->maxInputBytes = self::assertValidLimit($maxInputBytes);
    }

    private static function assertValidLimit(?int $limit): ?int
    {
        if ($limit !== null && ($limit < 1 || $limit > PHP_INT_MAX - 1)) {
            throw new InvalidArgumentException('maxInputBytes deve essere un intero >= 1 oppure null.');
        }

        return $limit;
    }

    /**
     * @param string[] $forceArrayTags
     */
    public function setForceArrayTags(array $forceArrayTags): void
    {
        $this->forceArrayTags = $forceArrayTags;
    }

    /**
     * Converte una stringa XML in un array PHP associativo.
     *
     * L'array restituito rappresenta il CONTENUTO dell'elemento radice
     * (attributi e figli), non include il nome del tag radice — simmetrico
     * a come JsonToXmlConverter riceve $rootName separatamente dai dati.
     *
     * @throws InvalidArgumentException se l'XML non è valido o è vuoto
     */
    public function xmlToArray(string $xmlString): array
    {
        $dom = $this->loadXml($xmlString);

        $root = $dom->documentElement;
        if ($root === null) {
            throw new InvalidArgumentException('Il documento XML non contiene un elemento radice.');
        }

        $value = $this->elementToValue($root);

        // Un elemento radice completamente vuoto o solo testuale produrrebbe
        // uno scalare: normalizziamo comunque in array per coerenza con json_decode(..., true).
        return is_array($value) ? $value : [self::TEXT_KEY => $value];
    }

    /**
     * Converte una stringa XML in una stringa JSON.
     *
     * @throws InvalidArgumentException se l'XML non è valido
     * @throws RuntimeException se la codifica in JSON fallisce
     */
    public function xmlToJsonString(
        string $xmlString,
        int $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ): string {
        $array = $this->xmlToArray($xmlString);

        $json = json_encode($array, $jsonFlags);
        if ($json === false) {
            throw new RuntimeException('Errore durante la codifica in JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Legge un file XML e salva il JSON corrispondente su file.
     *
     * @throws InvalidArgumentException se il file XML non è leggibile o non è valido
     * @throws RuntimeException se il salvataggio del file JSON fallisce
     */
    public function xmlFileToJsonFile(
        string $xmlFilePath,
        string $jsonFilePath,
        int $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ): bool {
        if (!is_readable($xmlFilePath)) {
            throw new InvalidArgumentException("Impossibile leggere il file XML: {$xmlFilePath}");
        }

        // Lettura limitata a max+1 byte: basta per accorgersi dello sforamento
        // (lo verifica loadXml()) senza caricare in memoria file enormi o
        // stream infiniti (/dev/zero, FIFO), dove filesize() non è affidabile.
        if ($this->maxInputBytes === null) {
            $xmlString = file_get_contents($xmlFilePath);
        } else {
            // Il limite è già validato (1 <= limite <= PHP_INT_MAX - 1) da
            // assertValidLimit(): "+ 1" non è mai negativo né va in overflow.
            /** @var int<0, max> $length */
            $length = $this->maxInputBytes + 1;
            $xmlString = file_get_contents($xmlFilePath, false, null, 0, $length);
        }

        if ($xmlString === false) {
            throw new InvalidArgumentException("Errore durante la lettura del file XML: {$xmlFilePath}");
        }

        $jsonString = $this->xmlToJsonString($xmlString, $jsonFlags);

        $bytesWritten = file_put_contents($jsonFilePath, $jsonString);
        if ($bytesWritten === false) {
            throw new RuntimeException("Errore durante il salvataggio del file: {$jsonFilePath}");
        }

        return true;
    }

    /**
     * Carica una stringa XML in un DOMDocument, con gestione esplicita degli errori
     * e protezione dagli attacchi XXE (nessun accesso di rete, nessuna entità esterna).
     *
     * @throws InvalidArgumentException se la stringa è vuota o l'XML non è valido
     */
    private function loadXml(string $xmlString): DOMDocument
    {
        if ($this->maxInputBytes !== null && strlen($xmlString) > $this->maxInputBytes) {
            throw new InvalidArgumentException(
                sprintf('L\'XML supera il limite consentito di %d byte.', $this->maxInputBytes)
            );
        }

        if (trim($xmlString) === '') {
            throw new InvalidArgumentException('La stringa XML è vuota.');
        }

        if (!$this->allowDoctype) {
            $this->assertNoDoctype($xmlString);
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false; // ignora l'indentazione tra i tag

        $previousSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // FLAG DI PARSING: non modificare senza leggere SECURITY.md.
        //
        //  - LIBXML_NONET blocca solo l'accesso di rete (http://, ftp://). NON blocca
        //    file://: la lettura di file locali via entità esterne è impedita
        //    dall'assenza di LIBXML_NOENT, non da NONET.
        //  - NON aggiungere LIBXML_NOENT: sostituirebbe le entità nel DOM, riaprendo
        //    XXE (file://) e l'espansione billion laughs / quadratic blowup.
        //  - NON aggiungere LIBXML_DTDLOAD / LIBXML_DTDATTR / LIBXML_DTDVALID: caricano
        //    il DTD esterno e applicano i suoi attributi di default.
        //  - NON aggiungere LIBXML_PARSEHUGE: disattiva i limiti di libxml2 su
        //    profondità (256) e dimensione dei nodi di testo (10 MB), che oggi
        //    proteggono anche la ricorsione di elementToValue()
        $loaded = $dom->loadXML($xmlString, LIBXML_NONET);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if (!$loaded) {
            $messages = array_map(static fn ($error) => trim($error->message), $errors);
            $detail = implode('; ', array_filter($messages));

            throw new InvalidArgumentException(
                'XML non valido' . ($detail !== '' ? ": {$detail}" : '.')
            );
        }

        if (!$this->allowDoctype && $dom->doctype !== null) {
            throw new InvalidArgumentException('DOCTYPE non consentito.');
        }

        return $dom;
    }

    /**
     * Rifiuta il documento prima del parsing se potrebbe contenere un DOCTYPE.
     *
     * Il controllo è volutamente conservativo: un "<!DOCTYPE" dentro un commento
     * o un CDATA viene rifiutato anch'esso. I byte NUL indicano UTF-16/32, dove
     * la ricerca testuale non funzionerebbe: in modalità restrittiva sono rifiutati.
     * Il controllo su $dom->doctype in loadXml() resta come seconda barriera.
     *
     * @throws InvalidArgumentException
     */
    private function assertNoDoctype(string $xmlString): void
    {
        if (str_contains($xmlString, "\0")) {
            throw new InvalidArgumentException(
                'Codifica non supportata con DOCTYPE disattivato (byte NUL: UTF-16/32?).'
            );
        }

        if (str_contains($xmlString, '<!DOCTYPE')) {
            throw new InvalidArgumentException('DOCTYPE non consentito.');
        }
    }

    /**
     * Converte ricorsivamente un DOMElement nel suo equivalente array/scalare/null,
     * seguendo le convenzioni "@attributo" / "#text" / liste per elementi ripetuti.
     */
    private function elementToValue(DOMElement $element): array|string|null
    {
        $result = [];

        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attribute) {
                // Un riferimento a entità definita nel DOCTYPE, dentro un
                // attributo, viene espanso solo alla lettura di ->value, con
                // costo quadratico: loadXML() non lo intercetta. Le entità
                // predefinite (&amp; &lt; ...) sono già testo e non arrivano qui.
                foreach ($attribute->childNodes as $child) {
                    if ($child instanceof \DOMEntityReference) {
                        throw new InvalidArgumentException(
                            "L'attributo \"{$attribute->nodeName}\" contiene un riferimento a entità: non supportato."
                        );
                    }
                }

                /** @var \DOMAttr $attribute */
                $result[self::ATTRIBUTE_PREFIX . $attribute->name] = $attribute->value;
            }
        }

        $childElements = [];
        $textParts = [];

        foreach ($element->childNodes as $node) {
            // Riferimento a un'entità dichiarata nel DOCTYPE: finora veniva
            // scartato in silenzio (non è né DOMElement né DOMText), con
            // perdita di dati. Meglio un errore esplicito, coerente con il
            // rifiuto già applicato agli attributi.
            if ($node instanceof \DOMEntityReference) {
                throw new InvalidArgumentException(
                    "L'elemento \"{$element->nodeName}\" contiene un riferimento a entità: non supportato."
                );
            }

            if ($node instanceof DOMElement) {
                $childElements[] = $node;
                continue;
            }

            // DOMCdataSection estende DOMText, quindi questo controllo intercetta
            // sia il testo normale che le sezioni CDATA senza bisogno di un
            // secondo instanceof esplicito.
            if ($node instanceof DOMText) {
                $textParts[] = $node->nodeValue;
            }

            // Commenti e processing instruction vengono ignorati intenzionalmente.
        }

        $textContent = implode('', $textParts);
        $hasMeaningfulText = trim($textContent) !== '';

        // Nodo foglia: nessun figlio elemento.
        if ($childElements === []) {
            if ($result === []) {
                // Nessun attributo: valore scalare diretto, o null se completamente vuoto.
                return $hasMeaningfulText ? $textContent : null;
            }

            // Ha attributi: il testo (se presente) va sotto "#text".
            if ($hasMeaningfulText) {
                $result[self::TEXT_KEY] = $textContent;
            }

            return $result;
        }

        // Ha figli: eventuale testo diretto è mixed content -> "#text".
        if ($hasMeaningfulText) {
            $result[self::TEXT_KEY] = $textContent;
        }

        // Raggruppa i figli per nome di tag, preservando l'ordine di prima comparsa.
        $grouped = [];
        foreach ($childElements as $child) {
            $grouped[$child->nodeName][] = $this->elementToValue($child);
        }

        foreach ($grouped as $tagName => $values) {
            $isRepeated = count($values) > 1;
            $isForcedArray = in_array($tagName, $this->forceArrayTags, true);

            $result[$tagName] = ($isRepeated || $isForcedArray) ? $values : $values[0];
        }

        return $result;
    }
}
