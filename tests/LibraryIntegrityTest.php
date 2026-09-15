<?php

declare(strict_types=1);

namespace A35G\JsonToXml\Tests;

use A35G\JsonToXml\JsonToXmlConverter;
use A35G\JsonToXml\XmlToJsonConverter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * LibraryIntegrityTest
 *
 * Test "trasversali" pensati per verificare lo status e l'integrità della
 * libreria oltre alla semplice correttezza unit-per-unit già coperta da
 * JsonToXmlConverterTest e XmlToJsonConverterTest:
 *
 *  - coerenza strutturale del progetto (composer.json / phpunit.xml)
 *  - round-trip JSON -> XML -> JSON
 *  - sicurezza (protezione XXE)
 *  - gestione dei file temporanei (nessun leak, anche in caso di eccezione)
 *  - sanitizzazione dei nomi di tag/attributi su casi limite
 *  - comportamenti "di frontiera" non ovvi del formato (array vuoti, chiavi
 *    numeriche non sequenziali, ecc.), utili come rete di sicurezza contro
 *    regressioni silenziose.
 */
final class LibraryIntegrityTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/..';

    // ------------------------------------------------------------------
    // Integrità strutturale del progetto
    // ------------------------------------------------------------------

    public function testComposerJsonIsValidAndDeclaresRequiredExtensions(): void
    {
        $path = self::PROJECT_ROOT . '/composer.json';
        $this->assertFileExists($path, 'composer.json mancante nella root del progetto.');

        $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('a35g/json-to-xml', $composer['name']);
        $this->assertSame('library', $composer['type']);
        $this->assertArrayHasKey('ext-dom', $composer['require']);
        $this->assertArrayHasKey('ext-json', $composer['require']);
        $this->assertArrayHasKey('psr-4', $composer['autoload']);
        $this->assertArrayHasKey('A35G\\JsonToXml\\', $composer['autoload']['psr-4']);
    }

    public function testPsr4AutoloadPathMatchesActualClassLocation(): void
    {
        // Se composer.json dichiara che il namespace A35G\JsonToXml\ vive in
        // "src/", ma le classi sono effettivamente altrove (es. root del
        // repo), l'autoload PSR-4 fallirebbe in un progetto reale che
        // consuma la libreria via `composer require`.
        $composer = json_decode(
            (string) file_get_contents(self::PROJECT_ROOT . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $declaredPath = rtrim($composer['autoload']['psr-4']['A35G\\JsonToXml\\'], '/');
        $expectedFile = self::PROJECT_ROOT . '/' . $declaredPath . '/JsonToXmlConverter.php';

        $this->assertFileExists(
            $expectedFile,
            "composer.json dichiara il namespace principale in \"{$declaredPath}/\" ma JsonToXmlConverter.php non si trova lì. " .
            'Verificare che i sorgenti siano stati spostati in quella cartella prima della pubblicazione del pacchetto.'
        );
    }

    public function testBothConverterClassesAreLoadableAndInstantiable(): void
    {
        $this->assertTrue(class_exists(JsonToXmlConverter::class));
        $this->assertTrue(class_exists(XmlToJsonConverter::class));

        $jsonToXml = new JsonToXmlConverter('root');
        $xmlToJson = new XmlToJsonConverter();

        $this->assertInstanceOf(JsonToXmlConverter::class, $jsonToXml);
        $this->assertInstanceOf(XmlToJsonConverter::class, $xmlToJson);
    }

    // ------------------------------------------------------------------
    // Round-trip JSON -> XML -> JSON
    // ------------------------------------------------------------------

    public function testRoundTripPreservesObjectStructureAndAttributes(): void
    {
        $originalJson = '{"request":{"@code":"","@typeReq":"ABC","cliente":"Mario Rossi"}}';

        $toXml = new JsonToXmlConverter('root');
        $xml = $toXml->jsonToXmlString($originalJson);

        $toJson = new XmlToJsonConverter();
        $roundTripped = $toJson->xmlToArray($xml);

        $this->assertSame('', $roundTripped['request']['@code']);
        $this->assertSame('ABC', $roundTripped['request']['@typeReq']);
        $this->assertSame('Mario Rossi', $roundTripped['request']['cliente']);
    }

    public function testRoundTripWithForceArrayTagsPreservesListShapeEvenWithOneElement(): void
    {
        $originalJson = '{"Note":{"Nota":[{"Principale":"0"}]}}';

        $toXml = new JsonToXmlConverter('root');
        $xml = $toXml->jsonToXmlString($originalJson);

        // Senza forceArrayTags il singolo elemento tornerebbe un oggetto, non una lista.
        $withoutForce = (new XmlToJsonConverter())->xmlToArray($xml);
        $this->assertArrayNotHasKey(0, $withoutForce['Note']['Nota']);

        // Con forceArrayTags la forma "lista" viene preservata anche con un solo elemento.
        $withForce = (new XmlToJsonConverter(forceArrayTags: ['Nota']))->xmlToArray($xml);
        $this->assertIsList($withForce['Note']['Nota']);
        $this->assertCount(1, $withForce['Note']['Nota']);
        $this->assertSame('0', $withForce['Note']['Nota'][0]['Principale']);
    }

    public function testRoundTripOfTopLevelScalarListChangesShapeAsDocumented(): void
    {
        // Una lista JSON di soli scalari a livello radice viene ripetuta come
        // <item>...</item>, ma tornando a JSON diventa un oggetto con chiave
        // "item" -> lista, non più una lista "nuda". Comportamento atteso e
        // già segnalato nel README ("non è un round-trip perfetto"): questo
        // test lo fissa esplicitamente per evitare regressioni silenziose.
        $toXml = new JsonToXmlConverter('data', 'item');
        $xml = $toXml->jsonToXmlString('["a","b"]');

        $this->assertSame(2, substr_count($xml, '<item>'));

        $roundTripped = (new XmlToJsonConverter())->xmlToArray($xml);
        $this->assertSame(['item' => ['a', 'b']], $roundTripped);
    }

    // ------------------------------------------------------------------
    // Sicurezza: protezione da XXE
    // ------------------------------------------------------------------

    public function testExternalEntityIsNotResolvedIntoNodeText(): void
    {
        $secretFile = tempnam(sys_get_temp_dir(), 'xxe_secret_');
        file_put_contents($secretFile, 'CONTENUTO_SEGRETO_NON_DEVE_COMPARIRE');

        $maliciousXml = '<?xml version="1.0"?>'
            . '<!DOCTYPE data [<!ENTITY xxe SYSTEM "file://' . $secretFile . '">]>'
            . '<data><nome>&xxe;</nome></data>';

        try {
            $converter = new XmlToJsonConverter();
            $array = $converter->xmlToArray($maliciousXml);

            $flat = json_encode($array);
            $this->assertStringNotContainsString(
                'CONTENUTO_SEGRETO_NON_DEVE_COMPARIRE',
                (string) $flat,
                'Il contenuto del file esterno referenziato via ENTITY non deve mai comparire nel risultato.'
            );
        } finally {
            @unlink($secretFile);
        }
    }

    public function testExternalEntityOverNetworkIsBlockedByLibxmlNonet(): void
    {
        // Riferimento a un URL: con LIBXML_NONET il parser non deve tentare
        // alcuna richiesta di rete né restituire un contenuto remoto.
        $maliciousXml = '<?xml version="1.0"?>'
            . '<!DOCTYPE data [<!ENTITY xxe SYSTEM "http://169.254.169.254/latest/meta-data/">]>'
            . '<data><nome>&xxe;</nome></data>';

        $converter = new XmlToJsonConverter();
        $array = $converter->xmlToArray($maliciousXml);

        $this->assertStringNotContainsString('ami-id', (string) json_encode($array));
    }

    // ------------------------------------------------------------------
    // Gestione dei file temporanei (no leak)
    // ------------------------------------------------------------------

    public function testStdOutDoesNotLeaveTemporaryFilesOnSuccess(): void
    {
        $before = glob(sys_get_temp_dir() . '/xml_writer_*') ?: [];

        $converter = new JsonToXmlConverter('data');
        ob_start();
        $converter->jsonToXmlStdOut('{"nome":"Mario"}');
        ob_end_clean();

        $after = glob(sys_get_temp_dir() . '/xml_writer_*') ?: [];

        $this->assertCount(count($before), $after, 'jsonToXmlStdOut ha lasciato un file temporaneo orfano su disco.');
    }

    public function testStdOutDoesNotLeaveTemporaryFilesEvenWhenJsonIsInvalid(): void
    {
        $before = glob(sys_get_temp_dir() . '/xml_writer_*') ?: [];

        $converter = new JsonToXmlConverter('data');

        try {
            $converter->jsonToXmlStdOut('{json non valido}');
            $this->fail('Ci si aspettava una InvalidArgumentException per JSON non valido.');
        } catch (InvalidArgumentException) {
            // atteso
        }

        $after = glob(sys_get_temp_dir() . '/xml_writer_*') ?: [];

        $this->assertCount(
            count($before),
            $after,
            'Il file temporaneo non è stato ripulito nel blocco finally quando la conversione fallisce.'
        );
    }

    public function testCustomTempDirIsActuallyUsedForTemporaryFiles(): void
    {
        $customDir = sys_get_temp_dir() . '/json_to_xml_custom_' . uniqid();
        mkdir($customDir);

        try {
            $converter = new JsonToXmlConverter('data');
            $converter->setTempDir($customDir);

            $reflection = new ReflectionClass($converter);
            $method = $reflection->getMethod('tempFilename');
            $method->setAccessible(true);

            /** @var string $tempFile */
            $tempFile = $method->invoke($converter);

            try {
                // realpath() normalizza sia i symlink (es. su macOS /var è un
                // symlink verso /private/var) sia i separatori di percorso
                // (Windows usa "\" invece di "/"): un confronto di stringa
                // esatto tra $customDir e dirname($tempFile) fallirebbe pur
                // trattandosi effettivamente della stessa directory.
                $this->assertSame(
                    realpath($customDir),
                    realpath(dirname($tempFile)),
                    'Il file temporaneo non è stato creato nella directory impostata con setTempDir().'
                );
            } finally {
                @unlink($tempFile);
            }
        } finally {
            @rmdir($customDir);
        }
    }

    public function testTempFilenameThrowsWhenTempDirIsNotWritable(): void
    {
        // Su Windows (NTFS) il parametro $mode di mkdir() non applica permessi
        // POSIX in stile Unix: la directory risulterebbe comunque scrivibile
        // e il test darebbe un falso negativo, non a causa di un bug della
        // libreria ma di una limitazione della piattaforma nel simulare
        // questo scenario. Il comportamento della libreria resta verificato
        // su Linux/macOS, dove il test è affidabile.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped(
                'mkdir() con permessi non scrivibili non è simulabile in modo affidabile su Windows.'
            );
        }

        $unwritableDir = sys_get_temp_dir() . '/json_to_xml_unwritable_' . uniqid();
        mkdir($unwritableDir, 0400);

        try {
            $converter = new JsonToXmlConverter('data');
            $converter->setTempDir($unwritableDir);

            $reflection = new ReflectionClass($converter);
            $method = $reflection->getMethod('tempFilename');
            $method->setAccessible(true);

            $this->expectException(\RuntimeException::class);
            $method->invoke($converter);
        } finally {
            @chmod($unwritableDir, 0700);
            @rmdir($unwritableDir);
        }
    }

    // ------------------------------------------------------------------
    // Sanitizzazione nomi di tag/attributi: casi limite
    // ------------------------------------------------------------------

    /**
     * @dataProvider tagNameEdgeCasesProvider
     */
    public function testSanitizeTagNameHandlesEdgeCases(string $input, string $expected): void
    {
        $converter = new JsonToXmlConverter('data');

        $reflection = new ReflectionClass($converter);
        $method = $reflection->getMethod('sanitizeTagName');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($converter, $input));
    }

    public static function tagNameEdgeCasesProvider(): array
    {
        return [
            'inizia con una cifra' => ['1campo', 'n_1campo'],
            'inizia con un trattino' => ['-campo', 'n_-campo'],
            'inizia con un punto' => ['.campo', 'n_.campo'],
            'chiave vuota' => ['', 'n_'],
            'caratteri non ammessi sostituiti' => ['campo con spazi/slash', 'campo_con_spazi_slash'],
            'prefisso xml riservato (minuscolo)' => ['xmlns', '_xmlns'],
            'prefisso xml riservato (maiuscolo)' => ['XMLData', '_XMLData'],
            'nome già valido resta invariato' => ['nomeValido_1', 'nomeValido_1'],
        ];
    }

    public function testAttributeKeyWithInvalidCharactersIsSanitizedNotDropped(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"nodo": {"@1strano nome!": "valore"}}');

        $this->assertMatchesRegularExpression('/<nodo\s+n_1strano_nome_="valore"\s*\/>/', $xml);
    }

    // ------------------------------------------------------------------
    // Comportamenti di frontiera del formato (non regressioni silenziose)
    // ------------------------------------------------------------------

    public function testEmptyJsonArrayProducesNoElementAtAll(): void
    {
        // array_is_list([]) === true, quindi la chiave con valore [] viene
        // trattata come "lista vuota": zero elementi ripetuti, e quindi
        // nessun nodo <tags> viene creato nel documento.
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"prima":"x", "tags": [], "dopo":"y"}');

        $this->assertStringNotContainsString('<tags', $xml);
        $this->assertStringContainsString('<prima>x</prima>', $xml);
        $this->assertStringContainsString('<dopo>y</dopo>', $xml);
    }

    public function testBooleanValuesAreStringifiedAsTrueFalse(): void
    {
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('{"attivo": true, "eliminato": false}');

        $this->assertStringContainsString('<attivo>true</attivo>', $xml);
        $this->assertStringContainsString('<eliminato>false</eliminato>', $xml);
    }

    public function testNonSequentialNumericKeysFallBackToItemNodeName(): void
    {
        // Un oggetto JSON con chiavi numeriche non sequenziali non è una
        // "lista" per array_is_list(): il valore viene comunque incapsulato
        // in un unico nodo <items>, e al suo interno i figli con chiave
        // numerica usano itemNodeName invece del nome della chiave originale.
        $converter = new JsonToXmlConverter('data', 'item');
        $xml = $converter->jsonToXmlString('{"items": {"0": {"a": "1"}, "2": {"a": "2"}}}');

        $this->assertSame(1, substr_count($xml, '<items>'));
        $this->assertSame(2, substr_count($xml, '<item>'));
        $this->assertStringContainsString('<a>1</a>', $xml);
        $this->assertStringContainsString('<a>2</a>', $xml);
    }

    public function testTopLevelNonArrayJsonValueIsWrappedUnderValueKey(): void
    {
        // decodeJson() avvolge gli scalari radice in ['value' => ...] prima
        // di passarli ad arrayToXml().
        $converter = new JsonToXmlConverter('data');
        $xml = $converter->jsonToXmlString('42');

        $this->assertStringContainsString('<value>42</value>', $xml);
    }

    public function testJsonToXmlFileWritesExpectedXml(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'json_to_xml_');

        try {
            $converter = new JsonToXmlConverter('data');

            $json = '{"nome": "Mario", "eta": 30}';

            $converter->jsonToXmlFile($json, $outputFile);

            $this->assertFileExists($outputFile);

            $xml = file_get_contents($outputFile);

            $this->assertIsString($xml);
            $this->assertStringContainsString('<nome>Mario</nome>', $xml);
            $this->assertStringContainsString('<eta>30</eta>', $xml);
        } finally {
            @unlink($outputFile);
        }
    }

    public function testXmlFileToJsonFileWritesExpectedJson(): void
    {
        $inputFile = tempnam(sys_get_temp_dir(), 'xml_input_');
        $outputFile = tempnam(sys_get_temp_dir(), 'xml_output_');

        try {
            file_put_contents($inputFile, '<root><nome>Mario</nome><eta>30</eta></root>');

            $converter = new XmlToJsonConverter();

            $converter->xmlFileToJsonFile($inputFile, $outputFile);

            $this->assertFileExists($outputFile);

            $json = file_get_contents($outputFile);

            $this->assertIsString($json);

            $decoded = json_decode($json, true);

            $this->assertSame('Mario', $decoded['nome']);

            $this->assertSame('30', $decoded['eta']);
        } finally {
            @unlink($inputFile);
            @unlink($outputFile);
        }
    }
}
