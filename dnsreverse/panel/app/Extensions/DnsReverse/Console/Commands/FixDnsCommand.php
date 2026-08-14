<?php

namespace Pterodactyl\Extensions\DnsReverse\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\CloudflareClient;
use Pterodactyl\Extensions\DnsReverse\Services\ProxyManager;

/**
 * Repara los registros DNS que faltan en Cloudflare.
 *
 * Para que sirve
 * --------------
 *
 * Un dominio puede estar creado en el panel, con su sitio montado en el nodo y
 * su certificado, y aun asi no cargar: el navegador dice "no se puede acceder a
 * este sitio, revisa que no haya errores de ortografia"
 * (DNS_PROBE_FINISHED_NXDOMAIN). Eso pasa cuando el registro no llego a
 * crearse en Cloudflare, y hasta ahora ocurria en silencio:
 *
 *   - el dominio del panel no tenia token de Cloudflare guardado
 *   - la zona estaba en Cloudflare pero sin activar (los nameservers del
 *     dominio no apuntaban a Cloudflare todavia)
 *   - el registro se borro a mano
 *
 * Este comando repasa todos los DNS guardados, comprueba en Cloudflare si el
 * registro esta puesto y apuntando a donde toca, y lo crea o lo corrige.
 *
 *     php artisan dnsreverse:fix-dns              (todos, preguntando)
 *     php artisan dnsreverse:fix-dns --force      (sin preguntar)
 *     php artisan dnsreverse:fix-dns --dry-run    (solo mirar, no tocar)
 *     php artisan dnsreverse:fix-dns --domain=ultraplus.click
 */
class FixDnsCommand extends Command
{
    protected $signature = 'dnsreverse:fix-dns
                            {--domain= : Solo los DNS de este dominio base}
                            {--dry-run : Solo comprobar, sin tocar nada}
                            {--force : No preguntar antes de corregir}';

    protected $description = 'Crea en Cloudflare los registros DNS que faltan o estan mal';

