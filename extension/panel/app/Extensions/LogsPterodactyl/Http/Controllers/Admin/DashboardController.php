<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Pterodactyl\Extensions\LogsPterodactyl\LogsPterodactylServiceProvider;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\InstallEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\MailLog;
use Pterodactyl\Extensions\LogsPterodactyl\Services\InstallGuard;
use Pterodactyl\Extensions\LogsPterodactyl\Services\LogReader;
use Pterodactyl\Extensions\LogsPterodactyl\Services\PanelUpdater;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;

/**
 * Pantalla de inicio de la extension: el estado de todo de un vistazo.
 */
class DashboardController extends Controller
{
    public function __construct(
        private LogReader $reader,
        private InstallGuard $guard,
        private PanelUpdater $updater,
        private Settings $settings,
    ) {
    }

    public function index()
    {
        return view('logspterodactyl::admin.dashboard', [
            'version' => LogsPterodactylServiceProvider::VERSION,
            'settings' => $this->settings,
            'logs' => $this->logSummary(),
            'installs' => $this->installSummary(),
            'mails' => $this->mailSummary(),
            'panel' => [
                'current' => $this->updater->currentVersion(),
                'latest' => $this->updater->latestVersion()['version'],
                'available' => $this->updater->updateAvailable(),
                'theme' => $this->updater->themeInfo(),
            ],
            'recent' => ExtensionEvent::query()->orderByDesc('id')->limit(12)->get(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function logSummary(): array
    {
        $files = $this->reader->files();
        $path = $this->reader->resolve(null);

        $counts = ['total' => 0, 'error' => 0, 'critical' => 0, 'warning' => 0];

        if ($path !== null) {
            $result = $this->reader->read($path, ['limit' => 1]);
            $counts = array_merge($counts, $result['counts']);
        }

        return [
            'files' => count($files),
            'latest' => $path ? basename($path) : null,
            'size' => $files[0]['size'] ?? 0,
            'counts' => $counts,
            'serious' => (int) ($counts['error'] ?? 0)
                + (int) ($counts['critical'] ?? 0)
                + (int) ($counts['emergency'] ?? 0)
                + (int) ($counts['alert'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installSummary(): array
    {
        $installing = $this->guard->installing();
        $limit = $this->settings->int('watchdog_minutes', 1, 1440);

        $over = $installing->filter(fn (Server $s) => $this->guard->minutesInstalling($s) >= $limit);

        return [
            'installing' => $installing->count(),
            'over_limit' => $over->count(),
            'limit' => $limit,
            'enabled' => $this->settings->bool('watchdog_enabled'),
            'last_24h' => (int) InstallEvent::query()->where('started_at', '>=', now()->subDay())->count(),
            'failed_24h' => (int) InstallEvent::query()
                ->where('started_at', '>=', now()->subDay())
                ->whereIn('status', [InstallEvent::STATUS_FAILED, InstallEvent::STATUS_TIMEOUT])
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function mailSummary(): array
    {
        return [
            'sent_24h' => (int) MailLog::query()->where('created_at', '>=', now()->subDay())->where('status', MailLog::STATUS_SENT)->count(),
            'failed_24h' => (int) MailLog::query()->where('created_at', '>=', now()->subDay())->where('status', MailLog::STATUS_FAILED)->count(),
            'failed_total' => (int) MailLog::query()->where('status', MailLog::STATUS_FAILED)->count(),
        ];
    }
}
