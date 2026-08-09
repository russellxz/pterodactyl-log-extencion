<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
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
            'secret' => 'nullable|string|max:500',
        ]);

        $acceso = NodeAccess::query()->firstOrNew(['node_id' => $nodo->id]);

        // La contrasena solo se toca si han escrito una nueva: asi se puede
        // cambiar el puerto o el usuario sin volver a teclearla.
        if (trim((string) $datos['secret']) === '' && !$acceso->exists) {
            return back()->with('logspterodactyl_error', 'Hace falta la contrasena SSH.');
        }

        $acceso->fill([
            'host' => trim($datos['host']),
            'port' => (int) $datos['port'],
            'username' => trim($datos['username']),
            'auth_type' => NodeAccess::AUTH_PASSWORD,
            'enabled' => $request->boolean('enabled'),
            'auto_fix' => $request->boolean('auto_fix'),
        ]);

        if (trim((string) $datos['secret']) !== '') {
            $acceso->secret = $datos['secret'];
        }

        $acceso->save();

        ExtensionEvent::log('info', 'nodos', 'Acceso SSH guardado para el nodo ' . $nodo->name, [
            'host' => $acceso->host,
            'puerto' => $acceso->port,
            'usuario' => $acceso->username,
            'arreglo_automatico' => $acceso->auto_fix,
        ]);

        return back()->with('logspterodactyl_success', 'Acceso guardado para "' . $nodo->name . '". Pulsa "Comprobar conexion" para ver si entra bien.');
    }

    /**
     * Comprobacion de conexion en vivo, sin recargar la pagina.
     *
     * Devuelve todo lo que hace falta para pintar el semaforo: si entra, con
     * que usuario, si docker responde y si wings esta corriendo.
     */
    public function check(Request $request, string $node): JsonResponse
    {
        $acceso = NodeAccess::query()->where('node_id', (int) $node)->first();

        if (!$acceso) {
            return new JsonResponse([
                'ok' => false,
                'estado' => 'sin_configurar',
                'mensaje' => 'Este nodo todavia no tiene acceso guardado.',
            ]);
        }

        $r = $this->ssh->test($acceso, false);

        if (!$r['ok']) {
            return new JsonResponse([
                'ok' => false,
                'estado' => $r['huella_nueva'] ? 'huella_cambiada' : 'error',
                'mensaje' => $r['error'] ?: 'No se pudo conectar.',
                'huella' => $r['huella'],
                'revisado' => now()->format('H:i:s'),
            ]);
        }

        // Se conecta, pero puede faltarle algo a la maquina.
        $avisos = [];

        if (($r['docker'] ?? 'no') === 'no') {
            $avisos[] = 'docker no responde con este usuario (¿esta instalado? ¿el usuario tiene permiso?)';
        }

        if (($r['wings'] ?? '') !== 'active') {
            $avisos[] = 'el servicio wings no esta activo (' . ($r['wings'] ?: 'desconocido') . ')';
        }

        return new JsonResponse([
            'ok' => true,
            'estado' => $avisos === [] ? 'conectado' : 'conectado_con_avisos',
            'mensaje' => sprintf('Conectado a %s como %s.', $acceso->host, $r['usuario']),
            'usuario' => $r['usuario'],
            'docker' => $r['docker'] === 'no' ? null : $r['docker'],
            'wings' => $r['wings'],
            'huella' => $r['huella'],
            'avisos' => $avisos,
            'revisado' => now()->format('H:i:s'),
        ]);
    }

    public function test(Request $request, string $node)
    {
        $acceso = NodeAccess::query()->where('node_id', (int) $node)->first();

        if (!$acceso) {
            return back()->with('logspterodactyl_error', 'Ese nodo no tiene acceso configurado.');
        }

        $r = $this->ssh->test($acceso, $request->boolean('confiar'));

        if (!$r['ok']) {
            return back()->with('logspterodactyl_error', 'No se pudo conectar: ' . ($r['error'] ?: 'sin detalle.'));
        }

        return back()->with('logspterodactyl_success', sprintf(
            'Conectado a %s como %s. Docker: %s. Wings: %s. Huella %s.',
            $acceso->host,
            $r['usuario'] ?: '?',
            $r['docker'] === 'no' ? 'NO responde' : ($r['docker'] ?: '?'),
            $r['wings'] ?: '?',
            $r['huella'] ?: '?'
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
