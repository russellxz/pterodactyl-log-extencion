<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\InstallEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\NodeAccess;
use Pterodactyl\Extensions\LogsPterodactyl\Services\NodeSsh;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * Acceso SSH a los nodos, para poder soltar el bloqueo de instalacion de wings
 * sin entrar a mano en cada maquina.
 */
class NodesController extends Controller
{
    public function __construct(private NodeSsh $ssh)
    {
    }

    public function index()
    {
        $accesos = NodeAccess::query()->get()->keyBy('node_id');

        $nodos = Node::query()->orderBy('name')->get()->map(function (Node $node) use ($accesos) {
            $acceso = $accesos->get($node->id);

            return [
                'id' => $node->id,
                'name' => $node->name,
                'fqdn' => $node->fqdn,
                'acceso' => $acceso,
                'configurado' => $acceso !== null,
            ];
        })->all();

        return view('logspterodactyl::admin.nodes', [
            'nodos' => $nodos,
            'disponible' => $this->ssh->available(),
        ]);
    }

    public function save(Request $request, string $node)
    {
        $nodo = Node::query()->find((int) $node);

        if (!$nodo) {
            return back()->with('logspterodactyl_error', 'Ese nodo ya no existe.');
        }

        $datos = $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:64',
            'auth_type' => 'required|in:password,key',
            'secret' => 'nullable|string|max:20000',
            'passphrase' => 'nullable|string|max:500',
        ]);

        $acceso = NodeAccess::query()->firstOrNew(['node_id' => $nodo->id]);

        // El secreto solo se toca si han escrito uno nuevo: asi se puede
        // cambiar el puerto o el usuario sin volver a teclear la contrasena.
        if (trim((string) $datos['secret']) === '' && !$acceso->exists) {
            return back()->with('logspterodactyl_error', 'Hace falta la contrasena o la clave privada.');
        }

        $acceso->fill([
            'host' => trim($datos['host']),
            'port' => (int) $datos['port'],
            'username' => trim($datos['username']),
            'auth_type' => $datos['auth_type'],
            'enabled' => $request->boolean('enabled'),
            'auto_fix' => $request->boolean('auto_fix'),
        ]);

        if (trim((string) $datos['secret']) !== '') {
            $acceso->secret = $datos['secret'];
            $acceso->passphrase = $datos['passphrase'] ?: null;

            // Credencial nueva: la huella de antes puede que ya no valga.
            if ($acceso->exists && $acceso->isDirty('auth_type')) {
                $acceso->fingerprint = null;
            }
        }

        $acceso->save();

        ExtensionEvent::log('info', 'nodos', 'Acceso SSH guardado para el nodo ' . $nodo->name, [
            'host' => $acceso->host,
            'puerto' => $acceso->port,
            'usuario' => $acceso->username,
            'tipo' => $acceso->auth_type,
            'arreglo_automatico' => $acceso->auto_fix,
        ]);

        return back()->with('logspterodactyl_success', 'Acceso guardado para "' . $nodo->name . '". Pruebalo antes de fiarte.');
    }

    public function test(Request $request, string $node)
    {
        $acceso = NodeAccess::query()->where('node_id', (int) $node)->first();

        if (!$acceso) {
            return back()->with('logspterodactyl_error', 'Ese nodo no tiene acceso configurado.');
        }

        $resultado = $this->ssh->test($acceso, $request->boolean('confiar'));

        if (!$resultado['ok']) {
            return back()->with('logspterodactyl_error', 'No se pudo conectar: ' . ($resultado['error'] ?: 'sin detalle.'));
        }

        return back()->with('logspterodactyl_success', sprintf(
            'Conectado a %s. Huella %s. Respuesta: %s',
            $acceso->host,
            $resultado['huella'] ?: '?',
            trim(preg_replace('/\s+/', ' ', $resultado['salida'])) ?: '(sin salida)'
        ));
    }

    public function forget(string $node)
    {
        $acceso = NodeAccess::query()->where('node_id', (int) $node)->first();

        if (!$acceso) {
            return back()->with('logspterodactyl_error', 'Ese nodo no tiene acceso configurado.');
        }

        $acceso->forgetFingerprint();

        return back()->with(
            'logspterodactyl_success',
            'Huella olvidada. La proxima conexion aceptara la clave nueva de la maquina y la guardara.'
        );
    }

    public function destroy(string $node)
    {
        NodeAccess::query()->where('node_id', (int) $node)->delete();

        ExtensionEvent::log('warning', 'nodos', 'Acceso SSH borrado del nodo ' . $node);

        return back()->with('logspterodactyl_success', 'Acceso borrado.');
    }

    /**
     * Reiniciar wings a mano desde el panel.
     */
    public function restartWings(string $node)
    {
        $acceso = NodeAccess::query()->where('node_id', (int) $node)->first();

        if (!$acceso) {
            return back()->with('logspterodactyl_error', 'Ese nodo no tiene acceso configurado.');
        }

        $resultado = $this->ssh->restartWings($acceso);

        return $resultado['ok']
            ? back()->with('logspterodactyl_success', 'Wings reiniciado en "' . $acceso->host . '".')
            : back()->with('logspterodactyl_error', 'No se pudo reiniciar wings: ' . ($resultado['error'] ?: trim($resultado['salida'])));
    }

    /**
     * Soltar el bloqueo de un servidor concreto, desde el aviso de la pantalla
     * de instalaciones.
     */
    public function releaseServer(string $server)
    {
        $modelo = Server::query()->with('node')->find((int) $server);

        if (!$modelo) {
            return back()->with('logspterodactyl_error', 'Ese servidor ya no existe.');
        }

        $acceso = NodeAccess::query()->where('node_id', $modelo->node_id)->where('enabled', true)->first();

        if (!$acceso) {
            return back()->with(
                'logspterodactyl_error',
                'El nodo de ese servidor no tiene acceso SSH configurado. Ponlo en la pestana "Nodos" y podras hacerlo desde aqui.'
            );
        }

        $estado = $this->ssh->installerStatus($acceso, (string) $modelo->uuid);

        if (!$estado['ok']) {
            return back()->with('logspterodactyl_error', 'No se pudo consultar el nodo: ' . ($estado['error'] ?: 'sin detalle.'));
        }

        if (!$estado['existe']) {
            return back()->with(
                'logspterodactyl_success',
                'En el nodo ya no queda ningun contenedor de instalacion de ese servidor. Si sigue sin instalar, reinicia wings.'
            );
        }

        $resultado = $this->ssh->killInstaller($acceso, (string) $modelo->uuid);

        if (!$resultado['ok']) {
            return back()->with('logspterodactyl_error', 'No se pudo eliminar el contenedor: ' . ($resultado['error'] ?: trim($resultado['salida'])));
        }

        // Se marcan los avisos de ese servidor como resueltos para que el
        // cartel rojo desaparezca sin esperar a la siguiente instalacion.
        InstallEvent::query()
            ->where('server_id', $modelo->id)
            ->whereIn('diagnosis', [InstallEvent::DIAG_NODE_BUSY, InstallEvent::DIAG_NODE_SILENT])
            ->update(['diagnosis' => null]);

        return back()->with('logspterodactyl_success', sprintf(
            'Contenedor de instalacion eliminado en "%s" (estaba: %s). El cliente ya puede reinstalar.',
            $acceso->host,
            $estado['detalle'] ?: 'sin detalle'
        ));
    }
}
