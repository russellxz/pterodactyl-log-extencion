<?php

namespace Pterodactyl\Extensions\DnsReverse\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;

/**
 * Elige como aparece el boton de DNS Reverse en el area de cliente.
 *
 *   php artisan dnsreverse:ui             ver como esta ahora
 *   php artisan dnsreverse:ui inject      modo inyectado (por defecto)
 *   php artisan dnsreverse:ui native      modo nativo (compilado con yarn)
 *
 * Los dos modos usan exactamente la misma pantalla y la misma API; lo unico
 * que cambia es de donde sale el boton.
 *
 * Este comando NO compila nada: solo enciende o apaga la parte inyectada. La
 * compilacion la hace install-frontend.sh, que llama aqui al terminar para que
 * el boton no salga dos veces.
 */
class UiModeCommand extends Command
{
    protected $signature = 'dnsreverse:ui {modo? : inject o native}';

    protected $description = 'Cambia como se muestra la pantalla del cliente (inyectada o compilada)';

    public function handle(Settings $ajustes): int
    {
        $modo = strtolower((string) $this->argument('modo'));

        if ($modo === '') {
            $this->mostrar($ajustes);

            return self::SUCCESS;
        }

        $modo = match ($modo) {
            'inject', 'inyectado', 'injected' => 'inject',
            'native', 'nativo', 'yarn' => 'native',
            default => '',
        };

        if ($modo === '') {
            $this->error('Modo desconocido. Usa "inject" o "native".');

            return self::FAILURE;
        }

        $ajustes->set('client_ui_enabled', $modo === 'inject' ? '1' : '0');

        $this->line('');

        if ($modo === 'native') {
            $this->info('  Modo nativo: el boton sale del panel compilado.');
            $this->line('  La pantalla inyectada queda apagada para que no salga dos veces.');
            $this->line('');
            $this->line('  Recuerda: despues de CADA actualizacion del panel hay que');
            $this->line('  volver a compilar o el boton desaparece:');
            $this->line('      sudo bash install-frontend.sh');
        } else {
            $this->info('  Modo inyectado: el boton lo pone el panel al vuelo, sin compilar.');
            $this->line('  Sobrevive a las actualizaciones del panel sin hacer nada.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function mostrar(Settings $ajustes): void
    {
        $inyectado = $ajustes->bool('client_ui_enabled');

        $this->line('');
        $this->line('  Modo actual: <fg=cyan>' . ($inyectado ? 'inyectado' : 'nativo') . '</>');
        $this->line('');

        if ($inyectado) {
            $this->line('  El boton "DNS Reverse" se anade a las paginas del cliente desde el');
            $this->line('  servidor. No hace falta compilar nada y aguanta las actualizaciones');
            $this->line('  del panel.');
        } else {
            $this->line('  La pantalla inyectada esta apagada. El boton tiene que venir del');
            $this->line('  panel compilado (install-frontend.sh). Si no lo compilaste, tus');
            $this->line('  clientes no ven la pantalla: vuelve al modo inyectado con');
            $this->line('      php artisan dnsreverse:ui inject');
        }

        $this->line('');
        $this->line('  Cambiar:  php artisan dnsreverse:ui inject');
        $this->line('            php artisan dnsreverse:ui native');
        $this->line('');
    }
}
