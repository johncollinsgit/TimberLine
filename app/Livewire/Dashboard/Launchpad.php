<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Models\Order;
use Illuminate\Support\Str;
use Livewire\Component;

class Launchpad extends Component
{
    public string $search = '';

    public function submitSearch()
    {
        return $this->redirect($this->resolveSearchUrl($this->search), navigate: true);
    }

    public function render()
    {
        return view('livewire.dashboard.launchpad', [
            'glance' => $this->todayAtGlance(),
            'popularActions' => $this->popularActions(),
        ])->layout('layouts.app', ['title' => 'Dashboard']);
    }

    private function todayAtGlance(): array
    {
        $waitingToPourStatuses = ['reviewed', 'submitted_to_pouring', 'pouring'];
        $waitingToShipStatuses = ['brought_down', 'verified'];
        $today = now()->startOfDay();
        $soon = now()->addWeeks(4)->endOfDay();

        $waitingToPour = Order::query()
            ->whereIn('status', $waitingToPourStatuses)
            ->count();

        $waitingToShip = Order::query()
            ->whereIn('status', $waitingToShipStatuses)
            ->count();

        $activeMarkets = Event::query()
            ->whereNotIn('status', ['cancelled', 'complete', 'completed'])
            ->where(function ($q) use ($today) {
                $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today->toDateString());
            })
            ->where(function ($q) use ($soon) {
                $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', $soon->toDateString());
            })
            ->count();

        return [
            'waiting_to_pour' => $waitingToPour,
            'waiting_to_ship' => $waitingToShip,
            'active_markets' => $activeMarkets,
        ];
    }

    private function popularActions(): array
    {
        $actions = [
            [
                'emoji' => '📦',
                'title' => 'Send Retail List to Pour Room',
                'description' => 'Open the retail queue and prepare the next pour batch.',
                'url' => route('retail.plan', ['queue' => 'retail']),
            ],
            [
                'emoji' => '🏪',
                'title' => 'Send Market Pour List',
                'description' => 'Generate or review market pour lists for upcoming events.',
                'url' => route('markets.lists.create'),
            ],
            [
                'emoji' => '👥',
                'title' => 'Add New User',
                'description' => 'Create and approve a new backstage account.',
                'url' => route('admin.users'),
            ],
            [
                'emoji' => '🚚',
                'title' => 'Shipping Room',
                'description' => 'Review shipping queues, calendar, and fulfillment status.',
                'url' => route('shipping.orders'),
            ],
            [
                'emoji' => '📊',
                'title' => 'View Analytics',
                'description' => 'Open metrics, widgets, and operational summaries.',
                'url' => route('analytics.index'),
            ],
            [
                'emoji' => '🛒',
                'title' => 'Wholesale Orders',
                'description' => 'Jump to the wholesale queue in the retail/pour planner.',
                'url' => route('retail.plan', ['queue' => 'wholesale']),
            ],
            [
                'emoji' => '🗓',
                'title' => 'Create Market Event',
                'description' => 'Create an event and prefill ship/due dates for planning.',
                'url' => route('markets.browser.index'),
            ],
            [
                'emoji' => '⚙',
                'title' => 'Manage Settings',
                'description' => 'Open administration tools, users, imports, and configuration.',
                'url' => route('admin.index'),
            ],
        ];

        if (auth()->user()?->canAccessMarketing()) {
            array_unshift($actions, [
                'emoji' => '💬',
                'title' => 'Send Message to All Opted-In Customers',
                'description' => 'Quick send to all SMS/email subscribers.',
                'url' => route('marketing.send.all-opted-in'),
            ]);
        }

        return $actions;
    }

    private function resolveSearchUrl(?string $input): string
    {
        $normalized = Str::of((string) $input)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            return route('analytics.index');
        }

        if ($this->matchesAny($normalized, ['send retail', 'retail pour', 'retail'])) {
            return route('retail.plan', ['queue' => 'retail']);
        }

        if ($this->matchesAny($normalized, ['market pour', 'event pour', 'market'])) {
            return route('markets.lists.create');
        }

        if ($this->matchesAny($normalized, ['wholesale'])) {
            return route('retail.plan', ['queue' => 'wholesale']);
        }

        if ($this->matchesAny($normalized, ['new user', 'add user', 'create user'])) {
            return route('admin.users');
        }

        if ($this->matchesAny($normalized, ['orders', 'shipping'])) {
            return route('shipping.orders');
        }

        if ($this->matchesAny($normalized, ['stats', 'analytics', 'metrics'])) {
            return route('analytics.index');
        }

        if (auth()->user()?->canAccessMarketing() && $this->matchesAny($normalized, ['message all', 'send message', 'text all', 'email all', 'opted in'])) {
            return route('marketing.send.all-opted-in');
        }

        if ($this->matchesAny($normalized, ['event', 'market event'])) {
            return route('markets.browser.index');
        }

        return route('analytics.index');
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, Str::of($needle)->lower()->squish()->value())) {
                return true;
            }
        }

        return false;
    }
}
