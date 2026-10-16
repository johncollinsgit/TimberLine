<?php

namespace App\Services\Soccer;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SoccerRegistrationService
{
    public function __construct(private SoccerStore $store, private SoccerEvents $events) {}

    public function teamFor(string $birthday, string $starts): string
    {
        $dob = CarbonImmutable::parse($birthday)->startOfDay();
        $start = CarbonImmutable::parse($starts)->startOfDay();
        if ($dob->gt($start)) {
            throw ValidationException::withMessages(['birthday' => 'Birthday must be before the season starts.']);
        }
        $age = (int) $dob->diffInYears($start);
        foreach (config('soccer.teams') as $key => $t) {
            if ($age >= $t['min_age'] && ($t['max_age'] === null || $age <= $t['max_age'])) {
                return $key;
            }
        }
        throw ValidationException::withMessages(['birthday' => 'Players must be at least 3 on the first practice date.']);
    }

    public function capacities(object $season): array
    {
        $teams = json_decode($season->teams, true);
        $rows = $this->store->query('registrations', $season->tenant_id)->where('season_id', $season->id)->whereIn('status', ['offered', 'registered'])->get();
        foreach ($teams as $k => &$t) {
            $t['public_used'] = $rows->where('team_key', $k)->where('seat_kind', 'public')->count();
            $t['reserved_used'] = $rows->where('team_key', $k)->where('seat_kind', 'reserved')->count();
            $t['public_free'] = max(0, $t['public'] - $t['public_used']);
            $t['reserved_free'] = max(0, $t['reserved'] - $t['reserved_used']);
        }

        return $teams;
    }

