<?php

namespace App\Services\Automation\V2\Operations\Native;

use App\Models\MarketingAudiencePermission;
use App\Models\MarketingAutomationEnrollment;
use App\Models\MarketingProfile;
use App\Services\Automation\V2\Contracts\TriggerOperation;
use App\Services\Automation\V2\Data\TriggerEvent;
use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;
use App\Services\Marketing\Desk\MarketingDeskAccess;
use App\Services\Marketing\Desk\MarketingWorkspaceClock;
use Carbon\CarbonImmutable;

class MarketingScheduleTriggerOperation implements TriggerOperation
{
    public function poll(TriggerOperationContext $context): TriggerPollResult
    {
        abort_unless(app(MarketingDeskAccess::class)->available($context->tenantId), 403);
        $time = (string) ($context->config['time'] ?? '09:00');
        abort_unless(preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time), 422, 'Use a 24-hour schedule such as 09:00.');
        $local = CarbonImmutable::now(app(MarketingWorkspaceClock::class)->timezone($context->tenantId));
        $slot = $local->setTimeFromTimeString($time);
        $cursor = json_decode($context->cursor ?? '{}', true) ?: [];
        if (! $context->dryRun && ($local->lt($slot) || (($context->config['days'] ?? 'daily') === 'weekdays' && $local->isWeekend()))) {
            return new TriggerPollResult([], $context->cursor, summary: ['status' => 'not_due']);
        }
        $slotKey = $slot->toDateString();
        $lastId = ($cursor['slot'] ?? '') === $slotKey ? (int) ($cursor['last_id'] ?? 0) : 0;
        $audience = (string) ($context->config['audience'] ?? '');
        $store = (string) ($context->config['store_key'] ?? '');
        $rows = MarketingAudiencePermission::forTenantId($context->tenantId)->where('audience', $audience)->where('store_key', $store)->where('channel', $context->config['channel'] ?? 'email')->whereNull('revoked_at')->when(! $context->dryRun, fn ($q) => $q->where('id', '>', $lastId))->orderBy('id')->limit($context->dryRun ? 1 : min(200, $context->limit))->get();
        $events = [];
        foreach ($rows as $permission) {
            $profile = MarketingProfile::forTenantId($context->tenantId)->whereNull('archived_at')->whereNull('merged_into_profile_id')->find($permission->marketing_profile_id);
            if (! $profile) {
                continue;
            }
            $key = hash('sha256', implode('|', [$context->tenantId, $context->workflowId, $context->workflowVersionId, $slotKey, $profile->id]));
            $payload = ['marketing_profile_id' => (string) $profile->id, 'store_key' => $store, 'marketing_event_key' => $key, 'wholesale_prospect_id' => ''];
            $events[] = new TriggerEvent($key, 'everbranch', (string) $profile->id, hash('sha256', json_encode($payload)), $payload, $slot->utc());
            if (! $context->dryRun) {
                MarketingAutomationEnrollment::firstOrCreate(['workflow_id' => $context->workflowId, 'marketing_profile_id' => $profile->id, 'event_key' => $key], ['tenant_id' => $context->tenantId, 'workflow_version_id' => $context->workflowVersionId, 'audience' => $audience, 'store_key' => $store, 'started_at' => $slot->utc()]);
            }
        }

        return new TriggerPollResult($events, $context->dryRun ? $context->cursor : json_encode(['slot' => $slotKey, 'last_id' => $rows->last()?->id ?? $lastId]), $rows->count() === min(200, $context->limit), ['timezone' => $local->timezoneName, 'missed_days' => 'skip', 'accepted' => count($events)]);
    }
}
