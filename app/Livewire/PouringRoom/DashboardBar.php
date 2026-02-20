<?php

namespace App\Livewire\PouringRoom;

use App\Services\Pouring\PouringQueueService;
use Livewire\Component;

class DashboardBar extends Component
{
    public bool $enabled = true;

    public function mount(): void
    {
        $prefs = is_array(auth()->user()?->ui_preferences ?? null) ? auth()->user()->ui_preferences : [];
        $this->enabled = (bool) ($prefs['pouring_dashboard_enabled'] ?? true);
    }

    public function toggle(): void
    {
        $this->enabled = !$this->enabled;
        $user = auth()->user();
        if ($user) {
            $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];
            $prefs['pouring_dashboard_enabled'] = $this->enabled;
            $user->forceFill(['ui_preferences' => $prefs])->save();
        }
    }

    public function render()
    {
        $summary = app(PouringQueueService::class)->stackSummary();
        $totalUnits = collect($summary)->sum(fn ($s) => $s['units'] ?? 0);
        $pending = collect($summary)->sum(fn ($s) => $s['pending_publish'] ?? 0);

        return view('livewire.pouring-room.dashboard-bar', [
            'summary' => $summary,
            'totalUnits' => $totalUnits,
            'pendingPublish' => $pending,
        ]);
    }
}