    public function register(int $tenant, string $familyId, array $input): array
    {
        return DB::transaction(function () use ($tenant, $familyId, $input) {
            $season = $this->store->lockSeason($tenant, $input['season_id']);
            abort_unless($season->registration_open && $season->starts_on, 422, 'Registration is not open.');
            $family = $this->store->row('families', $tenant, $familyId);
            $contact = $this->store->decrypt($family->profile);
            $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
            abort_unless(! empty($contact['parentName']) && $normalizer->toE164($contact['phone'] ?? '') && ! empty($contact['emergencyContact']) && $normalizer->toE164($contact['emergencyPhone'] ?? ''), 422, 'Review your family and emergency contact details before registering.');
            $selected = $input['children'];
            $ids = array_column($selected, 'player_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['children' => 'Select each child once.']);
            }
            $players = $this->store->query('players', $tenant)->where('family_id', $familyId)->whereIn('id', $ids)->get()->keyBy('id');
            abort_unless($players->count() === count($ids), 403);
            $volunteer = (bool) ($input['volunteer'] ?? false);
            if ($volunteer && empty($input['coach_jersey_size'])) {
                throw ValidationException::withMessages(['coach_jersey_size' => 'Enter your coach jersey size.']);
            }
            if ($volunteer && (! in_array($input['coach_parent'] ?? null, ['mother', 'father'], true)
                || ! is_string($input['coach_name'] ?? null) || trim($input['coach_name']) === ''
                || ! is_array($input['coach_teams'] ?? null) || ! $input['coach_teams']
                || array_diff($input['coach_teams'], array_keys(config('soccer.teams')))
                || ($input['coach_available_8_of_10'] ?? false) !== true)) {
                throw ValidationException::withMessages(['volunteer' => 'Choose the coaching parent and teams, and confirm availability for 8 of 10 weeks.']);
            }
            $existing = $this->store->query('registrations', $tenant)->where('season_id', $season->id)->where('family_id', $familyId)->get();
            $newIds = array_diff($ids, $existing->pluck('player_id')->all());
            $seasonWaivers = $existing->where('waived', true)->where('status', '!=', 'cancelled')->pluck('player_id')->unique()->all();
            $remainingWaivers = $volunteer ? max(0, 2 - count($seasonWaivers)) : 0;
            $waiverIds = collect($selected)->filter(fn ($child) => in_array($child['player_id'], $newIds, true))
                ->sortByDesc(fn ($child) => (bool) $child['needs_jersey'])->take($remainingWaivers)
                ->pluck('player_id')->all();
            $profile = $this->store->decrypt($family->profile);
            $profile['coaching_by_season'][$season->season_key] = [
                'volunteer' => $volunteer,
                'role' => $volunteer ? 'assistant' : null,
                'parent' => $volunteer ? $input['coach_parent'] : null,
                'name' => $volunteer ? trim($input['coach_name']) : null,
                'teams' => $volunteer ? array_values(array_unique($input['coach_teams'])) : [],
                'available_8_of_10' => $volunteer && $input['coach_available_8_of_10'],
                'jersey_size' => $volunteer ? $input['coach_jersey_size'] : null,
            ];
            $profile['volunteer'] = $volunteer || ($profile['volunteer'] ?? false);
            if ($volunteer) {
                $profile['coach_jersey_size'] = $input['coach_jersey_size'];
            }
            $this->store->query('families', $tenant)->where('id', $familyId)->update(['profile' => $this->store->encrypt($profile), 'updated_at' => now()]);
            $capacity = $this->capacities($season);
            $pending = [];
            foreach ($selected as $child) {
                if (! in_array($child['player_id'], $newIds, true)) {
                    continue;
                }
                $p = $players[$child['player_id']];
                abort_unless($p->birthday, 422, 'Review and complete each player’s birthday.');
                $team = $this->teamFor($p->birthday, $season->age_as_of ?? $season->starts_on);
                $jersey = (bool) $child['needs_jersey'];
                if ($jersey && empty($child['jersey_size'])) {
                    throw ValidationException::withMessages(['jersey_size' => 'Choose a size for each new jersey.']);
                }
                $releases = $this->store->query('releases', $tenant)->where('player_id', $p->id)->where('family_id', $familyId)->where('season_key', $season->season_key)->whereNotNull('signed_at')->latest('signed_at')->get();
                $datedReleases = $releases->filter(fn ($row) => ! empty($this->store->decrypt($row->answers)['child1Birthday']));
                $release = $datedReleases->isNotEmpty()
                    ? $datedReleases->first(fn ($row) => $this->store->decrypt($row->answers)['child1Birthday'] === $p->birthday)
                    : $releases->first();
                abort_unless($release, 422, 'Review and sign this season’s release with the current birthday for every selected player.');
                $hasQueue = $this->store->query('registrations', $tenant)->where('season_id', $season->id)->where('team_key', $team)->where('status', 'waitlisted')->exists();
                $seat = ! $hasQueue && $capacity[$team]['public_free'] > 0 ? 'public' : null;
                if ($seat) {
                    $capacity[$team]['public_free']--;
                }
                $waived = $volunteer && in_array($p->id, $waiverIds, true);
                $pending[] = ['season_id' => $season->id, 'family_id' => $familyId, 'player_id' => $p->id, 'release_id' => $release->id, 'team_key' => $team, 'status' => 'waitlisted', 'seat_kind' => $seat, 'needs_jersey' => $jersey, 'jersey_size' => $jersey ? ($child['jersey_size'] ?? null) : null, 'waived' => $waived, 'amount_cents' => $waived ? 0 : ($jersey ? 5000 : 3000), 'payment_status' => $waived ? 'waived' : 'unpaid', 'queued_at' => now()];
            }
            $public = $existing->whereIn('status', ['registered', 'offered'])->where('seat_kind', 'public')->count() + count(array_filter($pending, fn ($r) => $r['seat_kind'] === 'public'));
            $total = $existing->where('status', '!=', 'cancelled')->count() + count($pending);
            if (! $pending) {
                return ['placed' => [], 'waitlisted' => []];
            }
            $priorWaiting = $existing->where('status', 'waitlisted');
            $rest = array_merge(array_values(array_filter($pending, fn ($r) => ! $r['seat_kind'])), $priorWaiting->map(fn ($r) => (array) $r)->all());
            $reserved = false;
            if ($total && $public > $total / 2 && ! $priorWaiting->contains(fn ($r) => ! $r->auto_eligible) && $this->reservesFit($rest, $capacity)) {
                $reserved = $priorWaiting->isNotEmpty();
                foreach ($pending as &$r) {
                    if (! $r['seat_kind']) {
                        $r['seat_kind'] = 'reserved';
                        $reserved = true;
                    }
                } unset($r);
            }
            $offered = [];
            if ($reserved) {
                foreach ($priorWaiting as $r) {
                    $this->store->query('registrations', $tenant)->where('id', $r->id)->update(['seat_kind' => 'reserved', 'updated_at' => now()]);
                    $offered[] = $r->id;
                }
            }
            $waiting = [];
            $sequence = (int) $season->next_queue_sequence;
            foreach ($pending as $r) {
                $r['queue_sequence'] = ++$sequence;
                $id = $this->store->insert('registrations', $tenant, $r);
                if ($r['seat_kind']) {
                    $offered[] = $id;
                } else {
                    $waiting[] = $id;
                }
            }
            $this->store->query('seasons', $tenant)->where('id', $season->id)->update(['next_queue_sequence' => $sequence]);
            if ($offered) {
                $this->offer($season, $familyId, $offered, null, 'initial_registration');
            }
            if ($waiting || $reserved) {
                $this->events->record($tenant, $season->id, $familyId, $reserved ? 'sibling_reserved_spots' : 'family_waitlisted', ['summary' => count($offered).' placed, '.count($waiting).' waiting. '.implode('; ', array_map(fn ($r) => $players[$r['player_id']]->name.' ('.config('soccer.teams.'.$r['team_key'].'.name').'): '.($r['seat_kind'] ? 'placed' : 'waiting'), $pending)), 'registration_ids' => array_merge($offered, $waiting)], waitlist: true);
            }

            return ['placed' => $offered, 'waitlisted' => $waiting];
        }, 3);
    }

