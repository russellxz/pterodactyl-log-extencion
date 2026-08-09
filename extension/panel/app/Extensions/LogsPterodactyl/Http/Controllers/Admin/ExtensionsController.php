<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\Services\ExtensionVault;
use Pterodactyl\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Las otras extensiones del panel: verlas, respaldarlas y volver a ponerlas.
 */
class ExtensionsController extends Controller
{
    public function __construct(private ExtensionVault $vault)
    {
    }

    public function index()
    {
        $detectadas = [];
        $error = null;

        try {
            $detectadas = $this->vault->detect();
        } catch (\Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        return view('logspterodactyl::admin.extensions', [
            'detectadas' => $detectadas,
            'copias' => $this->vault->backups(),
            'proveedores' => $this->vault->providerLines(),
            'carpeta' => $this->vault->vaultDir(false),
            'zipDisponible' => class_exists(\ZipArchive::class),
            'error' => $error,
        ]);
    }

    public function backup(Request $request)
    {
        $claves = array_values(array_filter((array) $request->input('extensiones', [])));

        try {
            $resultado = $this->vault->backup($claves);
        } catch (\Throwable $e) {
            return back()->with('logspterodactyl_error', 'No se pudo hacer la copia: ' . $e->getMessage());
        }

        $mensaje = sprintf(
            'Copia creada (%s): %s.',
            $resultado['file'],
            $resultado['incluidas'] === [] ? 'sin contenido' : implode(', ', $resultado['incluidas'])
        );

        foreach ($resultado['avisos'] as $aviso) {
            $mensaje .= ' Aviso: ' . $aviso;
        }

        return back()->with('logspterodactyl_success', $mensaje);
    }

    public function download(string $file): BinaryFileResponse
    {
        $ruta = $this->vault->pathFor($file);

        abort_if($ruta === null, 404);

        return response()->download($ruta, $file, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function destroy(string $file)
    {
        return $this->vault->deleteBackup($file)
            ? back()->with('logspterodactyl_success', 'Copia borrada.')
            : back()->with('logspterodactyl_error', 'Esa copia ya no existe.');
    }

    public function restore(Request $request, string $file)
    {
        try {
            $resultado = $this->vault->restore($file);
        } catch (\Throwable $e) {
            return back()->with('logspterodactyl_error', 'No se pudo restaurar: ' . $e->getMessage());
        }

        $mensaje = 'Restaurado: ' . (implode(', ', $resultado['restauradas']) ?: 'nada') . '.';

        if ($resultado['proveedores'] !== []) {
            $mensaje .= ' OJO: hay ' . count($resultado['proveedores'])
                . ' linea(s) de proveedor que faltan en config/app.php. Las tienes en la pantalla de extensiones.';
        }

        foreach ($resultado['avisos'] as $aviso) {
            $mensaje .= ' Aviso: ' . $aviso;
        }

        return back()->with('logspterodactyl_success', $mensaje);
    }

    public function remove(Request $request, string $key)
    {
        if ($request->input('confirmar') !== $key) {
            return back()->with('logspterodactyl_error', 'No se ha confirmado el nombre de la extension.');
        }

        try {
            $resultado = $this->vault->remove($key);
        } catch (\Throwable $e) {
            return back()->with('logspterodactyl_error', 'No se pudo quitar: ' . $e->getMessage());
        }

        $mensaje = 'Extension quitada. Antes se guardo una copia: ' . $resultado['copia'] . '.';

        if ($resultado['proveedores'] !== []) {
            $mensaje .= ' Te queda quitar a mano de config/app.php: ' . implode(' ', $resultado['proveedores']);
        }

        return back()->with('logspterodactyl_success', $mensaje);
    }
}
