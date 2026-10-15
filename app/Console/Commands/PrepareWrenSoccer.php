<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Soccer\SoccerImportService;
use App\Services\Soccer\SoccerStore;
use App\Services\Tenancy\LandlordCommercialConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareWrenSoccer extends Command
{
    protected $signature = 'soccer:prepare-wren {--actor=1} {--spring=} {--fall=} {--releases=} {--groupme=} {--starts-on=} {--age-as-of=} {--opens-at=} {--apply}';

    protected $description = 'Preview or reconcile Wren historical soccer records and prepare a closed Spring 2027 season.';

    public function handle(SoccerStore $store, SoccerImportService $import): int
    {
        $t = Tenant::where('slug', config('soccer.tenant_slug'))->sole();
        $actor = User::findOrFail((int) $this->option('actor'));
        abort_unless($actor->tenants()->whereKey($t->id)->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'tenant_owner', 'admin'])->exists(), 403);
        DB::beginTransaction();
        try {
            app(LandlordCommercialConfigService::class)->setTenantModuleEntitlement($t->id, 'soccer', ['availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'price_override_cents' => 0, 'currency' => 'USD', 'entitlement_source' => 'owner_requested_soccer', 'notes' => 'Wren soccer operations; registration stays closed until readiness verified.', 'metadata' => ['billing_impact_cents' => 0]], $actor->id);
            $profile = TenantAccessProfile::where('tenant_id', $t->id)->firstOrFail();
            $profile->update(['metadata' => [...($profile->metadata ?? []), 'workspace_focus' => 'soccer']]);
            foreach (['spring-2026' => 'Spring 2026', 'fall-2026' => 'Fall 2026', 'spring-2027' => 'Spring 2027'] as $key => $name) {
                if (! $store->query('seasons', $t->id)->where('season_key', $key)->exists()) {
                    $store->insert('seasons', $t->id, ['season_key' => $key, 'name' => $name, 'teams' => json_encode(config('soccer.teams'))]);
                }
            }
            if ($start = $this->option('starts-on')) {
                abort_unless(preg_match('/^2027-\d{2}-\d{2}$/', $start) && \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $start)->format('Y-m-d') === $start, 422);
                $store->query('seasons', $t->id)->where('season_key', 'spring-2027')->update(['starts_on' => $start, 'updated_at' => now()]);
                app(\App\Services\Soccer\SoccerEvents::class)->record($t->id, null, null, 'season_start_confirmed', ['summary' => 'Spring 2027 first practice: '.$start], $actor->id);
            }
            if ($cutoff = $this->option('age-as-of')) {
                abort_unless(\Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $cutoff)->format('Y-m-d') === $cutoff, 422);
                $store->query('seasons', $t->id)->where('season_key', 'spring-2027')->update(['age_as_of' => $cutoff, 'updated_at' => now()]);
            }
            if ($opens = $this->option('opens-at')) {
                $when = \Carbon\CarbonImmutable::parse($opens, 'America/New_York')->utc();
                $store->query('seasons', $t->id)->where('season_key', 'spring-2027')->update(['registration_opens_at' => $when, 'updated_at' => now()]);
                app(\App\Services\Soccer\SoccerEvents::class)->record($t->id, null, null, 'registration_opening_scheduled', ['summary' => 'Registration opens '.$when->timezone('America/New_York')->format('M j, Y g:i A T').' after provider verification.'], $actor->id);
            }
            $report = ['apply' => (bool) $this->option('apply'), 'registration_open' => false];
            if ($path = $this->option('groupme')) {
                $mapping = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                abort_unless(array_keys($mapping['groupme_groups']) === ['soccer', 'all_coaches', 'head_coaches'] && count(array_unique($mapping['groupme_groups'])) === 3, 422);
                foreach ($mapping['groupme_groups'] as $group) {
                    abort_unless(preg_match('/^[0-9]+$/', $group), 422);
                }
                $before = $store->settings($t->id);
                $store->saveSettings($t->id, [...$before, ...$mapping]);
                app(\App\Services\Soccer\SoccerEvents::class)->record($t->id, null, null, 'groupme_mappings_saved', ['summary' => 'Soccer, All Coaches, and Head Coaches mapped.'], $actor->id);
                $report['groupme'] = 'mapped';
            }
            if ($path = $this->option('releases')) {
                $records = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $report['releases'] = $import->releases($t->id, $records);
            }
            foreach (['spring' => 'spring-2026', 'fall' => 'fall-2026'] as $option => $key) {
                if ($path = $this->option($option)) {
                    $report[$option] = $import->csv($t->id, $path, $key);
                }
            }
            if ($this->option('apply')) {
                DB::commit();
            } else {
                DB::rollBack();
            }$this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