    private function reservesFit(array $rows, array $capacity): bool
    {
        $needs = [];
        foreach ($rows as $r) {
            $needs[$r['team_key']] = ($needs[$r['team_key']] ?? 0) + 1;
        } foreach ($needs as $k => $n) {
            if ($capacity[$k]['reserved_free'] < $n) {
                return false;
            }
        }

        return true;
    }

    public function offer(object $season, string $family, array $ids, ?int $actor, string $action = 'waitlist_offer'): string
    {
        $rows = $this->store->query('registrations', $season->tenant_id)->where('season_id', $season->id)->where('family_id', $family)->whereIn('id', $ids)->where('status', 'waitlisted')->get();
        abort_unless($rows->count() === count($ids), 422, 'These players already have an offer or place.');
        $group = (string) \Illuminate\Support\Str::uuid();
        $expires = now()->addHours($season->offer_hours);
        $amount = $rows->sum('amount_cents');
        foreach ($rows as $r) {
            $this->store->query('registrations', $season->tenant_id)->where('id', $r->id)->update(['status' => $r->waived ? 'registered' : 'offered', 'offer_group' => $group, 'offer_expires_at' => $r->waived ? null : $expires, 'payment_status' => $r->waived ? 'waived' : 'unpaid', 'updated_at' => now()]);
        }
        $invoice = $this->store->insert('invoices', $season->tenant_id, ['season_id' => $season->id, 'family_id' => $family, 'offer_group' => $group, 'registration_ids' => json_encode($ids), 'amount_cents' => $amount, 'status' => $amount === 0 ? 'waived' : 'unpaid', 'expires_at' => $expires, 'paid_at' => $amount === 0 ? now() : null]);
        $text = $amount === 0 ? 'Wren Soccer: your children have places; your registration fees are waived.' : 'Wren Soccer: places are reserved for your children. Pay $'.number_format($amount / 100, 2).' by '.$expires->timezone('America/New_York')->format('M j, g:i A T').': '.config('soccer.origin').'/spring?pay='.$invoice;

        $this->events->record($season->tenant_id, $season->id, $family, $action, ['summary' => $rows->count().' children offered places.', 'invoice_id' => $invoice], $actor, waitlist: $action !== 'initial_registration', familyText: $text);

        return $invoice;
    }

