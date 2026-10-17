<?php

namespace App\Services\Voice;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AiBranch\AiAssistantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class PhonePractice
{
    public const SCENARIOS = ['open_hours', 'closed_hours', 'staff_unavailable', 'transfer_declined', 'callback_requested', 'private_data_request', 'provider_outage'];

    public function available(Tenant $tenant, User $user): bool
    {
        return (app()->environment(['local', 'testing']) || app(\App\Services\AiBranch\AiFictionalPilot::class)->current()) && config('ai_branch.assistant_phone_practice_enabled')
            && app(AiAssistantAccess::class)->allows($tenant, $user);
    }

    public function run(Tenant $tenant, User $user, string $scenario, string $uuid): array
    {
        abort_unless($this->available($tenant, $user), 404);
        abort_unless(in_array($scenario, self::SCENARIOS, true) && Str::isUuid($uuid), 422);
        $policy = ['version' => 1, 'timezone' => 'America/New_York', 'hours' => [1 => [['09:00', '17:00']]],
            'targets' => [['kind' => 'staff', 'user_id' => 1]], 'fallback' => 'receptionist'];
        $at = CarbonImmutable::parse($scenario === 'closed_hours' ? '2026-10-19T23:00:00Z' : '2026-10-19T15:00:00Z');
        $decision = app(CallRoutingPolicy::class)->route($policy, $at, $scenario === 'staff_unavailable' ? [] : [1], ['+12025550100'], true);
        $steps = ['Fictional caller reached Fictional Cedar Works.', 'Checked fictional routing policy version 1.'];
        if ($scenario === 'provider_outage') {
            $steps[] = 'Provider is unavailable. Call connection and voicemail are unconfirmed; a human recovery task is suggested.';
            $outcome = 'unconfirmed';
            $statuses = ['queued', 'failed'];
        } elseif ($scenario === 'private_data_request') {
            $steps[] = 'The receptionist refused financial, employee and private-mail information. Caller ID is not authentication.';
            $outcome = 'restricted_response';
            $statuses = ['queued', 'active', 'completed'];
        } elseif ($scenario === 'transfer_declined') {
            $steps[] = 'Fictional Alex declined the transfer. Return to the caller; offer voicemail or an agreed callback. A transfer was not completed.';
            $outcome = 'callback_offer';
            $statuses = ['queued', 'ringing', 'unanswered'];
        } elseif ($scenario === 'callback_requested') {
            $steps[] = 'Fictional caller agreed to a callback. Prepare a follow-up for human review; nothing has been assigned or sent.';
            $outcome = 'callback_proposal';
            $statuses = ['queued', 'active', 'completed'];
        } elseif ($decision['route'] === 'voicemail') {
            $steps[] = 'Outside fictional business hours. Offer voicemail; no audio has been recorded.';
            $outcome = 'voicemail_offer';
            $statuses = ['queued', 'unanswered'];
        } elseif ($decision['route'] === 'receptionist') {
            $steps[] = 'Fictional staff are unavailable. Answer only from the fictional public playbook; offer human follow-up.';
            $outcome = 'receptionist_offer';
            $statuses = ['queued', 'active', 'completed'];
        } else {
            $steps[] = 'Fictional Alex accepted the fictional call. Only a public service-request summary was handed over.';
            $outcome = 'simulated_connection';
            $statuses = ['queued', 'ringing', 'active', 'completed'];
        }
        $adapter = app(FakeCallEventAdapter::class);
        $state = null;
        foreach ($statuses as $sequence => $status) {
            $event = $adapter->event(['tenant_id' => $tenant->id, 'provider' => 'fake', 'account_id' => 'fictional-'.$tenant->id, 'simulated' => true],
                'fictional://practice', ['call_id' => 'fictional-'.strtolower($uuid), 'status' => $status, 'sequence' => $sequence, 'duration_seconds' => $status === 'completed' ? 45 : null], 'fictional-only', '');
            $state = app(CallLifecycle::class)->apply($state, $event);
        }
        abort_unless($this->available($tenant, $user), 404);

        return ['version' => 1, 'simulated' => true, 'simulation_id' => $uuid, 'scenario' => $scenario, 'outcome' => $outcome,
            'disclosure' => 'Fictional practice only. No call, message, purchase, recording, provider request, task assignment or billable usage occurred.',
            'routing' => $decision, 'steps' => $steps, 'call' => $state, 'provider_capabilities' => $adapter->capabilities(),
            'knowledge' => ['name' => 'Fictional Cedar Works public playbook', 'version' => 1, 'fictional' => true],
            'coverage' => 'Scripted routing and call-state practice. Tenant knowledge publication, durable phone records, porting, real transfer/callback recovery and emergency calling remain unavailable.'];
    }
}
