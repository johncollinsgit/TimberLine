<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Soccer\SoccerDeliveryService;
use App\Services\Soccer\SoccerRegistrationService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessSoccerSeason extends Command
{
    protected $signature = 'soccer:process';

    protected $description = 'Expire soccer offers, send reminders, promote eligible waiting families, and deliver queued messages.';

    public function handle(SoccerStore $store, SoccerRegistrationService $registrations, SoccerDeliveryService $deliveries): int
    {
        if (! config('soccer.enabled') || ! Schema::hasTable('soccer_seasons')) {
            return self::SUCCESS;
        }
        $t = Tenant::where('slug', config('soccer.tenant_slug'))->first();
        if (! $t || ! app(\App\Services\Soccer\SoccerAccess::class)->enabled($t)) {
            return self::SUCCESS;
        }app(\App\Support\Tenancy\TenantContext::class)->set($t->id);
        foreach ($store->query('seasons', $t->id)->where('season_key', 'spring-2027')->get() as $s) {
            if (! $s->registration_open && $s->starts_on && $s->registration_opens_at && \Carbon\CarbonImmutable::parse($s->registration_opens_at)->lte(now()) && app(\App\Services\Soccer\SoccerLaunchReadiness::class)->blockers($t) === []) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($store, $t, $s) {
                    $locked = $store->lockSeason($t->id, $s->id);
                    if (! $locked->registration_open && $locked->registration_opens_at) {
                        $store->query('seasons', $t->id)->where('id', $s->id)->update(['registration_open' => true, 'registration_opens_at' => null, 'updated_at' => now()]);
                        app(\App\Services\Soccer\SoccerEvents::class)->record($t->id, $s->id, null, 'registration_opened', ['summary' => 'Scheduled registration opened after readiness verification.']);
                    }
                });
            }
            $registrations->tick($t->id, $s->id);
        }
        $deliveries->run($t->id);

        return self::SUCCESS;
    }
}
