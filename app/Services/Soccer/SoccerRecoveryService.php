<?php

namespace App\Services\Soccer;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Saved registrations survive an expired reservation; recovery never declares payment. */
class SoccerRecoveryService
{
    public function __construct(private SoccerStore $store) {}

    public function pending(int $tenant, string $season, string $family)
    {
        return $this->store->query('registrations', $tenant)->where('season_id', $season)->where('family_id', $family)
            ->where('payment_status', 'unpaid')->where('status', 'payment_pending')->get();
    }

    /** Do not let old timer-generated waitlist rows masquerade as capacity waits. */
    public function normalize(int $tenant, string $season): void
    {
        $expired = $this->store->query('invoices', $tenant)->where('season_id', $season)->where('status', 'expired')->pluck('offer_group');
        $this->store->query('registrations', $tenant)->where('season_id', $season)->whereIn('offer_group', $expired)
            ->where('status', 'waitlisted')->where('auto_eligible', false)->where('payment_status', 'unpaid')
            ->update(['status' => 'payment_pending', 'seat_kind' => null, 'offer_expires_at' => null, 'updated_at' => now()]);
    }

    /** Returns the original invoice history for these exact saved registrations. */
    public function invoicesFor(int $tenant, string $season, string $family, array $ids)
    {
        return $this->store->query('invoices', $tenant)->where('season_id', $season)->where('family_id', $family)->get()
            ->filter(fn ($i) => array_intersect($ids, json_decode($i->registration_ids, true) ?: []));
    }

    public function needsReview(int $tenant, string $season, string $family, array $ids): bool
    {
        $invoices = $this->invoicesFor($tenant, $season, $family, $ids);
        if ($this->store->query('payments', $tenant)->whereIn('invoice_id', $invoices->pluck('id'))->exists()) {
            return true;
        }
        foreach ($invoices as $invoice) {
            try {
                app(SoccerPayPalService::class)->assertNoInFlightPayment($tenant, $invoice->id);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                return true;
            }
        }

        return false;
    }

    /** Preserve public queue order and the existing strict-majority sibling reserves. */
    public function allocation(object $season, string $family, $rows): array
    {
        $capacity = app(SoccerRegistrationService::class)->capacities($season);
        $queue = $this->store->query('registrations', $season->tenant_id)->where('season_id', $season->id)
            ->where('status', 'waitlisted')->where('auto_eligible', true)->where('family_id', '!=', $family)->get();
        $assigned = [];
        foreach ($rows as $row) {
            $earlier = $queue->contains(fn ($waiting) => $waiting->team_key === $row->team_key && $waiting->queue_sequence < $row->queue_sequence);
            $seat = ! $earlier && ($capacity[$row->team_key]['public_free'] ?? 0) > 0 ? 'public' : null;
            if ($seat) {
                $capacity[$row->team_key]['public_free']--;
            }
            $assigned[$row->id] = $seat;
        }
        $placed = $this->store->query('registrations', $season->tenant_id)->where('season_id', $season->id)
            ->where('family_id', $family)->whereIn('status', ['registered', 'offered'])->get();
        $otherWaiting = $this->store->query('registrations', $season->tenant_id)->where('season_id', $season->id)
            ->where('family_id', $family)->where('status', 'waitlisted')->whereNotIn('id', $rows->pluck('id'))->exists();
        $public = $placed->where('seat_kind', 'public')->count() + count(array_filter($assigned));
        $rest = $rows->filter(fn ($r) => ! $assigned[$r->id]);
        $fits = ! $otherWaiting && $public > ($placed->count() + $rows->count()) / 2;
        foreach ($rest->groupBy('team_key') as $team => $children) {
            $fits = $fits && ($capacity[$team]['reserved_free'] ?? 0) >= $children->count();
        }
        if ($fits) {
            foreach ($rest as $row) {
                $assigned[$row->id] = 'reserved';
            }
        }

        return $assigned;
    }

    public function summary(int $tenant, string $seasonId, string $family): array
    {
        $season = $this->store->row('seasons', $tenant, $seasonId);
        $rows = $this->pending($tenant, $seasonId, $family);
        $legacy = $this->store->query('registrations', $tenant)->where('season_id', $seasonId)->where('family_id', $family)
            ->where('status', 'waitlisted')->where('auto_eligible', false)->where('payment_status', 'unpaid')->get()
            ->filter(fn ($r) => $this->store->query('invoices', $tenant)->where('offer_group', $r->offer_group)->where('status', 'expired')->exists());
        $rows = $rows->merge($legacy);
        $review = $rows->isNotEmpty() && $this->needsReview($tenant, $seasonId, $family, $rows->pluck('id')->all());

        return ['season_id' => $seasonId, 'pending_count' => $rows->count(), 'needs_review' => $review,
            'can_resume' => (bool) $season->registration_open && $rows->isNotEmpty() && ! $review,
            'available_count' => $review || ! $season->registration_open ? 0 : count(array_filter($this->allocation($season, $family, $rows)))];
    }

