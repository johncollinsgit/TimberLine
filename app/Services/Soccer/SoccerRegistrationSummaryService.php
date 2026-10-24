<?php

namespace App\Services\Soccer;

class SoccerRegistrationSummaryService
{
    public function __construct(private SoccerStore $store) {}

    public function forEvent(int $tenant, object $event): array
    {
        $season = $this->store->row('seasons', $tenant, $event->season_id);
        $family = $this->store->row('families', $tenant, $event->family_id);
        $profile = $this->store->decrypt($family->profile);
        $data = $this->store->decrypt($event->data);
        $rows = $this->store->query('registrations', $tenant)->where('season_id', $season->id)->whereIn('status', ['registered', 'offered', 'waitlisted', 'payment_pending'])->get();
        $players = $this->store->query('players', $tenant)->whereIn('id', $rows->pluck('player_id'))->get()->keyBy('id');
        $placed = $rows->whereIn('status', ['registered', 'offered']);
        $paid = $placed->where('payment_status', 'paid')->where('waived', false);
        $unpaid = $rows->whereIn('status', ['offered', 'payment_pending'])->where('payment_status', 'unpaid')->where('waived', false);
        $invoices = $this->store->query('invoices', $tenant)->where('season_id', $season->id)->where('status', 'paid')->get();
        $received = (int) $invoices->sum('amount_cents');
        $refunded = (int) $invoices->sum('refunded_cents');
        $describe = fn ($row) => [
            'name' => $players[$row->player_id]->name ?? 'Player',
            'team' => config('soccer.teams.'.$row->team_key.'.name', $row->team_key),
            'status' => $row->status === 'payment_pending' ? 'Payment pending · application saved' : ($row->status === 'waitlisted' ? 'Waitlisted' : ($row->waived ? 'Fee waived' : ($row->payment_status === 'paid' ? 'Paid' : 'Payment due'))),
        ];
        $groups = [];
        foreach (json_decode($season->teams, true) as $key => $team) {
            $members = $rows->where('team_key', $key);
            $groups[] = [
                'name' => config('soccer.teams.'.$key.'.name', $key),
                'placed' => $members->whereIn('status', ['registered', 'offered'])->count(),
                'paid' => $paid->where('team_key', $key)->count(),
                'unpaid' => $unpaid->where('team_key', $key)->count(),
                'waived' => $members->where('status', 'registered')->where('waived', true)->count(),
                'waiting' => $members->where('status', 'waitlisted')->count(),
                'children' => $members->map($describe)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            ];
        }

        return [
            'headline' => match ($event->action) {
                'payment_confirmed' => 'Payment received',
                'organizer_fee_waived' => 'Registration fees waived',
                default => 'New family registration',
            },
            'season' => $season->name,
            'parent' => $profile['parentName'] ?? 'Parent / guardian',
            'email' => $family->email,
            'children' => $rows->where('family_id', $family->id)->when(! empty($data['registration_ids']), fn ($collection) => $collection->whereIn('id', $data['registration_ids']))->map($describe)->values()->all(),
            'groups' => $groups,
            'totals' => [
                'families' => $rows->pluck('family_id')->unique()->count(),
                'children' => $placed->count(),
                'paid' => $paid->count(),
                'unpaid' => $unpaid->count(),
                'waived' => $placed->where('waived', true)->count(),
                'waiting' => $rows->where('status', 'waitlisted')->count(),
                'families_paid' => $paid->pluck('family_id')->unique()->count(),
                'families_unpaid' => $unpaid->pluck('family_id')->unique()->count(),
                'received_cents' => $received,
                'refunded_cents' => $refunded,
                'net_cents' => $received - $refunded,
            ],
            'updated_at' => now('America/New_York')->format('M j, Y · g:i A T'),
            'workspace_url' => rtrim((string) config('app.url'), '/').'/soccer/teams?tenant=wren-family-soccer',
        ];
    }
}
