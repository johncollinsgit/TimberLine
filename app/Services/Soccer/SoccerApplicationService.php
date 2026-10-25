<?php

namespace App\Services\Soccer;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Amend only saved, unsettled registrations; confirmed places and receipts stay authoritative. */
class SoccerApplicationService
{
    public function __construct(private SoccerStore $store) {}

    private function editable(int $tenant, string $season, string $family)
    {
        return $this->store->query('registrations', $tenant)->where('season_id', $season)->where('family_id', $family)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereIn('status', ['offered', 'payment_pending'])->where('payment_status', 'unpaid');
                })->orWhere(function ($q) {
                    $q->where('status', 'waitlisted')->whereIn('payment_status', ['unpaid', 'waived']);
                });
            })->orderBy('queue_sequence')->orderBy('id')->get();
    }

    public function revision(int $tenant, object $season, string $family): string
    {
        $rows = $this->store->query('registrations', $tenant)->where('season_id', $season->id)->where('family_id', $family)
            ->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except(['created_at', 'updated_at'])->all())->all();
        $profile = $this->store->decrypt($this->store->row('families', $tenant, $family)->profile);

        return hash('sha256', json_encode([$rows, $profile['coaching_by_season'][$season->season_key] ?? null]));
    }

    private function rows(int $tenant, object $season, string $family, array $input)
    {
        $rows = $this->editable($tenant, $season->id, $family);
        abort_if($rows->isEmpty(), 422, 'There is no unpaid application to edit. Contact Sarah to change a confirmed registration.');
        $requested = array_column($input['children'], 'player_id');
        $expected = $rows->pluck('player_id')->all();
        sort($requested);
        sort($expected);
        abort_unless($requested === $expected, 409, 'Your saved players changed. Reopen Edit application & coaching before saving.');
        abort_if(app(SoccerRecoveryService::class)->needsReview($tenant, $season->id, $family, $rows->pluck('id')->all()), 409,
            'A payment is recorded or still being confirmed. Contact Sarah before changing fees.');

        return $rows;
    }

    private function prices(int $tenant, object $season, string $family, $rows, array $input): array
    {
        $confirmedWaivers = $this->store->query('registrations', $tenant)->where('season_id', $season->id)->where('family_id', $family)
            ->where('waived', true)->where('status', '!=', 'cancelled')->whereNotIn('id', $rows->pluck('id'))->count();
        $remaining = ! empty($input['volunteer']) ? max(0, 2 - $confirmedWaivers) : 0;
        $waivers = collect($input['children'])->sortByDesc(fn ($child) => (bool) $child['needs_jersey'])->take($remaining)->pluck('player_id')->all();
        $prices = [];
        foreach ($input['children'] as $child) {
            $waived = in_array($child['player_id'], $waivers, true);
            $prices[$child['player_id']] = ['amount_cents' => $waived ? 0 : ($child['needs_jersey'] ? 5000 : 3000), 'waived' => $waived];
        }

        return $prices;
    }

    /** Read-only quote preserves current holds and rechecks expired applications against queue order. */
    public function quote(int $tenant, string $family, array $input): array
    {
        $season = $this->store->row('seasons', $tenant, $input['season_id']);
        abort_unless($season->registration_open && $season->starts_on, 422, 'Registration is closed. Your saved application remains available.');
        $revision = $this->revision($tenant, $season, $family);
        abort_if(isset($input['expected_application_revision']) && ! hash_equals($revision, $input['expected_application_revision']), 409,
            'Your application changed in another session. Reopen it before saving.');
        $rows = $this->rows($tenant, $season, $family, $input);
        $prices = $this->prices($tenant, $season, $family, $rows, $input);
        $capacity = app(SoccerRegistrationService::class)->capacities($season);
        // capacities() includes our existing offers. Add them back in this estimate,
        // then preserve only holds that are still current before allocating other rows.
        foreach ($rows->where('status', 'offered') as $row) {
            if (in_array($row->seat_kind, ['public', 'reserved'], true)) {
                $capacity[$row->team_key][$row->seat_kind.'_free']++;
            }
        }
        $queue = $this->store->query('registrations', $tenant)->where('season_id', $season->id)->where('status', 'waitlisted')
            ->where('auto_eligible', true)->where('family_id', '!=', $family)->get();
        $assigned = [];
        foreach ($rows as $row) {
            $player = $this->store->row('players', $tenant, $row->player_id);
            app(SoccerEligibilityService::class)->assertEligible($tenant, $player, $season);
            abort_unless(app(SoccerRegistrationService::class)->teamFor($player->birthday, $season->age_as_of ?? $season->starts_on) === $row->team_key,
                422, 'This player’s age group needs review. Contact Sarah before changing the application.');
            $held = $row->status === 'offered' && $row->offer_expires_at && CarbonImmutable::parse($row->offer_expires_at)->gt(now())
                && in_array($row->seat_kind, ['public', 'reserved'], true);
            $assigned[$row->id] = $held ? $row->seat_kind : null;
            if ($held) {
                $capacity[$row->team_key][$row->seat_kind.'_free']--;
            }
        }
        foreach ($rows as $row) {
            if ($assigned[$row->id]) {
                continue;
            }
            $earlier = $queue->contains(fn ($waiting) => $waiting->team_key === $row->team_key && $waiting->queue_sequence < $row->queue_sequence);
            if (! $earlier && $capacity[$row->team_key]['public_free'] > 0) {
                $assigned[$row->id] = 'public';
                $capacity[$row->team_key]['public_free']--;
            }
        }
        $placed = $this->store->query('registrations', $tenant)->where('season_id', $season->id)->where('family_id', $family)
            ->whereIn('status', ['registered', 'offered'])->whereNotIn('id', $rows->pluck('id'))->get();
        $rest = $rows->filter(fn ($r) => ! $assigned[$r->id]);
        $public = $placed->where('seat_kind', 'public')->count() + count(array_filter($assigned, fn ($seat) => $seat === 'public'));
        $fits = $public > ($placed->count() + $rows->count()) / 2;
        foreach ($rest->groupBy('team_key') as $team => $children) {
            $fits = $fits && $capacity[$team]['reserved_free'] >= $children->count();
        }
        if ($fits) {
            foreach ($rest as $row) {
                $assigned[$row->id] = 'reserved';
            }
        }
        $children = $rows->map(fn ($row) => ['player_id' => $row->player_id, 'team_key' => $row->team_key, 'seat_kind' => $assigned[$row->id],
            'status' => $assigned[$row->id] ? 'available' : 'waitlisted', ...$prices[$row->player_id]])->all();

        return ['season_id' => $season->id, 'checked_at' => now()->toIso8601String(), 'application_revision' => $revision,
            'children' => $children, 'due_today_cents' => collect($children)->where('status', 'available')->sum('amount_cents')];
    }

    public function amend(int $tenant, string $family, array $input): array
    {
        $result = DB::transaction(function () use ($tenant, $family, $input) {
            $season = $this->store->lockSeason($tenant, $input['season_id']);
            // An exact transport retry reuses the saved result, including its invoice.
            $key = 'application-amend:'.hash('sha256', json_encode([$family, $input]));
            if ($previous = $this->store->query('events', $tenant)->where('event_key', $key)->first()) {
                return $this->store->decrypt($previous->data)['result'];
            }
            $quote = $this->quote($tenant, $family, $input);
            abort_unless(hash_equals($quote['application_revision'], $input['expected_application_revision']), 409,
                'Your application changed in another session. Reopen it before saving.');
            $available = collect($quote['children'])->where('status', 'available')->pluck('player_id')->sort()->values()->all();
            $expected = $input['expected_available_player_ids'];
            sort($expected);
            abort_unless((int) $input['expected_due_today_cents'] === (int) $quote['due_today_cents'] && $available === $expected, 409,
                'Spots or fees changed. Review the updated total before saving again.');
            $rows = $this->rows($tenant, $season, $family, $input);
            $choices = collect($input['children'])->keyBy('player_id');
            $profile = $this->store->decrypt($this->store->row('families', $tenant, $family)->profile);
            $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
            abort_unless(! empty($profile['parentName']) && $normalizer->toE164($profile['phone'] ?? '')
                && ! empty($profile['emergencyContact']) && $normalizer->toE164($profile['emergencyPhone'] ?? ''), 422, 'Review your family and emergency contact details.');
            $oldCoach = $profile['coaching_by_season'][$season->season_key] ?? [];
            $volunteer = (bool) $input['volunteer'];
            if ($volunteer) {
                $parent = $input['coach_parent'] ?? '';
                $phone = $profile[$parent === 'father' ? 'fatherPhone' : 'motherPhone'] ?? null;
                $phone = $phone ?: ($profile[$parent === 'father' ? 'spousePhone' : 'phone'] ?? null);
                abort_unless(in_array($parent, ['mother', 'father'], true) && ! empty(trim($input['coach_name'] ?? ''))
                    && ! empty($input['coach_jersey_size']) && ! empty($input['coach_teams'])
                    && ! array_diff($input['coach_teams'], array_keys(config('soccer.teams')))
                    && ($input['coach_available_8_of_10'] ?? false) && $normalizer->toE164($phone), 422, 'Complete the coaching details and confirm the coach’s phone number.');
            } elseif (! empty($oldCoach['volunteer']) && $this->store->query('registrations', $tenant)->where('season_id', $season->id)
                ->where('family_id', $family)->where('status', 'registered')->where('waived', true)->exists()) {
                abort(422, 'Contact Sarah to change coaching after waived places have been confirmed.');
            }
            $releases = [];
            foreach ($rows as $row) {
                $choice = $choices[$row->player_id];
                abort_if($choice['needs_jersey'] && empty($choice['jersey_size']), 422, 'Choose a size for each new jersey.');
                $player = $this->store->row('players', $tenant, $row->player_id);
                $candidates = $this->store->query('releases', $tenant)->where('family_id', $family)->where('player_id', $row->player_id)
                    ->where('season_key', $season->season_key)->whereNotNull('signed_at')->latest('signed_at')->get();
                $release = $candidates->first(fn ($r) => ($this->store->decrypt($r->answers)['child1Birthday'] ?? $player->birthday) === $player->birthday);
                abort_unless($release, 422, 'Review and sign this season’s release for each saved player.');
                $releases[$row->id] = $release->id;
            }
            $invoices = app(SoccerRecoveryService::class)->invoicesFor($tenant, $season->id, $family, $rows->pluck('id')->all());
            foreach ($invoices as $invoice) {
                $otherIds = array_diff(json_decode($invoice->registration_ids, true) ?: [], $rows->pluck('id')->all());
                $otherRows = $this->store->query('registrations', $tenant)->whereIn('id', $otherIds)->get();
                abort_unless($otherRows->count() === count($otherIds) && $otherRows->every(fn ($r) => $r->family_id === $family
                    && $r->season_id === $season->id && $r->status === 'registered' && $r->waived && $r->payment_status === 'waived'), 409,
                    'This invoice includes another registration that needs review. Contact Sarah before changing it.');
                // Keep any genuine racing payment committed even when it blocks the edit.
                if (app(SoccerPaymentService::class)->retireStripeCheckout($tenant, $invoice->id)) {
                    return ['payment_review' => true];
                }
            }
            if (app(SoccerRecoveryService::class)->needsReview($tenant, $season->id, $family, $rows->pluck('id')->all())) {
                return ['payment_review' => true];
            }
            $original = $rows->map(fn ($r) => collect((array) $r)->only(['id', 'status', 'amount_cents', 'waived', 'needs_jersey', 'jersey_size', 'offer_group'])->all())->all();
            $this->store->query('invoices', $tenant)->whereIn('id', $invoices->pluck('id'))->where('status', 'unpaid')
                ->update(['status' => 'expired', 'updated_at' => now()]);
            $byPlayer = collect($quote['children'])->keyBy('player_id');
            $offered = [];
            foreach ($rows as $row) {
                $child = $byPlayer[$row->player_id];
                $choice = $choices[$row->player_id];
                $this->store->query('registrations', $tenant)->where('id', $row->id)->update(['status' => 'waitlisted', 'seat_kind' => $child['seat_kind'],
                    'amount_cents' => $child['amount_cents'], 'waived' => $child['waived'], 'payment_status' => $child['waived'] ? 'waived' : 'unpaid',
                    'needs_jersey' => $choice['needs_jersey'], 'jersey_size' => $choice['needs_jersey'] ? $choice['jersey_size'] : null,
                    'release_id' => $releases[$row->id], 'offer_group' => null, 'offer_expires_at' => null, 'auto_eligible' => true, 'updated_at' => now()]);
                if ($child['seat_kind']) {
                    $offered[] = $row->id;
                }
            }
            $profile['coaching_by_season'][$season->season_key] = ['volunteer' => $volunteer, 'role' => $volunteer ? 'assistant' : null,
                'parent' => $volunteer ? $input['coach_parent'] : null, 'name' => $volunteer ? trim($input['coach_name']) : null,
                'teams' => $volunteer ? array_values(array_unique($input['coach_teams'])) : [], 'available_8_of_10' => $volunteer,
                'jersey_size' => $volunteer ? $input['coach_jersey_size'] : null];
            $profile['volunteer'] = $volunteer || ($profile['volunteer'] ?? false);
            if ($volunteer) {
                $profile['coach_jersey_size'] = $input['coach_jersey_size'];
            }
            $this->store->query('families', $tenant)->where('id', $family)->update(['profile' => $this->store->encrypt($profile), 'updated_at' => now()]);
            $invoiceId = $offered ? app(SoccerRegistrationService::class)->offer($season, $family, $offered, null, 'amended_registration') : null;
            $result = ['invoice_id' => $invoiceId, 'offered' => count($offered), 'waiting' => $rows->count() - count($offered)];
            app(SoccerEvents::class)->record($tenant, $season->id, $family, 'application_amended', ['registration_ids' => $rows->pluck('id')->all(),
                'original_registrations' => $original, 'previous_coaching' => $oldCoach, 'result' => $result,
                'summary' => 'Family amended its saved application and reviewed the recalculated fees.'], key: $key);

            return $result;
        }, 3);
        abort_if(! empty($result['payment_review']), 409, 'A card payment was received or needs review. Your payment was preserved; refresh before making changes.');

        return $result;
    }
}
