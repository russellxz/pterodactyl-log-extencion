<?php

namespace Pterodactyl\Extensions\ArixLog\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\ArixLog\Models\ExtensionEvent;
use Pterodactyl\Extensions\ArixLog\Services\LogReader;
use Pterodactyl\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Visor de los errores del panel (storage/logs).
 */
class LogsController extends Controller
{
    public function __construct(private LogReader $reader)
    {
    }

    public function index(Request $request)
    {
        $files = $this->reader->files();
        $selected = $this->currentName($request, $files);

        return view('arixlog::admin.logs', [
            'files' => $files,
            'selected' => $selected,
            'levels' => LogReader::levels(),
            'level' => (string) $request->query('level', ''),
            'search' => (string) $request->query('search', ''),
            'directory' => $this->reader->directory(),
        ]);
    }

    /**
     * Datos del visor. La pantalla los pide por AJAX para poder refrescar sin
     * recargar toda la pagina.
     */
    public function data(Request $request): JsonResponse
    {
        $path = $this->reader->resolve($request->query('file'));

        if ($path === null) {
            return new JsonResponse([
                'entries' => [],
                'counts' => [],
                'empty' => true,
                'message' => 'No hay ningun archivo de log todavia. Eso normalmente es buena senal.',
            ]);
        }

        $result = $this->reader->read($path, [
            'level' => (string) $request->query('level', ''),
            'search' => (string) $request->query('search', ''),
            'limit' => (int) $request->query('limit', 150),
            'deep' => $request->boolean('deep'),
        ]);

        return new JsonResponse([
            'file' => basename($path),
            'entries' => $result['entries'],
            'counts' => $result['counts'],
            'truncated' => $result['truncated'],
            'size' => $result['size'],
            'size_human' => $this->human($result['size']),
            'bytes_read_human' => $this->human($result['bytes_read']),
            'empty' => $result['entries'] === [],
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $path = $this->reader->resolve($request->query('file'));

        abort_if($path === null, 404, 'Ese archivo de log no existe.');

        return response()->download($path, basename($path));
    }

    public function clear(Request $request)
    {
        $path = $this->reader->resolve($request->input('file'));

        if ($path === null) {
            return back()->with('arixlog_error', 'Ese archivo de log no existe.');
        }

        $ok = $this->reader->clear($path);

        ExtensionEvent::log($ok ? 'info' : 'warning', 'logs', 'Se vacio un archivo de log', [
            'archivo' => basename($path),
            'resultado' => $ok ? 'correcto' : 'sin permisos',
        ]);

        return back()->with(
            $ok ? 'arixlog_success' : 'arixlog_error',
            $ok
                ? 'Se vacio ' . basename($path) . '.'
                : 'No se pudo vaciar ' . basename($path) . '. Revisa los permisos de storage/logs.'
        );
    }

    public function destroy(Request $request)
    {
        $path = $this->reader->resolve($request->input('file'));

        if ($path === null) {
            return back()->with('arixlog_error', 'Ese archivo de log no existe.');
        }

        $name = basename($path);
        $ok = $this->reader->delete($path);

        ExtensionEvent::log($ok ? 'info' : 'warning', 'logs', 'Se borro un archivo de log', [
            'archivo' => $name,
            'resultado' => $ok ? 'correcto' : 'sin permisos',
        ]);

        return redirect()
            ->route('admin.arixlog.logs')
            ->with(
                $ok ? 'arixlog_success' : 'arixlog_error',
                $ok ? 'Se borro ' . $name . '.' : 'No se pudo borrar ' . $name . '. Revisa los permisos.'
            );
    }

    /**
     * @param array<int, array{name: string}> $files
     */
    private function currentName(Request $request, array $files): ?string
    {
        $requested = (string) $request->query('file', '');

        foreach ($files as $file) {
            if ($file['name'] === $requested) {
                return $requested;
            }
        }

        return $files[0]['name'] ?? null;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 1) . ' ' . $units[$index];
    }
}
