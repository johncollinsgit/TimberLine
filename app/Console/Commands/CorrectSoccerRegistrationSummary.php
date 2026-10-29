<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Soccer\SoccerDeliveryService;
use App\Services\Soccer\SoccerEvents;
use App\Services\Soccer\SoccerRegistrationSummaryService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CorrectSoccerRegistrationSummary extends Command
{
    protected $signature = 'soccer:correct-registration-summary {delivery : Original accepted admin delivery ID} {--recipient= : Exact independently verified original recipient} {--apply : Send this one correction through the existing mailer}';

    protected $description = 'Preview or send one replay-safe correction to an accepted Wren organizer summary';

    public function handle(SoccerStore $store, SoccerRegistrationSummaryService $summaries, SoccerEvents $events, SoccerDeliveryService $sender): int
    {
        $tenant = Tenant::where('slug', config('soccer.tenant_slug'))->firstOrFail()->id;
        $original = $store->row('deliveries', $tenant, $this->argument('delivery'));
        $payload = $store->decrypt($original->payload);
        $recipient = strtolower(trim((string) $this->option('recipient')));
        $allowed = array_map(fn ($email) => strtolower(trim($email)), config('soccer.admin_notification_emails', []));
        if ($original->status !== 'accepted' || ! $original->provider_message_id
            || ($payload['channel'] ?? null) !== 'admin_email' || $recipient === ''
            || ($payload['email'] ?? null) !== $recipient || ! in_array($recipient, $allowed, true)) {
            $this->error('Requires an accepted organizer summary and its verified, still-allowed original recipient.');

            return self::FAILURE;
        }
        $source = $store->row('events', $tenant, $original->event_id);
        if (! in_array($source->action, ['family_registration_submitted', 'application_amended', 'payment_confirmed', 'organizer_fee_waived'], true)) {
            $this->error('Only an original registration summary can be corrected.');

            return self::FAILURE;
        }
        $key = 'summary-correction:'.$original->id;
        $prior = $store->query('events', $tenant)->where('event_key', $key)->first();
        if ($prior) {
            $delivery = $store->query('deliveries', $tenant)->where('event_id', $prior->id)->sole();
            $this->line('Existing correction '.$delivery->id.': '.$delivery->status.'; provider '.($delivery->provider_message_id ?? 'none').'. No new send attempted.');

            return $delivery->status === 'accepted' ? self::SUCCESS : self::FAILURE;
        }
        $summary = $summaries->forEvent($tenant, $source);
        $this->line('Original logical message: soccer-email:'.$tenant.':'.$original->id);
        $this->line('Recipient: '.$recipient);
        $this->line('Family: '.$summary['parent'].'; season: '.$summary['season']);
        foreach ($summary['children'] as $child) {
            $this->line($child['name'].' / '.$child['team'].' / '.$child['status']);
        }
        $this->line(json_encode($summary['totals'], JSON_THROW_ON_ERROR));
        if (! $this->option('apply')) {
            $this->info('Preview only. No delivery or registration changed.');

            return self::SUCCESS;
        }
        $delivery = DB::transaction(function () use ($tenant, $source, $original, $recipient, $key, $store, $events) {
            $store->lockSeason($tenant, $source->season_id);
            if ($prior = $store->query('events', $tenant)->where('event_key', $key)->first()) {
                return null;
            }
            $data = $store->decrypt($source->data);
            $event = $events->record($tenant, $source->season_id, $source->family_id, 'registration_summary_corrected', [
                'original_delivery_id' => $original->id, 'original_event_id' => $source->id,
                'registration_ids' => $data['registration_ids'] ?? [],
            ], key: $key);

            return $store->insert('deliveries', $tenant, ['event_id' => $event,
                'recipient_key' => hash('sha256', 'admin_email:'.$recipient),
                'payload' => $store->encrypt(['channel' => 'admin_email', 'email' => $recipient])]);
        }, 3);
        if (! $delivery) {
            $this->error('A correction was reserved concurrently. Inspect its existing outcome; no new send attempted.');

            return self::FAILURE;
        }
        $sender->run($tenant, $delivery);
        $result = $store->row('deliveries', $tenant, $delivery);
        $this->line('Correction '.$result->id.': '.$result->status.'; provider '.($result->provider_message_id ?? 'none').'.');

        return $result->status === 'accepted' ? self::SUCCESS : self::FAILURE;
    }
}
