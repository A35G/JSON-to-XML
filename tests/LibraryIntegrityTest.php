<?php

declare(strict_types=1);

namespace A35G\JsonToXml\Tests;

use DOMDocument;
use RuntimeException;
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

            try {
                $array = $converter->xmlToArray($maliciousXml);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1); // rifiuto esplicito: accettabile
                return;
            }

            $flat = json_encode($array);
            $this->assertStringNotContainsString('CONTENUTO_SEGRETO_NON_DEVE_COMPARIRE', (string) $flat, 'Il contenuto del file esterno referenziato via ENTITY non deve mai comparire nel risultato.');
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

        try {
            $array = $converter->xmlToArray($maliciousXml);
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1); // rifiuto esplicito: accettabile
            return;
        }

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

        $this->assertCount(count($before), $after, 'Il file temporaneo non è stato ripulito nel blocco finally quando la conversione fallisce.');
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
                $this->assertSame(realpath($customDir), realpath(dirname($tempFile)), 'Il file temporaneo non è stato creato nella directory impostata con setTempDir().');
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
            $this->markTestSkipped('mkdir() con permessi non scrivibili non è simulabile in modo affidabile su Windows.');
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

    // ------------------------------------------------------------------
    // Sicurezza: Billion Laughs / espansione ricorsiva di entità
    // ------------------------------------------------------------------

    /**
     * Costruisce un classico payload "Billion Laughs": $levels livelli di
     * entità, ognuna composta da $fanout riferimenti al livello precedente.
     * Espansione teorica: $fanout ** $levels volte la stringa base.
     */
    public static function setUpBeforeClass(): void
    {
        fwrite(STDERR, 'libxml2 ' . LIBXML_DOTTED_VERSION . PHP_EOL);
    }

    private static function billionLaughsXml(int $levels, int $fanout): string
    {
        $entities = '<!ENTITY lol0 "lol">';
        for ($i = 1; $i <= $levels; $i++) {
            $refs = str_repeat('&lol' . ($i - 1) . ';', $fanout);
            $entities .= '<!ENTITY lol' . $i . ' "' . $refs . '">';
        }

        return '<?xml version="1.0"?>'
            . '<!DOCTYPE lolz [' . $entities . ']>'
            . '<lolz>&lol' . $levels . ';</lolz>';
    }

    /**
     * Esegue codice PHP in un processo separato con memory_limit ridotto e
     * timeout, così un'eventuale espansione fuori controllo non può
     * abbattere (o bloccare) l'intera suite PHPUnit.
     *
     * Lo script viene scritto su file e stdin/stdout/stderr passano da file
     * temporanei: niente quoting di "php -r" e niente pipe non bloccanti,
     * entrambi inaffidabili su Windows.
     *
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, elapsed: float}
     */
    private function runPhpSubprocess(string $code, string $stdin, int $timeoutSeconds = 15): array
    {
        $tmp = sys_get_temp_dir();
        $scriptFile = (string) tempnam($tmp, 'xxe_script_');
        $inFile = (string) tempnam($tmp, 'xxe_in_');
        $outFile = (string) tempnam($tmp, 'xxe_out_');
        $errFile = (string) tempnam($tmp, 'xxe_err_');

        try {
            file_put_contents($scriptFile, "<?php\n" . $code);
            file_put_contents($inFile, $stdin);

            $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=256M ' . escapeshellarg($scriptFile);

            $process = proc_open($cmd, [
                0 => ['file', $inFile, 'r'],
                1 => ['file', $outFile, 'w'],
                2 => ['file', $errFile, 'w'],
            ], $pipes);
            $this->assertIsResource($process, 'Impossibile avviare il sottoprocesso PHP.');

            $timedOut = false;
            $exitCode = -1;
            $start = microtime(true);

            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    // L'exit code è valido solo alla prima lettura dopo la
                    // terminazione: va salvato subito, proc_close() poi
                    // restituirebbe -1.
                    $exitCode = $status['exitcode'];
                    break;
                }

                if (microtime(true) - $start > $timeoutSeconds) {
                    $timedOut = true;
                    proc_terminate($process);
                    break;
                }

                usleep(20000);
            }

            proc_close($process);

            return [
                'exitCode' => $exitCode,
                'stdout' => (string) file_get_contents($outFile),
                'stderr' => (string) file_get_contents($errFile),
                'timedOut' => $timedOut,
                'elapsed' => microtime(true) - $start,
            ];
        } finally {
            foreach ([$scriptFile, $inFile, $outFile, $errFile] as $file) {
                @unlink($file);
            }
        }
    }

    public function testSmallEntityExpansionIsNotExpandedIntoOutput(): void
    {
        // Payload "innocuo" (3 livelli x 10 = ~3 KB se espanso): non deve
        // saturare nulla, quindi qui possiamo testare in-process che le
        // entità interne NON vengano sostituite nel risultato (nessun
        // LIBXML_NOENT). Se libxml2 lo rifiuta comunque, è un esito valido.
        $converter = new XmlToJsonConverter();

        try {
            $array = $converter->xmlToArray(self::billionLaughsXml(3, 10));
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1); // rifiuto esplicito: accettabile
            return;
        }

        $this->assertStringNotContainsString('lol', (string) json_encode($array));
    }

    /**
     * @dataProvider entityExpansionAttacksProvider
     */
    public function testEntityExpansionAttacksAreRejectedOrNeutralized(string $xml): void
    {
        $autoload = realpath(self::PROJECT_ROOT . '/vendor/autoload.php');
        $this->assertNotFalse($autoload, 'vendor/autoload.php non trovato: eseguire composer install.');

        $code = 'require ' . var_export($autoload, true) . ';'
            . '$c = new A35G\JsonToXml\XmlToJsonConverter();'
            . 'try {'
            . '  echo json_encode($c->xmlToArray((string) stream_get_contents(STDIN)));'
            . '  exit(0);'
            . '} catch (InvalidArgumentException $e) {'
            . '  echo "REJECTED";'
            . '  exit(3);'
            . '}';

        $result = $this->runPhpSubprocess($code, $xml);

        $this->assertFalse($result['timedOut'], 'Il parsing non è terminato entro il timeout: possibile DoS.');
        $this->assertContains(
            $result['exitCode'],
            [0, 3],
            'Exit code inatteso (255 = fatal per memoria esaurita). STDERR: ' . $result['stderr']
        );
        $this->assertLessThan(1000, strlen($result['stdout']), 'L\'output è sospettosamente grande: entità espanse?');
        $this->assertLessThan(10.0, $result['elapsed'], 'Il parsing ha impiegato troppo tempo.');
    }

    public static function entityExpansionAttacksProvider(): array
    {
        $big = str_repeat('A', 50000);

        return [
            // 10 livelli x 10 riferimenti: ~10^9 occorrenze di "lol" se espanso.
            'classic billion laughs' => [self::billionLaughsXml(10, 10)],

            // Entità che si riferiscono a vicenda: ricorsione infinita.
            'recursive entity (a -> b -> a)' => [
                '<?xml version="1.0"?>'
                . '<!DOCTYPE r [<!ENTITY a "&b;"><!ENTITY b "&a;">]>'
                . '<r>&a;</r>',
            ],

            // Quadratic blowup: nessuna annidazione, una sola entità grande
            // ripetuta molte volte (~2.5 GB se espansa, documento di ~250 KB).
            'quadratic blowup' => [
                '<?xml version="1.0"?>'
                . '<!DOCTYPE q [<!ENTITY big "' . $big . '">]>'
                . '<q>' . str_repeat('&big;', 50000) . '</q>',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Sicurezza: risorse, profondità, esplosione di nodi/attributi
    // ------------------------------------------------------------------

    /**
     * Parsa un XML in un sottoprocesso (stesso meccanismo degli attacchi a
     * entità) e restituisce solo un riassunto, per non gonfiare l'output.
     *
     * @return array{exitCode: int, bytes: int|null, timedOut: bool, elapsed: float, stderr: string}
     */
    private function parseXmlInSubprocess(string $xml): array
    {
        $autoload = realpath(self::PROJECT_ROOT . '/vendor/autoload.php');
        $this->assertNotFalse($autoload, 'vendor/autoload.php non trovato: eseguire composer install.');

        $code = 'require ' . var_export($autoload, true) . ';'
            . '$c = new A35G\JsonToXml\XmlToJsonConverter();'
            . 'try {'
            . '  $r = $c->xmlToArray((string) stream_get_contents(STDIN));'
            . '  echo "OK:" . strlen((string) json_encode($r));'
            . '  exit(0);'
            . '} catch (InvalidArgumentException $e) {'
            . '  echo "REJECTED";'
            . '  exit(3);'
            . '}';

        $result = $this->runPhpSubprocess($code, $xml);

        return [
            'exitCode' => $result['exitCode'],
            'bytes' => preg_match('/^OK:(\d+)$/', $result['stdout'], $m) === 1 ? (int) $m[1] : null,
            'timedOut' => $result['timedOut'],
            'elapsed' => $result['elapsed'],
            'stderr' => $result['stderr'],
        ];
    }

    public function testQuadraticBlowupInAttributeValueIsRejectedOrNeutralized(): void
    {
        // Come il quadratic blowup nel contenuto, ma dentro un attributo: il
        // valore viene letto con DOMAttr::$value, che potrebbe espandere le
        // entità. ~2,5 GB se espanso, documento di ~250 KB.
        $big = str_repeat('A', 50000);
        $xml = '<?xml version="1.0"?>'
            . '<!DOCTYPE q [<!ENTITY big "' . $big . '">]>'
            . '<q a="' . str_repeat('&big;', 50000) . '"/>';

        $result = $this->parseXmlInSubprocess($xml);

        $this->assertFalse($result['timedOut'], 'Il parsing non è terminato entro il timeout: possibile DoS.');
        $this->assertContains(
            $result['exitCode'],
            [0, 3],
            'Exit code inatteso (255 = memoria esaurita). STDERR: ' . $result['stderr']
        );
        $this->assertLessThan(1000000, $result['bytes'] ?? 0, 'Il valore dell\'attributo risulta espanso.');
    }

    /**
     * @dataProvider wideDocumentsProvider
     */
    public function testWideDocumentsAreHandledWithinResourceBudget(string $kind): void
    {
        // La libreria non impone limiti propri: questo test documenta che
        // documenti larghi ma "legittimi" restano nel budget (memoria 256M,
        // 10 s) e che quelli oltre i limiti di libxml2 vengono rifiutati
        // invece di mandare in crash il processo.
        $xml = match ($kind) {
            'siblings' => '<r>' . str_repeat('<i>x</i>', 100000) . '</r>',
            'attributes' => '<r ' . implode(' ', array_map(
                static fn (int $n): string => "a{$n}=\"1\"",
                range(1, 20000)
            )) . '/>',
            'namespaces' => '<r ' . implode(' ', array_map(
                static fn (int $n): string => "xmlns:p{$n}=\"urn:{$n}\"",
                range(1, 5000)
            )) . '/>',
            'huge-text' => '<r>' . str_repeat('A', 11 * 1024 * 1024) . '</r>',
            default => throw new \LogicException("Tipo di documento sconosciuto: {$kind}"),
        };

        $result = $this->parseXmlInSubprocess($xml);

        $this->assertFalse($result['timedOut'], "Timeout con il documento '{$kind}'.");
        $this->assertContains(
            $result['exitCode'],
            [0, 3],
            "Exit code inatteso con '{$kind}' (255 = memoria esaurita). STDERR: " . $result['stderr']
        );
        $this->assertLessThan(10.0, $result['elapsed'], "Parsing troppo lento con '{$kind}'.");
    }

    public static function wideDocumentsProvider(): array
    {
        return [
            '100k elementi fratelli' => ['siblings'],
            '20k attributi su un elemento' => ['attributes'],
            '5k dichiarazioni di namespace' => ['namespaces'],
            'nodo di testo da 11 MB' => ['huge-text'],
        ];
    }

    public function testXmlNestingBeyondLibxmlDepthLimitIsRejected(): void
    {
        // libxml2 rifiuta profondità > 256 senza LIBXML_PARSEHUGE: è anche
        // ciò che protegge la ricorsione PHP di elementToValue().
        $xml = str_repeat('<a>', 300) . str_repeat('</a>', 300);

        $this->expectException(InvalidArgumentException::class);
        (new XmlToJsonConverter())->xmlToArray($xml);
    }

    public function testModerateXmlNestingIsParsedWithoutRecursionProblems(): void
    {
        $xml = str_repeat('<a>', 200) . 'x' . str_repeat('</a>', 200);

        $array = (new XmlToJsonConverter())->xmlToArray($xml);

        // Il root <a> è l'elemento esterno: il risultato parte dal suo primo figlio <a>.
        $this->assertArrayHasKey('a', $array);
    }

    public function testJsonNestingBeyondDecodeDepthIsRejected(): void
    {
        // json_decode() ha profondità massima 512: l'errore diventa una
        // InvalidArgumentException, non un crash.
        $json = str_repeat('{"a":', 600) . '1' . str_repeat('}', 600);

        $this->expectException(InvalidArgumentException::class);
        (new JsonToXmlConverter('data'))->jsonToXmlString($json);
    }

    public function testJsonToXmlOutputDeeperThanLibxmlLimitCannotBeParsedBack(): void
    {
        // Asimmetria documentata: JSON profondo 300 è accettato (< 512) e
        // produce un XML valido, ma XmlToJsonConverter non riesce a
        // rileggerlo (> 256).
        $json = str_repeat('{"a":', 300) . '1' . str_repeat('}', 300);
        $xml = (new JsonToXmlConverter('data'))->jsonToXmlString($json);

        $this->expectException(InvalidArgumentException::class);
        (new XmlToJsonConverter())->xmlToArray($xml);
    }

    // ------------------------------------------------------------------
    // Namespace e "parser differential" (caratterizzazione)
    // ------------------------------------------------------------------

    public function testNamespacedAttributesWithSameLocalNameCollapseIntoOneKey(): void
    {
        // $attribute->name è il nome locale senza prefisso: a:id e b:id,
        // associati a namespace diversi, finiscono nella stessa chiave
        // "@id" (l'ultimo vince). Le dichiarazioni xmlns:* non compaiono.
        $xml = '<r xmlns:a="urn:a" xmlns:b="urn:b" a:id="1" b:id="2"/>';

        $array = (new XmlToJsonConverter())->xmlToArray($xml);

        $this->assertSame(['@id'], array_keys($array));
        $this->assertSame('2', $array['@id']);
    }

    public function testElementPrefixIsKeptInKeyButNamespaceUriIsLost(): void
    {
        // Stesso namespace, prefissi diversi => chiavi diverse; l'URI non
        // compare mai nel JSON, quindi un consumatore non può distinguere
        // i namespace.
        $one = (new XmlToJsonConverter())->xmlToArray('<r xmlns:x="urn:one"><x:item>1</x:item></r>');
        $two = (new XmlToJsonConverter())->xmlToArray('<r xmlns:y="urn:one"><y:item>1</y:item></r>');

        $this->assertSame(['x:item' => '1'], $one);
        $this->assertSame(['y:item' => '1'], $two);
    }

    public function testDuplicateJsonKeysFollowPhpLastWinsBehavior(): void
    {
        // Altri parser JSON possono tenere il primo valore o rifiutare il
        // documento: qui vale il comportamento di json_decode().
        $xml = (new JsonToXmlConverter('data'))->jsonToXmlString('{"a":"1","a":"2"}');

        $this->assertSame(1, substr_count($xml, '<a>'));
        $this->assertStringContainsString('<a>2</a>', $xml);
    }

    public function testIntegersBeyondInt64ArePreservedAsStrings(): void
    {
        $xml = (new JsonToXmlConverter('data'))->jsonToXmlString('{"id": 12345678901234567890, "neg": -12345678901234567890, "small": 42}');

        $this->assertStringContainsString('<id>12345678901234567890</id>', $xml);
        $this->assertStringContainsString('<neg>-12345678901234567890</neg>', $xml);
        $this->assertStringContainsString('<small>42</small>', $xml);
    }

    public function testAttributeNamesCollidingAfterSanitizationOverwriteSilently(): void
    {
        // "a b" e "a_b" diventano entrambi "a_b": per gli attributi
        // setAttribute() sovrascrive, per gli elementi i nodi restano due.
        $converter = new JsonToXmlConverter('data');

        $attr = $converter->jsonToXmlString('{"n": {"@a b": "1", "@a_b": "2"}}');
        $this->assertStringContainsString('a_b="2"', $attr);
        $this->assertStringNotContainsString('a_b="1"', $attr);

        $elements = $converter->jsonToXmlString('{"a b": "1", "a_b": "2"}');
        $this->assertSame(2, substr_count($elements, '<a_b>'));
    }

    // ------------------------------------------------------------------
    // Injection JSON -> XML
    // ------------------------------------------------------------------

    private function loadWellFormed(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'L\'XML generato non è ben formato: ' . trim($errors[0]->message ?? ''));

        return $dom;
    }

    /**
     * @dataProvider hostileValuesProvider
     */
    public function testHostileValuesCannotBreakOutOfTheirNode(string $value, bool $useCdata): void
    {
        $json = json_encode(['v' => $value], JSON_THROW_ON_ERROR);
        $converter = new JsonToXmlConverter(rootName: 'data', itemNodeName: 'item', useCdata: $useCdata);

        $xml = $converter->jsonToXmlString($json);

        $dom = $this->loadWellFormed($xml);
        $this->assertSame(0, $dom->getElementsByTagName('injected')->length, 'Elemento iniettato nel documento.');

        // Il testo deve tornare identico: niente troncamenti né spezzature.
        $this->assertSame($value, (new XmlToJsonConverter())->xmlToArray($xml)['v']);
    }

    public static function hostileValuesProvider(): iterable
    {
        $values = [
            'terminatore CDATA' => 'x]]>y',
            'uscita dal CDATA con elemento' => 'x]]><injected/><![CDATA[y',
            'elemento' => '<injected/>',
            'processing instruction' => '<?php echo 1; ?>',
            'commento' => '<!-- c -->',
            'riferimento a entità' => '&injected;',
            'doctype' => '<!DOCTYPE x [<!ENTITY a "b">]>',
        ];

        foreach ($values as $label => $value) {
            yield "{$label} (cdata on)" => [$value, true];
            yield "{$label} (cdata off)" => [$value, false];
        }
    }

    /**
     * @dataProvider xmlInvalidCharactersProvider
     */
    public function testCharactersInvalidInXml10AreRejectedOrSerializedSafely(string $value): void
    {
        // I caratteri di controllo (tranne tab, LF, CR) non sono ammessi in
        // XML 1.0, nemmeno come riferimenti numerici. La libreria deve
        // rifiutarli oppure produrre XML ben formato: mai XML non valido.
        $json = json_encode(['v' => $value], JSON_THROW_ON_ERROR);

        try {
            $xml = (new JsonToXmlConverter('data'))->jsonToXmlString($json);
        } catch (InvalidArgumentException | RuntimeException) {
            $this->addToAssertionCount(1); // rifiuto esplicito: accettabile
            return;
        }

        $this->loadWellFormed($xml);
    }

    public static function xmlInvalidCharactersProvider(): array
    {
        return [
            'SOH' => ["\x01"],
            'backspace' => ["\x08"],
            'vertical tab' => ["\x0B"],
            'unit separator' => ["\x1F"],
            'nel percorso CDATA' => ["<\x01>"],
        ];
    }

    public function testKeysCannotInjectMarkupOrNamespaces(): void
    {
        $json = json_encode([
            'a><injected/><b' => 'v',
            'node' => ['@x="1" y' => 'v', '@xmlns:evil' => 'urn:evil', '@xmlns' => 'urn:evil2'],
        ], JSON_THROW_ON_ERROR);

        $xml = (new JsonToXmlConverter('data'))->jsonToXmlString($json);

        $dom = $this->loadWellFormed($xml);
        $this->assertSame(0, $dom->getElementsByTagName('injected')->length);

        foreach ($dom->getElementsByTagName('*') as $element) {
            $this->assertNull($element->namespaceURI, 'Namespace iniettato tramite una chiave JSON.');
        }
    }

    public function testAttributeValueCannotInjectAnotherAttribute(): void
    {
        $xml = (new JsonToXmlConverter('data'))->jsonToXmlString('{"n": {"@a": "\\" injected=\\"1"}}');

        $dom = $this->loadWellFormed($xml);
        $node = $dom->getElementsByTagName('n')->item(0);

        $this->assertNotNull($node);
        $this->assertSame(1, $node->attributes->length);
        $this->assertSame('" injected="1', $node->attributes->getNamedItem('a')?->nodeValue);
    }

    public function testRootAndItemNamesAreSanitized(): void
    {
        // rootName e itemNodeName arrivano da codice/CLI (--root, --item).
        $converter = new JsonToXmlConverter('r><evil', 'i><evil');
        $xml = $converter->jsonToXmlString('["a"]');

        $dom = $this->loadWellFormed($xml);
        $this->assertSame(0, $dom->getElementsByTagName('evil')->length);
    }

    // ------------------------------------------------------------------
    // File e file temporanei (casi aggiuntivi)
    // ------------------------------------------------------------------

    public function testTempFilenameThrowsWhenTempDirDoesNotExist(): void
    {
        // A differenza del test sui permessi, questo funziona anche su Windows.
        $converter = new JsonToXmlConverter('data');
        $converter->setTempDir(sys_get_temp_dir() . '/json_to_xml_missing_' . uniqid());

        $method = (new ReflectionClass($converter))->getMethod('tempFilename');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $method->invoke($converter);
    }

    public function testJsonToXmlFileToMissingDirectoryThrowsRuntimeException(): void
    {
        $target = sys_get_temp_dir() . '/json_to_xml_missing_' . uniqid() . '/out.xml';
        $converter = new JsonToXmlConverter('data');

        // file_put_contents() emette un E_WARNING prima di restituire false:
        // lo silenziamo solo perché PHPUnit non lo trasformi in errore.
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $converter->jsonToXmlFile('{"a":"b"}', $target);
        } finally {
            restore_error_handler();
        }
    }

    public function testXmlFileToJsonFileThrowsWhenInputFileIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new XmlToJsonConverter())->xmlFileToJsonFile(
            sys_get_temp_dir() . '/json_to_xml_missing_' . uniqid() . '.xml',
            sys_get_temp_dir() . '/json_to_xml_out_' . uniqid() . '.json'
        );
    }

    public function testTempFilesAreCreatedWithOwnerOnlyPermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('I permessi POSIX non sono verificabili su Windows.');
        }

        $converter = new JsonToXmlConverter('data');
        $method = (new ReflectionClass($converter))->getMethod('tempFilename');
        $method->setAccessible(true);

        /** @var string $tempFile */
        $tempFile = $method->invoke($converter);

        try {
            $this->assertSame(0600, fileperms($tempFile) & 0777);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testControlCharactersInAttributeValuesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonToXmlConverter('data'))->jsonToXmlString('{"n": {"@a": "x\\u0001y"}}');
    }

    public function testEntityReferenceInAttributeIsRejectedQuickly(): void
    {
        // Caso piccolo e in-process: il rifiuto deve essere immediato.
        $xml = '<?xml version="1.0"?>'
            . '<!DOCTYPE q [<!ENTITY e "valore">]>'
            . '<q a="&e;"/>';

        $this->expectException(InvalidArgumentException::class);
        (new XmlToJsonConverter())->xmlToArray($xml);
    }

    public function testPredefinedEntitiesInAttributesStillWork(): void
    {
        // Le cinque entità predefinite non devono essere toccate dal
        // controllo anti-entità: sono testo normale per il DOM.
        $array = (new XmlToJsonConverter())->xmlToArray('<q a="x &amp; y &lt; z &quot;w&quot;"/>');

        $this->assertSame('x & y < z "w"', $array['@a']);
    }

    public function testEntityReferenceInElementContentIsRejected(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<!DOCTYPE r [<!ENTITY e "x">]>'
            . '<r>&e;</r>';

        $this->expectException(InvalidArgumentException::class);
        (new XmlToJsonConverter())->xmlToArray($xml);
    }

    public function testPredefinedEntitiesAndCharacterReferencesInContentStillWork(): void
    {
        $array = (new XmlToJsonConverter())->xmlToArray('<r><t>a &amp; b &lt; c &#65;</t></r>');

        $this->assertSame('a & b < c A', $array['t']);
    }
}