    public function promote(int $tenant, string $seasonId): int
    {
        return DB::transaction(function () use ($tenant, $seasonId) {
            $s = $this->store->lockSeason($tenant, $seasonId);
            $promoted = 0;
            if (! $s->registration_open) {
                return 0;
            }
            do {
                $changed = false;
                $capacity = $this->capacities($s);
                $queue = $this->store->query('registrations', $tenant)->where('season_id', $s->id)->where('status', 'waitlisted')->orderBy('queue_sequence')->orderBy('id')->get();
                foreach ($queue->groupBy('family_id') as $family => $waiting) {
                    if ($waiting->contains(fn ($r) => ! $r->auto_eligible)) {
                        continue;
                    }
                    $head = true;
                    foreach ($waiting->groupBy('team_key') as $team => $children) {
                        $first = $queue->where('team_key', $team)->take($children->count());
                        if ($first->contains(fn ($r) => $r->family_id !== $family)) {
                            $head = false;
                            break;
                        }
                    } if (! $head) {
                        continue;
                    }
                    $allocation = [];
                    $free = $capacity;
                    foreach ($waiting as $r) {
                        $kind = $free[$r->team_key]['public_free'] > 0 ? 'public' : null;
                        if ($kind) {
                            $free[$r->team_key]['public_free']--;
                        } $allocation[] = ['id' => $r->id, 'team_key' => $r->team_key, 'seat_kind' => $kind];
                    }
                    $already = $this->store->query('registrations', $tenant)->where('season_id', $s->id)->where('family_id', $family)->whereIn('status', ['registered', 'offered'])->get();
                    $public = $already->where('seat_kind', 'public')->count() + count(array_filter($allocation, fn ($r) => $r['seat_kind'] === 'public'));
                    $total = $already->count() + $waiting->count();
                    $rest = array_filter($allocation, fn ($r) => ! $r['seat_kind']);
                    if ($rest && ! ($public > $total / 2 && $this->reservesFit($rest, $free))) {
                        continue;
                    }
                    foreach ($allocation as $r) {
                        $this->store->query('registrations', $tenant)->where('id', $r['id'])->update(['seat_kind' => $r['seat_kind'] ?? 'reserved', 'updated_at' => now()]);
                    }
                    $this->offer($s, $family, $waiting->pluck('id')->all(), null);
                    $promoted += $waiting->count();
                    $changed = true;
                    break;
                }
            } while ($changed);

            return $promoted;
        }, 3);
    }

    public function manualOffer(int $tenant, string $seasonId, string $family, array $assignments, int $actor): string
    {
        return DB::transaction(function () use ($tenant, $seasonId, $family, $assignments, $actor) {
            $s = $this->store->lockSeason($tenant, $seasonId);
            $cap = $this->capacities($s);
            $ids = [];
            foreach ($assignments as $a) {
                $r = $this->store->row('registrations', $tenant, $a['id']);
                abort_unless($r->season_id === $s->id && $r->family_id === $family && $r->status === 'waitlisted', 422);
                $k = $a['seat_kind'];
                abort_unless(in_array($k, ['public', 'reserved'], true) && $cap[$r->team_key][$k.'_free'] > 0, 422, 'No seat is available in the chosen pool.');
                $cap[$r->team_key][$k.'_free']--;
                $ids[] = $r->id;
                $this->store->query('registrations', $tenant)->where('id', $r->id)->update(['seat_kind' => $k, 'updated_at' => now()]);
            }

            return $this->offer($s, $family, $ids, $actor, 'manual_waitlist_offer');
        }, 3);
    }

    public function cancel(int $tenant, string $id, int $actor): void
    {
        DB::transaction(function () use ($tenant, $id, $actor) {
            $r = $this->store->row('registrations', $tenant, $id);
            $this->store->lockSeason($tenant, $r->season_id);
            if ($r->status === 'cancelled') {
                return;
            }
            abort_if($r->status === 'offered', 422, 'Withdraw the unpaid family offer first.');
            $this->store->query('registrations', $tenant)->where('id', $id)->update(['status' => 'cancelled', 'seat_kind' => null, 'offer_expires_at' => null, 'updated_at' => now()]);
            $this->events->record($tenant, $r->season_id, $r->family_id, 'registration_cancelled', ['summary' => 'A player was withdrawn; their seat is available.', 'registration_id' => $id], $actor, waitlist: true);
        });
        $r = $this->store->row('registrations', $tenant, $id);
        $this->promote($tenant, $r->season_id);
    }