    public function handle(ProxyManager $proxies): int
    {
        $soloMirar = (bool) $this->option('dry-run');

        $consulta = ProxyRecord::query()
            ->with(['allocation', 'server'])
            ->where('proxy_type', '!=', ProxyRecord::TYPE_DOMAIN);

        if ($this->option('domain')) {
            $base = strtolower(trim((string) $this->option('domain')));
            $dominio = DnsDomain::query()->where('domain', $base)->first();

            if ($dominio === null) {
                $this->error('No hay ningun dominio base que se llame ' . $base . '.');

                return self::FAILURE;
            }

            $consulta->where(function ($q) use ($dominio) {
                $q->where('domain_id', $dominio->id)->orWhere('base_domain', $dominio->domain);
            });
        }

        $registros = $consulta->orderBy('domain')->get();

        if ($registros->isEmpty()) {
            $this->info('No hay DNS que revisar.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  Revisando ' . $registros->count() . ' DNS en Cloudflare...');
        $this->line('');

        // --- 1. Primero se mira, sin tocar nada ----------------------------

        $rotos = [];
        $bien = 0;
        $sinSaber = 0;

        foreach ($registros as $registro) {
            $estado = $this->revisar($registro);

            if ($estado['estado'] === 'ok') {
                $bien++;

                continue;
            }

            if ($estado['estado'] === 'desconocido') {
                $sinSaber++;
                $this->line('  <fg=yellow>[..]</> ' . $registro->domain . ': ' . $estado['detalle']);

                continue;
            }

            $rotos[] = $registro;
            $this->line('  <fg=red>[!!]</> ' . $registro->domain . ': ' . $estado['detalle']);
        }

        $this->line('');
        $this->line('  Bien: ' . $bien . '. Para arreglar: ' . count($rotos) . '. Sin poder comprobar: ' . $sinSaber . '.');
        $this->line('');

        if ($rotos === []) {
            $this->info('  No hay nada que reparar.');

            return self::SUCCESS;
        }

        if ($soloMirar) {
            $this->line('  (--dry-run: no se ha tocado nada)');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('¿Crear o corregir esos ' . count($rotos) . ' registros en Cloudflare?', true)) {
            $this->line('  Cancelado.');

            return self::SUCCESS;
        }

        // --- 2. Y ahora se arregla ------------------------------------------

        $this->line('');
        $arreglados = 0;
        $fallidos = 0;

        foreach ($rotos as $registro) {
            $resultado = $proxies->repararDns($registro);

            if ($resultado['ok']) {
                $arreglados++;
                $this->line('  <fg=green>[ok]</> ' . $registro->domain . ': ' . $resultado['message']);

                continue;
            }

            $fallidos++;
            $this->line('  <fg=red>[!!]</> ' . $registro->domain . ': ' . $resultado['message']);
        }

        $this->line('');
        $this->info('  Arreglados: ' . $arreglados . '. Con problemas: ' . $fallidos . '.');

        if ($arreglados > 0) {
            $this->line('');
            $this->line('  Los cambios de DNS tardan unos minutos en verse desde todas partes.');
            $this->line('  Si un dominio sigue sin cargar dentro de 10 minutos, mira el detalle con');
            $this->line('  el boton "Revisar" de ese DNS en el area de administracion.');
        }

        if ($fallidos > 0) {
            $this->line('');
            $this->line('  Lo que suele fallar:');
            $this->line('   - el dominio del panel no tiene token de Cloudflare (pestana Dominios)');
            $this->line('   - el token no tiene permiso Zone.DNS -> Edit sobre esa zona');
            $this->line('   - la zona esta en Cloudflare pero sin activar: hay que cambiar los');
            $this->line('     nameservers del dominio en el registrador donde se compro');
        }

        $this->line('');

        return $fallidos > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{estado: string, detalle: string}
     */
    private function revisar(ProxyRecord $registro): array
    {
        $dominioBase = null;

        try {
            $dominioBase = $registro->domain_id
                ? DnsDomain::find($registro->domain_id)
                : DnsDomain::query()->where('domain', (string) $registro->base_domain)->first();
        } catch (\Throwable $e) {
            return ['estado' => 'desconocido', 'detalle' => $e->getMessage()];
        }

        if ($dominioBase === null) {
            return ['estado' => 'desconocido', 'detalle' => 'no esta enganchado a ningun dominio del panel'];
        }

        if (!$dominioBase->hasToken()) {
            return ['estado' => 'desconocido', 'detalle' => $dominioBase->domain . ' no tiene token de Cloudflare'];
        }

        $allocation = $registro->allocation;

        if ($allocation === null) {
            return ['estado' => 'desconocido', 'detalle' => 'sin puerto asignado'];
        }

        $cliente = CloudflareClient::for($dominioBase);
        $zona = $cliente->zonaLista();

        if (!$zona['ok']) {
            return ['estado' => 'desconocido', 'detalle' => $zona['message']];
        }

        $ip = trim((string) $allocation->ip);
        $encontrados = $cliente->findRecords((string) $registro->domain);

        if ($encontrados === []) {
            return ['estado' => 'roto', 'detalle' => 'no existe el registro en Cloudflare'];
        }

        foreach ($encontrados as $encontrado) {
            if (in_array($encontrado['type'], ['A', 'AAAA'], true) && $encontrado['content'] === $ip) {
                return ['estado' => 'ok', 'detalle' => 'correcto'];
            }
        }

        $vistos = [];

        foreach ($encontrados as $encontrado) {
            $vistos[] = $encontrado['type'] . ' -> ' . $encontrado['content'];
        }

        return [
            'estado' => 'roto',
            'detalle' => 'apunta a ' . implode(', ', $vistos) . ' y el servidor esta en ' . $ip,
        ];
    }
}
