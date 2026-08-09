<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Services;

/**
 * Lector de los archivos de log de Laravel (storage/logs).
 *
 * Estos archivos pueden llegar a pesar cientos de megas en un panel con
 * trafico, asi que nunca se cargan enteros en memoria: se lee solo la cola del
 * archivo (los ultimos N bytes) y se recorta por el principio de la primera
 * entrada completa. Es la parte que suele dejar sin RAM a los visores de logs
 * hechos a la ligera.
 */
class LogReader
{
    /** Tamano maximo de la cola que se lee de un archivo, en bytes. */
    public const DEFAULT_TAIL_BYTES = 3 * 1024 * 1024;

    /** Limite duro al buscar en todo el archivo. */
    public const MAX_SEARCH_BYTES = 48 * 1024 * 1024;

    /** Recorte del texto guardado por entrada para no reventar la vista. */
    private const MAX_ENTRY_CHARS = 60000;

    private const LEVELS = [
        'emergency', 'alert', 'critical', 'error',
        'warning', 'notice', 'info', 'debug',
    ];

    public function __construct(private ?string $directory = null)
    {
        $this->directory = $this->directory ?: storage_path('logs');
    }

    /**
     * Archivos de log disponibles, del mas reciente al mas antiguo.
     *
     * @return array<int, array{name: string, size: int, modified: int}>
     */
    public function files(): array
    {
        $files = [];

        foreach ((array) glob($this->directory . '/*.log') as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $files[] = [
                'name' => basename($path),
                'size' => (int) filesize($path),
                'modified' => (int) filemtime($path),
            ];
        }

        usort($files, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Comprueba que el nombre pedido es uno de los archivos de la carpeta de
     * logs y devuelve su ruta absoluta. Evita cualquier intento de salirse de
     * la carpeta con rutas tipo "../../.env".
     */
    public function resolve(?string $name): ?string
    {
        if ($name === null || $name === '') {
            $files = $this->files();

            return $files === [] ? null : $this->directory . '/' . $files[0]['name'];
        }

        if (basename($name) !== $name || !str_ends_with($name, '.log')) {
            return null;
        }

        $path = $this->directory . '/' . $name;

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * Lee y trocea las entradas de un archivo.
     *
     * @param array{level?: string, search?: string, limit?: int, deep?: bool} $options
     *
     * @return array{
     *     entries: array<int, array<string, mixed>>,
     *     truncated: bool,
     *     bytes_read: int,
     *     size: int,
     *     counts: array<string, int>
     * }
     */
    public function read(string $path, array $options = []): array
    {
        $level = strtolower((string) ($options['level'] ?? ''));
        $search = trim((string) ($options['search'] ?? ''));
        $limit = max(1, min(1000, (int) ($options['limit'] ?? 200)));
        $deep = (bool) ($options['deep'] ?? false);

        $size = (int) filesize($path);
        $window = $deep ? self::MAX_SEARCH_BYTES : self::DEFAULT_TAIL_BYTES;
        $chunk = $this->tail($path, $window);

        $headers = $this->headers($chunk['content']);
        $counts = $this->countLevels($headers);

        // Se recorre de la entrada mas nueva a la mas vieja y se para en
        // cuanto hay suficientes resultados. Asi el consumo de memoria depende
        // del limite pedido y no del tamano del archivo.
        $entries = [];
        $needle = $search === '' ? null : mb_strtolower($search);
        $wanted = ($level !== '' && in_array($level, self::LEVELS, true)) ? $level : null;
        $total = count($headers);

        for ($i = $total - 1; $i >= 0 && count($entries) < $limit; $i--) {
            if ($wanted !== null && $headers[$i]['level'] !== $wanted) {
                continue;
            }

            $entry = $this->buildEntry($chunk['content'], $headers, $i);

            if ($needle !== null
                && !str_contains(mb_strtolower($entry['message']), $needle)
                && !str_contains(mb_strtolower($entry['stack']), $needle)) {
                continue;
            }

            $entries[] = $entry;
        }

        return [
            'entries' => $entries,
            'truncated' => $chunk['truncated'],
            'bytes_read' => $chunk['bytes'],
            'size' => $size,
            'counts' => $counts,
        ];
    }

    /**
     * Devuelve los ultimos $bytes de un archivo, recortados por el principio
     * hasta la primera entrada completa para no partir un mensaje por la mitad.
     *
     * @return array{content: string, bytes: int, truncated: bool}
     */
    private function tail(string $path, int $bytes): array
    {
        $size = (int) filesize($path);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ['content' => '', 'bytes' => 0, 'truncated' => false];
        }

        $truncated = $size > $bytes;
        $offset = $truncated ? $size - $bytes : 0;

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $content = (string) stream_get_contents($handle);
        fclose($handle);

        if ($truncated) {
            // Se descarta lo que haya antes de la primera entrada completa.
            $position = $this->firstEntryOffset($content);
            $content = $position === null ? '' : substr($content, $position);
        }

        return [
            'content' => $content,
            'bytes' => strlen($content),
            'truncated' => $truncated,
        ];
    }

    private function firstEntryOffset(string $content): ?int
    {
        if (preg_match('/^\[\d{4}-\d{2}-\d{2}/m', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
            return $m[0][1];
        }

        return null;
    }

    /**
     * Localiza la cabecera de cada entrada ("[fecha] canal.NIVEL: mensaje")
     * guardando solo su posicion, nivel y fecha. Es una pasada barata que
     * permite contar por nivel y luego materializar unicamente las entradas
     * que se van a mostrar.
     *
     * @return array<int, array{offset: int, date: string, channel: string, level: string}>
     */
    private function headers(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $pattern = '/^\[(?<date>\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s'
            . '(?<channel>[^\s.]+)\.(?<level>[A-Z]+):\s/m';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $headers = [];

        foreach ($matches as $match) {
            $level = strtolower($match['level'][0]);

            $headers[] = [
                'offset' => $match[0][1],
                'date' => $match['date'][0],
                'channel' => $match['channel'][0],
                'level' => in_array($level, self::LEVELS, true) ? $level : 'info',
            ];
        }

        return $headers;
    }

    /**
     * Materializa una entrada concreta: su bloque va desde su propia cabecera
     * hasta la cabecera siguiente.
     *
     * @param array<int, array{offset: int, date: string, channel: string, level: string}> $headers
     *
     * @return array<string, mixed>
     */
    private function buildEntry(string $content, array $headers, int $index): array
    {
        $header = $headers[$index];
        $start = $header['offset'];
        $end = isset($headers[$index + 1]) ? $headers[$index + 1]['offset'] : strlen($content);
        $block = substr($content, $start, $end - $start);

        // La primera linea es la cabecera; el resto es la traza.
        $newline = strpos($block, "\n");
        $firstLine = $newline === false ? $block : substr($block, 0, $newline);
        $stack = $newline === false ? '' : trim(substr($block, $newline + 1));

        // Se quita el prefijo "[fecha] canal.NIVEL: " del mensaje.
        $message = preg_replace('/^\[[^\]]+\]\s[^\s.]+\.[A-Z]+:\s/', '', $firstLine) ?? $firstLine;

        return [
            'id' => substr(sha1($header['date'] . $message . $start), 0, 12),
            'date' => $header['date'],
            'channel' => $header['channel'],
            'level' => $header['level'],
            'level_label' => strtoupper($header['level']),
            'message' => $this->clip($message),
            'stack' => $this->clip($stack),
            'exception' => $this->extractException($message . "\n" . $stack),
            'origin' => $this->extractOrigin($message . "\n" . $stack),
        ];
    }

    private function clip(string $value): string
    {
        return mb_strlen($value) > self::MAX_ENTRY_CHARS
            ? mb_substr($value, 0, self::MAX_ENTRY_CHARS) . "\n[... recortado por LogsPterodactyl ...]"
            : $value;
    }

    /**
     * Saca el nombre de la clase de excepcion, que es lo que de verdad se
     * busca de un vistazo al abrir los logs.
     */
    private function extractException(string $text): ?string
    {
        if (preg_match('/\(([A-Za-z0-9_\\\\]*(?:Exception|Error))(?:\(code|\)|:)/', $text, $m) === 1) {
            return $this->shortClass($m[1]);
        }

        if (preg_match('/^([A-Za-z0-9_\\\\]*(?:Exception|Error)):/', $text, $m) === 1) {
            return $this->shortClass($m[1]);
        }

        return null;
    }

    /**
     * Primer archivo del propio panel que aparece en la traza: es el sitio
     * donde hay que mirar. Se ignoran vendor/ y el framework.
     */
    private function extractOrigin(string $text): ?string
    {
        if (preg_match_all('#(/[^\s():"\']+\.php)(?::|\()(\d+)#', $text, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $fallback = null;

        foreach ($matches as $match) {
            $file = $match[1];
            $line = $match[2];
            $short = $this->relativePath($file);

            if ($fallback === null) {
                $fallback = $short . ':' . $line;
            }

            if (!str_contains($file, '/vendor/')) {
                return $short . ':' . $line;
            }
        }

        return $fallback;
    }

    private function relativePath(string $file): string
    {
        $base = rtrim(base_path(), '/') . '/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private function shortClass(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));

        return end($parts) ?: $class;
    }

    /**
     * @param array<int, array{level: string}> $headers
     *
     * @return array<string, int>
     */
    private function countLevels(array $headers): array
    {
        $counts = array_fill_keys(self::LEVELS, 0);
        $counts['total'] = count($headers);

        foreach ($headers as $header) {
            $level = $header['level'];
            $counts[$level] = ($counts[$level] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Vacia un archivo de log sin borrarlo, para no pelearse con los permisos
     * ni con el descriptor que php-fpm pueda tener abierto.
     */
    public function clear(string $path): bool
    {
        return @file_put_contents($path, '') !== false;
    }

    public function delete(string $path): bool
    {
        return @unlink($path);
    }

    public function directory(): string
    {
        return (string) $this->directory;
    }

    /**
     * @return array<int, string>
     */
    public static function levels(): array
    {
        return self::LEVELS;
    }
}
