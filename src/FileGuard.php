<?php

declare(strict_types=1);

namespace A35G\JsonToXml;

use InvalidArgumentException;
use RuntimeException;

/**
 * FileGuard
 *
 * Utilità interna per l'accesso ai file di JsonToXmlConverter e XmlToJsonConverter:
 * validazione dei percorsi, confinamento in una directory base e scrittura atomica.
 *
 * @internal Non fa parte dell'API pubblica: può cambiare senza preavviso.
 */
final class FileGuard
{
    /** Stream wrapper ("scheme://") e URI "data:": non ammessi come percorsi di file. */
    private const WRAPPER_PATTERN = '#^(?:[a-z][a-z0-9+.\-]+://|data:)#i';

    private function __construct()
    {
    }

    /**
     * Rifiuta percorsi vuoti, con byte NUL o con stream wrapper.
     *
     * Lo schema richiede almeno due caratteri, così "C:\..." e "C:/..." (Windows)
     * non vengono scambiati per un wrapper.
     *
     * @throws InvalidArgumentException
     */
    public static function assertPlainPath(string $path): void
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException('Il percorso del file non può essere vuoto.');
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Il percorso del file contiene byte NUL.');
        }

        if (preg_match(self::WRAPPER_PATTERN, $path) === 1) {
            throw new InvalidArgumentException(
                "Il percorso \"{$path}\" usa uno stream wrapper o un URI: sono ammessi solo percorsi di file."
            );
        }
    }

    /**
     * Valida e normalizza la directory base (null = nessun confinamento).
     *
     * @throws InvalidArgumentException se non è una directory esistente
     */
    public static function normalizeBaseDir(?string $baseDir): ?string
    {
        if ($baseDir === null) {
            return null;
        }

        self::assertPlainPath($baseDir);

        $real = realpath($baseDir);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("La directory base non esiste: {$baseDir}");
        }

        return $real;
    }

    /**
     * Valida un percorso e, se è impostata una directory base, lo risolve e
     * verifica che sia al suo interno. Restituisce il percorso da usare.
     *
     * Con una directory base il risultato è il percorso REALE (symlink risolti),
     * da usare per l'operazione successiva al posto dell'originale.
     *
     * @param bool $mustExist true per i file da leggere, false per quelli da scrivere
     *                        (in tal caso deve esistere la directory che li contiene)
     *
     * @throws InvalidArgumentException
     */
    public static function resolve(string $path, ?string $baseDir, bool $mustExist): string
    {
        self::assertPlainPath($path);

        if ($baseDir === null) {
            return $path;
        }

        if ($mustExist) {
            $real = realpath($path);
            if ($real === false) {
                throw new InvalidArgumentException("Impossibile risolvere il percorso: {$path}");
            }
        } else {
            $name = basename($path);
            if ($name === '' || $name === '.' || $name === '..') {
                throw new InvalidArgumentException("Percorso di destinazione non valido: {$path}");
            }

            $dir = realpath(dirname($path));
            if ($dir === false) {
                throw new InvalidArgumentException("La directory di destinazione non esiste: {$path}");
            }

            $real = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $name;
        }

        if (!self::isInside($real, $baseDir)) {
            throw new InvalidArgumentException("Il percorso \"{$path}\" è fuori dalla directory base consentita.");
        }

        return $real;
    }

    /**
     * Scrive il contenuto in modo atomico: file temporaneo nella stessa directory,
     * sincronizzazione su disco e rename() sulla destinazione.
     *
     * Se la scrittura fallisce, la destinazione resta com'era e il file
     * temporaneo viene eliminato.
     *
     * @throws RuntimeException
     */
    public static function writeAtomic(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException(
                "Errore durante il salvataggio del file: {$path} (directory assente o non scrivibile)"
            );
        }

        $mode = self::targetMode($path);

        // tempnam() crea il file con permessi 0600: li allineiamo prima del rename.
        $tmp = tempnam($dir, 'xml_atomic_');
        if ($tmp === false) {
            throw new RuntimeException("Impossibile creare il file temporaneo in: {$dir}");
        }

        try {
            self::writeAndSync($tmp, $content, $path);
            @chmod($tmp, $mode);

            if (!@rename($tmp, $path)) {
                throw new RuntimeException("Errore durante il salvataggio del file: {$path}");
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function isInside(string $path, string $baseDir): bool
    {
        $prefix = rtrim($baseDir, '\\/') . DIRECTORY_SEPARATOR;

        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $prefix = strtolower($prefix);
        }

        return str_starts_with($path, $prefix);
    }

    /**
     * Permessi del file finale: quelli del file esistente, altrimenti gli stessi
     * che avrebbe creato file_put_contents() (0666 filtrato dalla umask).
     */
    private static function targetMode(string $path): int
    {
        if (is_file($path)) {
            $perms = fileperms($path);
            if ($perms !== false) {
                return $perms & 0777;
            }
        }

        return 0666 & ~umask();
    }

    /**
     * @throws RuntimeException
     */
    private static function writeAndSync(string $tmp, string $content, string $finalPath): void
    {
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Errore durante il salvataggio del file: {$finalPath}");
        }

        $written = fwrite($handle, $content);
        $flushed = fflush($handle);
        @fsync($handle); // best effort: non tutti i filesystem lo supportano
        fclose($handle);

        if ($written !== strlen($content) || !$flushed) {
            throw new RuntimeException("Errore durante il salvataggio del file: {$finalPath}");
        }
    }
}