    /** Reuse signed releases and saved prices; never insert duplicate registrations. */
    public function resume(int $tenant, string $seasonId, string $family): array
    {
        return DB::transaction(function () use ($tenant, $seasonId, $family) {
            $season = $this->store->lockSeason($tenant, $seasonId);
            abort_unless($season->registration_open, 422, 'Registration is closed. Your application is saved.');
            app(SoccerRegistrationService::class)->expireOffers($tenant, $seasonId);
            $this->normalize($tenant, $seasonId);
            app(SoccerRegistrationService::class)->promote($tenant, $seasonId);
            $active = $this->store->query('invoices', $tenant)->where('season_id', $seasonId)->where('family_id', $family)
                ->where('status', 'unpaid')->where('expires_at', '>', now())->where('registration_ids', '!=', '[]')->first();
            if ($active) {
                abort_if($this->needsReview($tenant, $seasonId, $family, json_decode($active->registration_ids, true)), 409, 'A payment needs review. Contact Sarah before paying again.');

                return ['invoice_id' => $active->id, 'offered' => count(json_decode($active->registration_ids, true)), 'waiting' => 0];
            }
            $rows = $this->pending($tenant, $seasonId, $family);
            abort_if($rows->isEmpty(), 422, 'Your application is saved. Waiting children will receive an offer when spots are available.');
            abort_if($this->needsReview($tenant, $seasonId, $family, $rows->pluck('id')->all()), 409, 'A payment needs review. Contact Sarah before paying again.');
            foreach ($rows as $row) {
                $player = $this->store->row('players', $tenant, $row->player_id);
                app(SoccerEligibilityService::class)->assertEligible($tenant, $player, $season);
                abort_unless($row->release_id && $this->store->query('releases', $tenant)->where('id', $row->release_id)->where('family_id', $family)->where('player_id', $row->player_id)->where('season_key', $season->season_key)->whereNotNull('signed_at')->exists(), 422, 'This season’s signed release needs review. Contact Sarah.');
            }
            foreach ($rows as $row) {
                $release = $this->store->row('releases', $tenant, $row->release_id);
                $birthday = $this->store->decrypt($release->answers)['child1Birthday'] ?? null;
                $player = $this->store->row('players', $tenant, $row->player_id);
                abort_unless(! $birthday || $birthday === $player->birthday, 422, 'Review this season’s release after the birthday correction. Contact Sarah.');
            }
            foreach ($this->invoicesFor($tenant, $seasonId, $family, $rows->pluck('id')->all()) as $old) {
                app(SoccerPaymentService::class)->retireStripeCheckout($tenant, $old->id);
            }
            abort_if($this->needsReview($tenant, $seasonId, $family, $rows->pluck('id')->all()), 409, 'A payment needs review. Contact Sarah before paying again.');
            $allocation = $this->allocation($season, $family, $rows);
            $offered = [];
            foreach ($rows as $row) {
                $seat = $allocation[$row->id];
                $this->store->query('registrations', $tenant)->where('id', $row->id)->update(['status' => 'waitlisted', 'seat_kind' => $seat, 'auto_eligible' => true, 'updated_at' => now()]);
                if ($seat) {
                    $offered[] = $row->id;
                }
            }
            $invoice = $offered ? app(SoccerRegistrationService::class)->offer($season, $family, $offered, null, 'resumed_registration') : null;
            app(SoccerEvents::class)->record($tenant, $seasonId, $family, 'application_resumed', ['registration_ids' => $rows->pluck('id')->all(), 'invoice_id' => $invoice, 'summary' => count($offered).' places offered from the saved application.']);

            return ['invoice_id' => $invoice, 'offered' => count($offered), 'waiting' => $rows->count() - count($offered)];
        }, 3);
    }

    public function reminderEligible(int $tenant, object $event): bool
    {
        $season = $this->store->query('seasons', $tenant)->where('id', $event->season_id)->first();
        if (! config('soccer.payment_reminders_enabled') || ! $season?->registration_open) {
            return false;
        }
        $rows = $this->store->query('registrations', $tenant)->where('season_id', $season->id)->where('family_id', $event->family_id)
            ->where('payment_status', 'unpaid')->whereIn('status', ['offered', 'payment_pending'])->get();
        if ($rows->isEmpty() || $this->needsReview($tenant, $season->id, $event->family_id, $rows->pluck('id')->all())) {
            return false;
        }

        return $rows->contains(fn ($r) => $r->status === 'offered' && CarbonImmutable::parse($r->offer_expires_at)->gt(now()))
            || count(array_filter($this->allocation($season, $event->family_id, $rows->where('status', 'payment_pending')))) > 0;
    }

    public function remind(int $tenant, string $seasonId): void
    {
        $season = $this->store->row('seasons', $tenant, $seasonId);
        if (! config('soccer.payment_reminders_enabled') || ! $season->registration_open) {
            return;
        }
        $families = $this->store->query('registrations', $tenant)->where('season_id', $seasonId)->where('payment_status', 'unpaid')
            ->whereIn('status', ['offered', 'payment_pending'])->get()->groupBy('family_id');
        foreach ($families as $family => $rows) {
            $event = (object) ['season_id' => $seasonId, 'family_id' => $family];
            if ($rows->max('created_at') > now()->subDay()->toDateTimeString() || ! $this->reminderEligible($tenant, $event)) {
                continue;
            }
            $recent = $this->store->query('events', $tenant)->where('season_id', $seasonId)->where('family_id', $family)
                ->whereIn('action', ['registration_payment_reminder', 'payment_reminder', 'initial_registration', 'resumed_registration', 'waitlist_offer', 'manual_waitlist_offer'])
                ->where('created_at', '>', now()->subDay())->exists();
            if ($recent) {
                continue;
            }
            app(SoccerEvents::class)->record($tenant, $seasonId, $family, 'registration_payment_reminder', ['summary' => 'Saved application payment reminder.'],
                key: 'payment-followup:'.$seasonId.':'.$family.':'.now('America/New_York')->toDateString(),
                familyText: 'Wren Soccer: your saved registration is incomplete because payment has not been confirmed. Sign in to resume and check current availability: '.config('soccer.origin').'/spring?resume='.$seasonId."\n".'Do not pay again if you already sent payment; contact Sarah at 678-644-1829 to have it matched.');
        }
    }
}
