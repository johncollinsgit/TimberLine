<?php

namespace App\Services\HighLevel;

use App\Models\HighLevel\Authorization;
use App\Models\HighLevel\Installation;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LifecycleService
{
    public function handle(array $event, \Carbon\CarbonInterface $receivedAt): void
    {
        abort_unless(($event['appId'] ?? '') === config('highlevel.app_id'), 422);
        $type = $event['type'] ?? '';
        $at = isset($event['timestamp']) ? Carbon::parse($event['timestamp']) : $receivedAt;
        abort_if($at->gt(now()->addMinutes(5)), 422);
        if ($type === 'UNINSTALL' && empty($event['locationId']) && filled($event['companyId'] ?? null)) {
            $agency = Authorization::where('app_id', config('highlevel.app_id'))->where('company_id', $event['companyId'])->first();
            $agency?->update(['status' => 'revoked', 'access_token' => null, 'refresh_token' => null]);
            foreach (Installation::where('app_id', config('highlevel.app_id'))->where('company_id', $event['companyId'])->get() as $install) {
                app(InstallationService::class)->uninstall($install);
            }

            return;
        }
        abort_unless(is_string($event['locationId'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $event['locationId']), 422);
        $install = Installation::where('app_id', config('highlevel.app_id'))->where('location_id', $event['locationId'])->first();
        if (! $install) {
            abort_unless(filled($event['companyId'] ?? null), 503, 'Waiting for authorization.');
            $install = Installation::firstOrCreate(['app_id' => config('highlevel.app_id'), 'location_id' => $event['locationId']],
                ['company_id' => $event['companyId']]);
        }
        abort_if(isset($event['companyId']) && $event['companyId'] !== $install->company_id, 422);
        if (in_array($type, ['INSTALL', 'UPDATE'], true) && ! $install->tenant_id) {
            $agency = Authorization::where('app_id', $install->app_id)->where('company_id', $install->company_id)->where('status', 'authorized')->first();
            if ($agency) {
                $install = app(InstallationService::class)->install($agency->company_id, $install->location_id, app(HighLevelApi::class)->locationToken($agency, $install->location_id));
            }
        }
        DB::transaction(function () use ($event, $install, $type, $at): void {
            $install = Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
            if ($type === 'APP_PAYMENT_STATUS') {
                if ($install->payment_at && $at->lte($install->payment_at)) {
                    return;
                }
                // Uninstall wins; delayed payment recovery cannot restore access.
                if ($install->uninstalled_at || ! in_array($event['newStatus'] ?? '', ['COMPLETE', 'FAILED', 'PENDING'], true)) {
                    return;
                }
                $new = $event['newStatus'];
                // With no provider timestamp the documented transition still
                // prevents an older delivery from overwriting a newer status.
                if (isset($event['previousStatus']) && $event['previousStatus'] !== $install->payment_status) {
                    return;
                }
                $grace = $new === 'FAILED' ? ($install->grace_ends_at ?? $at->copy()->addDays(30)) : null;
                $install->update(['payment_status' => $new, 'payment_at' => $at, 'grace_ends_at' => $grace]);
            } else {
                if ($install->lifecycle_at && $at->lte($install->lifecycle_at)) {
                    return;
                }
                if ($type === 'UNINSTALL') {
                    app(InstallationService::class)->uninstall($install);
                } elseif (in_array($type, ['INSTALL', 'UPDATE'], true)) {
                    // Webhooks alone never restore a revoked authorization.
                    // A real OAuth reinstall must obtain fresh provider tokens.
                    if ($install->uninstalled_at) {
                        return;
                    }
                    $origin = $this->origin(data_get($event, 'whitelabelDetails.domain'));
                    $updates = ['plan_id' => $event['planId'] ?? $install->plan_id];
                    if ($origin) {
                        $updates['parent_origin'] = $origin;
                    }
                    if ($type === 'INSTALL' && ! $install->payment_at && ($event['planId'] ?? null) === config('highlevel.plan_id')
                        && filled(config('highlevel.plan_id')) && ! data_get($event, 'trial.onTrial', false)) {
                        $updates += ['payment_status' => 'COMPLETE', 'payment_at' => $at];
                    }
                    $install->update($updates);
                }
                $install->update(['lifecycle_at' => $at]);
            }
            app(LandlordOperatorActionAuditService::class)->record($install->tenant_id, $install->actor_user_id,
                'highlevel.lifecycle', targetType: 'highlevel_installation', targetId: $install->id,
                afterState: ['status' => $install->status, 'payment_status' => $install->payment_status, 'plan_id' => $install->plan_id],
                context: ['event_type' => $type], result: ['billing_authority' => 'highlevel']);
        });
    }

    private function origin(mixed $domain): ?string
    {
        if (! is_string($domain)) {
            return null;
        }
        $origin = 'https://'.strtolower(preg_replace('#^https?://#', '', trim($domain, '/ ')));

        return in_array($origin, config('highlevel.parent_origins', []), true) ? $origin : null;
    }
}
