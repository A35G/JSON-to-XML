<?php

declare(strict_types=1);

namespace A35G\JsonToXml\Tests;

use PHPUnit\Framework\TestCase;

/**
 * CliTest
 *
 * A differenza degli altri test, questi non chiamano le classi PHP
 * direttamente ma lanciano bin/json-to-xml come un vero processo esterno
 * (via proc_open), così come farebbe un utente da terminale. Copre:
 *
 *  - parsing degli argomenti (--root, --item, --no-cdata, --force-array, ...)
 *  - lettura/scrittura da STDIN/STDOUT e da/verso file (--input/--output)
 *  - codici di uscita distinti per successo, uso errato, I/O e dati non validi
 *  - il messaggio di help
 *
 * Se lo script CLI non esiste ancora (repository non aggiornato) o l'eseguibile
 * PHP non è disponibile nell'ambiente di CI, i test vengono saltati anziché
 * falliti, per non bloccare l'esecuzione della suite unitaria "pura".
 */
final class CliTest extends TestCase
{
    private const CLI_SCRIPT = __DIR__ . '/../bin/json-to-xml';

    protected function setUp(): void
    {
        if (!is_file(self::CLI_SCRIPT)) {
            $this->markTestSkipped('Script CLI non trovato: ' . self::CLI_SCRIPT);
        }
    }

    /**
     * @param string[] $args
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private function runCli(array $args, ?string $stdin = null): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::CLI_SCRIPT);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        $this->assertIsResource($process, 'Impossibile avviare il processo CLI.');

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
    }

    public function testToXmlConvertsStdinToStdout(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['to-xml'], '{"nome":"Mario"}');

        $this->assertSame(0, $exitCode, "STDERR: {$stderr}");
        $this->assertStringContainsString('<nome>Mario</nome>', $stdout);
    }

    public function testToJsonConvertsStdinToStdout(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['to-json'], '<data><nome>Mario</nome></data>');

        $this->assertSame(0, $exitCode, "STDERR: {$stderr}");

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Mario', $decoded['nome']);
    }

    public function testToXmlHonorsRootAndNoCdataOptions(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(
            ['to-xml', '--root=root', '--no-cdata'],
            '{"testo":"a <b> c"}'
        );

        $this->assertSame(0, $exitCode, "STDERR: {$stderr}");
        $this->assertStringStartsWith('<?xml', $stdout);
        $this->assertStringContainsString('<root>', $stdout);
        $this->assertStringNotContainsString('CDATA', $stdout);
        $this->assertStringContainsString('a &lt;b&gt; c', $stdout);
    }

    public function testToJsonHonorsForceArrayOption(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(
            ['to-json', '--force-array=Nota'],
            '<Note><Nota>uno</Nota></Note>'
        );

        $this->assertSame(0, $exitCode, "STDERR: {$stderr}");

        $decoded = json_decode($stdout, true);
        $this->assertSame(['uno'], $decoded['Nota']);
    }

    public function testFileInputAndOutputOptionsWork(): void
    {
        $inputFile = tempnam(sys_get_temp_dir(), 'cli_in_');
        $outputFile = tempnam(sys_get_temp_dir(), 'cli_out_');

        try {
            file_put_contents($inputFile, '{"nome":"Mario"}');

            [$exitCode, , $stderr] = $this->runCli([
                'to-xml',
                '--input=' . $inputFile,
                '--output=' . $outputFile,
            ]);

            $this->assertSame(0, $exitCode, "STDERR: {$stderr}");
            $this->assertFileExists($outputFile);
            $this->assertStringContainsString('<nome>Mario</nome>', (string) file_get_contents($outputFile));
        } finally {
            @unlink($inputFile);
            @unlink($outputFile);
        }
    }

    public function testInvalidJsonExitsWithDedicatedCodeAndWritesToStderr(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['to-xml'], '{invalid}');

        $this->assertSame(3, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Errore', $stderr);
    }

    public function testUnknownCommandExitsWithErrorAndUsage(): void
    {
        [$exitCode, , $stderr] = $this->runCli(['frobnicate']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Comando sconosciuto', $stderr);
        $this->assertStringContainsString('Uso:', $stderr);
    }

    public function testHelpFlagPrintsUsageAndExitsSuccessfully(): void
    {
        [$exitCode, , $stderr] = $this->runCli(['--help']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Uso:', $stderr);
    }

    public function testMissingInputFileExitsWithIoErrorCode(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['to-xml', '--input=/percorso/sicuramente/inesistente.json']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Impossibile leggere', $stderr);
    }

    public function testNoArgumentsPrintsUsageAndExitsWithErrorCode(): void
    {
        [$exitCode, , $stderr] = $this->runCli([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Uso:', $stderr);
    }
}
