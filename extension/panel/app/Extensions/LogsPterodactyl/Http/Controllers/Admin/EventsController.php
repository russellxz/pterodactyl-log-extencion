<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Registro propio de la extension: que ha hecho y cuando.
 */
class EventsController extends Controller
{
    public function index(Request $request)
    {
        $channel = (string) $request->query('channel', '');
        $level = (string) $request->query('level', '');
        $search = trim((string) $request->query('search', ''));

        $events = ExtensionEvent::query()
            ->when($channel !== '', fn ($q) => $q->where('channel', $channel))
            ->when($level !== '', fn ($q) => $q->where('level', $level))
            ->when($search !== '', fn ($q) => $q->where('message', 'like', '%' . $search . '%'))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('logspterodactyl::admin.events', [
            'events' => $events,
            'channel' => $channel,
            'level' => $level,
            'search' => $search,
            'channels' => ExtensionEvent::query()->distinct()->orderBy('channel')->pluck('channel')->all(),
        ]);
    }
}