    public function withdrawOffer(int $tenant, string $invoiceId, int $actor, string $reason): void
    {
        $season = DB::transaction(function () use ($tenant, $invoiceId, $actor, $reason) {
            $invoice = $this->store->row('invoices', $tenant, $invoiceId);
            $s = $this->store->lockSeason($tenant, $invoice->season_id);
            $invoice = $this->store->row('invoices', $tenant, $invoiceId);
            abort_unless($invoice->status === 'unpaid', 422, 'Only unpaid offers can be withdrawn.');
            $this->store->query('invoices', $tenant)->where('id', $invoiceId)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            $this->store->query('registrations', $tenant)->where('offer_group', $invoice->offer_group)->where('status', 'offered')->update(['status' => 'waitlisted', 'seat_kind' => null, 'auto_eligible' => false, 'offer_expires_at' => null, 'updated_at' => now()]);
            $this->events->record($tenant, $s->id, $invoice->family_id, 'offer_withdrawn', ['summary' => 'Family offer withdrawn. '.$reason, 'invoice_id' => $invoiceId], $actor, waitlist: true);

            return $s->id;
        });
        $this->promote($tenant, $season);
    }

    public function overrideTeam(int $tenant, string $id, string $team, string $reason, int $actor): void
    {
        DB::transaction(function () use ($tenant, $id, $team, $reason, $actor) {
            $r = $this->store->row('registrations', $tenant, $id);
            $season = $this->store->lockSeason($tenant, $r->season_id);
            $r = $this->store->row('registrations', $tenant, $id);
            abort_unless(isset(config('soccer.teams')[$team]) && in_array($r->status, ['waitlisted', 'registered'], true), 422);
            if ($r->team_key === $team) {
                return;
            }
            $capacity = $this->capacities($season);
            if ($r->status === 'registered') {
                abort_unless($capacity[$team][$r->seat_kind.'_free'] > 0, 422, 'Destination team is full.');
            }
            $this->store->query('registrations', $tenant)->where('id', $id)->update(['team_key' => $team, 'updated_at' => now()]);
            $this->events->record($tenant, $season->id, $r->family_id, 'placement_overridden', ['summary' => 'Player moved from '.$r->team_key.' to '.$team.'. '.$reason, 'registration_id' => $id], $actor, waitlist: true);
        });
        $r = $this->store->row('registrations', $tenant, $id);
        $this->promote($tenant, $r->season_id);
    }

    public function tick(int $tenant, string $seasonId): void
    {
        DB::transaction(function () use ($tenant, $seasonId) {
            $s = $this->store->lockSeason($tenant, $seasonId);
            $invoices = $this->store->query('invoices', $tenant)->where('season_id', $seasonId)->where('status', 'unpaid')->get();
            foreach ($invoices as $i) {
                $expires = CarbonImmutable::parse($i->expires_at);
                if ($expires->lte(now())) {
                    $this->store->query('invoices', $tenant)->where('id', $i->id)->update(['status' => 'expired', 'updated_at' => now()]);
                    $this->store->query('registrations', $tenant)->where('offer_group', $i->offer_group)->where('status', 'offered')->update(['status' => 'waitlisted', 'auto_eligible' => false, 'seat_kind' => null, 'offer_expires_at' => null, 'updated_at' => now()]);
                    $this->events->record($tenant, $seasonId, $i->family_id, 'offer_expired', ['summary' => 'Unpaid family offer expired.', 'invoice_id' => $i->id], key: 'expired:'.$i->id, waitlist: true, familyText: 'Wren Soccer: your unpaid offer expired. Your children remain on the waitlist. Contact Sarah to reactivate.');
                } else {
                    foreach ([24, 2] as $hours) {
                        if ($expires->lte(now()->addHours($hours))) {
                            $this->events->record($tenant, $seasonId, $i->family_id, 'payment_reminder', ['summary' => 'Payment deadline reminder.', 'invoice_id' => $i->id], key: 'reminder:'.$i->id.':'.$hours, familyText: 'Wren Soccer: pay your registration invoice before '.$expires->timezone('America/New_York')->format('M j, g:i A T').' to keep the reserved spots: '.config('soccer.origin').'/spring?pay='.$i->id);
                        }
                    }
                }
            }
        });
        $this->promote($tenant, $seasonId);
    }
}
