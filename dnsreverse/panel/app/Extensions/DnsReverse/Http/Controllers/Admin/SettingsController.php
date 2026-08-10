<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Pterodactyl\Extensions\DnsReverse\DnsReverseServiceProvider;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Configuracion general de la extension.
 *
 * Lo especifico de cada dominio (token, certificado, reglas) NO esta aqui:
 * vive en la ficha de cada dominio. Aqui solo lo que afecta a todo.
 */
class SettingsController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function index(): View
    {
        return view('dnsreverse::admin.settings', [
            'ajustes' => $this->settings->all(),
            'bloqueados' => $this->settings->blockedDomains(),
            'version' => DnsReverseServiceProvider::VERSION,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'default_proxy_limit' => ['required', 'integer', 'min:0', 'max:100'],
            'dns_instructions' => ['nullable', 'string', 'max:2000'],
            'dns_instruction_source' => ['required', 'in:ip,alias'],
            'letsencrypt_renew_days' => ['required', 'integer', 'min:1', 'max:89'],
            'blocked_domains' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->settings->setMany([
            'default_proxy_limit' => (int) $datos['default_proxy_limit'],
            'dns_instructions' => $datos['dns_instructions'] ?? '',
            'dns_instruction_source' => $datos['dns_instruction_source'],
            'letsencrypt_renew_days' => (int) $datos['letsencrypt_renew_days'],
            'blocked_domains' => $datos['blocked_domains'] ?? '',
            'letsencrypt_enabled' => $request->boolean('letsencrypt_enabled') ? '1' : '0',
            'letsencrypt_auto_renew' => $request->boolean('letsencrypt_auto_renew') ? '1' : '0',
            'allow_custom_domains' => $request->boolean('allow_custom_domains') ? '1' : '0',
        ]);

        DnsEvent::record('info', 'settings.update', 'Configuracion guardada');

        return redirect()->route('admin.dnsreverse.settings')
            ->with('dnsreverse_success', 'Configuracion guardada.');
    }
}
