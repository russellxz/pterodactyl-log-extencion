<?php

namespace Pterodactyl\Extensions\DnsReverse\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\DnsReverse\Support\ArixTheme;

/**
 * Pone o quita el boton de DNS Reverse en el menu del tema Arix.
 *
 *   php artisan dnsreverse:arix          ver como esta
 *   php artisan dnsreverse:arix add      ponerlo
 *   php artisan dnsreverse:arix remove   quitarlo
 *
 * Arix no saca el menu del cliente de routes.ts, sino de una lista de enlaces
 * guardada en la base de datos. Este comando toca esa lista, que es la forma
 * que tiene el tema de anadir apartados: el enlace queda como uno mas y el
 * administrador lo puede mover, renombrar o cambiarle el icono desde
 * Admin -> Arix -> Links.
 *
 * Lo llama solo install-frontend.sh cuando detecta el tema. Aqui esta suelto
 * por si hace falta repetirlo despues de actualizar el tema.
 */
class ArixLinkCommand extends Command
{
    protected $signature = 'dnsreverse:arix {accion? : add o remove}';

    protected $description = 'Pone o quita el boton de DNS Reverse en el menu del tema Arix';

    public function handle(): int
    {
        if (!ArixTheme::instalado()) {
            $this->line('');
            $this->line('  El tema Arix no esta instalado en este panel.');
            $this->line('  Este comando solo hace falta con Arix; con el panel normal el boton');
            $this->line('  sale de routes.ts y no hay que tocar nada mas.');
            $this->line('');

            return self::SUCCESS;
        }

        $accion = strtolower((string) $this->argument('accion'));

        if ($accion === '') {
            $this->estado();

            return self::SUCCESS;
        }

        $resultado = match ($accion) {
            'add', 'anadir', 'poner' => ArixTheme::anadirEnlace(),
            'remove', 'quitar' => ArixTheme::quitarEnlace(),
            default => null,
        };

        if ($resultado === null) {
            $this->error('Accion desconocida. Usa "add" o "remove".');

            return self::FAILURE;
        }

        $this->line('');

        if (!$resultado['ok']) {
            $this->error('  ' . $resultado['message']);
            $this->line('');

            return self::FAILURE;
        }

        $this->info('  ' . $resultado['message']);

        if ($accion !== 'remove' && $resultado['cambiado']) {
            $this->line('');
            $this->line('  Tus clientes lo veran al recargar (Ctrl+F5). Si quieres cambiarlo de');
            $this->line('  sitio, de icono o de nombre: Admin -> Arix -> Links.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function estado(): void
    {
        $puesto = ArixTheme::tieneEnlace();

        $this->line('');
        $this->line('  Tema Arix detectado.');
        $this->line('  Enlace en el menu del cliente: <fg=cyan>' . ($puesto ? 'puesto' : 'no puesto') . '</>');
        $this->line('');

        if ($puesto) {
            $this->line('  Recuerda que el enlace lleva a /dnsreverse, asi que la ruta tiene que');
            $this->line('  estar tambien compilada en el panel (install-frontend.sh). Compruebalo');
            $this->line('  con: php artisan dnsreverse:doctor');
        } else {
            $this->line('  Ponerlo:  php artisan dnsreverse:arix add');
        }

        $this->line('');
    }
}
