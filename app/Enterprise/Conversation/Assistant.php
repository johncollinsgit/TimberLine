<?php

namespace App\Enterprise\Conversation;

use App\Enterprise\AskEverbranch;
use App\Enterprise\ReadOnlyAssistant;
use App\Enterprise\RecordStore;
use App\Enterprise\Trial\TrialBudget;
use App\Enterprise\VoiceRetention;
use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Real provider-backed text conversation; current evidence, short encrypted session and one campus trial budget. */
class Assistant
{
    public function __construct(private Connection $connection, private ReadOnlyAssistant $records, private RecordStore $store,
        private TrialBudget $budget, private Responses $provider) {}

    private function row(Tenant $tenant, User $user, string $area, string $id, bool $allowExpired = false): TeamOperationRecord
    {
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => 'ent_conversation_session',
            'assigned_user_id' => $user->id])->findOrFail($id);
        abort_unless($allowExpired || now()->lt(CarbonImmutable::parse($row->data['expires_at'])), 409, 'This conversation expired. Start a new one.');

        return $row;
    }

    private function snapshot(Tenant $tenant, User $user, string $area, string $intent, ?string $building): array
    {
        $authority = $this->connection->authority($tenant, $user, $area);
        $records = $this->records->answer($tenant, $user, $area, $intent, $building);
        $sources = array_slice($records['sources'], 0, 8);
        $byRef = array_column($sources, null, 'ref');
        $calculations = [];
        foreach ($records['comparisons'] as $group) {
            $estimate = array_values(array_intersect($group['estimate_refs'], array_keys($byRef)));
            $recorded = array_values(array_intersect($group['recorded_cost_refs'], array_keys($byRef)));
            if ($estimate === [] && $recorded === []) {
                continue;
            }
            $sum = fn (array $refs) => array_sum(array_map(fn ($ref) => $byRef[$ref]['facts']['amount_minor'], $refs));
            $calculations[] = ['project' => $group['project_title'], 'currency' => $group['currency'], 'estimate_minor' => $estimate !== [] ? $sum($estimate) : null,
                'recorded_cost_minor' => $recorded !== [] ? $sum($recorded) : null, 'difference_minor' => $estimate !== [] && $recorded !== [] ? $sum($recorded) - $sum($estimate) : null,
                'input_refs' => [...$estimate, ...$recorded], 'basis' => 'Checked selected inputs only; no approved budget.'];
        }
        $stamp = AskEverbranch::digest(['actor' => $authority['actor'], 'intent' => $intent, 'building' => $building,
            'sources' => $sources, 'calculations' => $calculations, 'connection' => $authority['settings']['settings_sha256']]);

        return ['sources' => $sources, 'calculations' => $calculations, 'stamp' => $stamp, 'settings' => $authority['settings']];
    }

    private function assertCurrent(Tenant $tenant, User $user, string $area, TeamOperationRecord $row): array
    {
        abort_unless($row->status === 'pending', 409, 'This conversation is no longer awaiting that turn.');
        $data = $row->data;
        $current = $this->snapshot($tenant, $user, $area, $data['intent'], $data['building_id']);
        abort_unless(hash_equals($data['stamp'], $current['stamp']), 409, 'Current records, permissions or connection approval changed. Start a new conversation.');

        return $current;
    }

    private function value(string $reply, array $sources): array
    {
        $value = json_decode($reply, true, 16, JSON_THROW_ON_ERROR);
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys);
        abort_unless($keys === ['answer', 'refs'] && is_string($value['answer']) && trim($value['answer']) !== ''
            && mb_strlen($value['answer']) <= 1500 && ! preg_match('/<[^>]*>|https?:\/\//iu', $value['answer'])
            && ! preg_match('/\b(?:I|we)\s+(?:have\s+)?(?:sent|paid|deleted|assigned|approved|updated|rescheduled|cancelled|notified|created|completed)\b/iu', $value['answer'])
            && is_array($value['refs']) && array_is_list($value['refs']) && count($value['refs']) <= 5
            && count(array_unique($value['refs'], SORT_REGULAR)) === count($value['refs']), 503, 'The model returned an unsupported answer.');
        foreach ($value['refs'] as $ref) {
            abort_unless(is_string($ref) && in_array($ref, array_column($sources, 'ref'), true), 503, 'The model returned an unsupported citation.');
        }
        abort_unless($sources === [] || $value['refs'] !== [], 503, 'A source citation is required.');

        return $value;
    }

    public function turn(Tenant $tenant, User $user, string $area, string $intent, ?string $building, string $question, string $sessionId, string $requestId): array
    {
        $this->connection->authority($tenant, $user, $area);
        if (preg_match('/^\s*(?:(?:please|can you|could you|would you)\s+)?(?:send|email|text|sms|delete|assign|approve|pay|reschedule|cancel|notify|purchase|reassign|remove)\b/iu', $question)) {
            return ['mode' => 'deterministic_policy', 'provider_dispatch' => false, 'reply' => 'This conversation only reads records. Use the authorized work screen to review and confirm an action. Nothing was changed or sent.',
                'sources' => [], 'calculations' => [], 'sample_data' => true, 'session_id' => $sessionId, 'request_id' => $requestId];
        }
        app(VoiceRetention::class)->prune(100, $tenant->id);
        $inputHash = hash('sha256', json_encode([$intent, $building, $question], JSON_THROW_ON_ERROR));
        $claim = $this->store->locked($tenant, function () use ($tenant, $user, $area, $intent, $building, $question, $sessionId, $requestId, $inputHash): array {
            $this->connection->authority($tenant, $user, $area);
            $existing = TeamOperationRecord::query()->whereKey($sessionId)->exists();
            if ($existing) {
                $row = $this->row($tenant, $user, $area, $sessionId);
            } else {
                abort_if(TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_conversation_session', 'assigned_user_id' => $user->id])
                    ->whereIn('status', ['active', 'pending'])->where('voice_expires_at', '>', now())->count() >= 10, 429, 'End an existing conversation before opening another.');
                $row = TeamOperationRecord::query()->create(['id' => $sessionId, 'tenant_id' => $tenant->id, 'area_id' => $area,
                    'kind' => 'ent_conversation_session', 'assigned_user_id' => $user->id, 'source_key' => hash('sha256', 'conversation:'.$user->id.':'.$sessionId),
                    'visibility' => 'area', 'title' => 'Private short conversation', 'status' => 'active', 'version' => 1, 'voice_expires_at' => now()->addMinutes(10),
                    'data' => ['user_id' => (string) $user->id, 'expires_at' => now()->addMinutes(10)->toIso8601String(), 'intent' => $intent,
                        'building_id' => $building, 'turns' => 0, 'history' => [], 'seen' => []]]);
            }
            $data = $row->data;
            abort_unless(in_array($row->status, ['active', 'pending'], true) && $data['intent'] === $intent && $data['building_id'] === $building,
                409, 'This conversation ended or belongs to another record check. Start a new one.');
            $snapshot = $this->snapshot($tenant, $user, $area, $intent, $building);
            abort_unless(! isset($data['stamp']) || hash_equals($data['stamp'], $snapshot['stamp']), 409, 'Historical sources or access changed. Start a new conversation.');
            if (($data['last_uuid'] ?? null) === $requestId) {
                abort_unless(hash_equals($data['input_hash'], $inputHash), 409, 'This turn key belongs to different text.');

                return ['replay' => $data['last_result']];
            }
            if ($row->status === 'pending') {
                abort_unless(($data['pending_uuid'] ?? null) === $requestId && hash_equals($data['input_hash'], $inputHash), 409,
                    'The existing turn is pending or uncertain. Keep its exact key for reconciliation.');

                return ['row_id' => $row->id, 'payload' => $data['pending_payload'], 'snapshot' => $snapshot];
            }
            abort_if(in_array($requestId, $data['seen'], true) || $data['turns'] >= 12, 409, 'Start a new conversation; this turn key was used or the turn limit was reached.');
            $sources = array_map(function ($source): array {
                unset($source['url']);

                return $source;
            }, $snapshot['sources']);
            $payload = ['question' => $question, 'history' => array_slice($data['history'], -6), 'sources' => $sources,
                'calculations' => $snapshot['calculations'], 'coverage' => 'At most eight current authorized saved sources. Sample data; no approved budget, live feed or staffing capacity.'];
            Responses::assertPayload($payload);
            $row->data = array_replace($data, ['stamp' => $snapshot['stamp'], 'input_hash' => $inputHash, 'pending_uuid' => $requestId, 'pending_payload' => $payload]);
            $row->status = 'pending';
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'conversation.turn_claimed', ['request_id' => $requestId]);

            return ['row_id' => $row->id, 'payload' => $payload, 'snapshot' => $snapshot];
        });
        if (isset($claim['replay'])) {
            return $claim['replay'] + ['replayed' => true];
        }
        $settings = $claim['snapshot']['settings'];
        $digest = hash('sha256', json_encode([$claim['payload'], $settings['settings_sha256'], $sessionId, $requestId], JSON_THROW_ON_ERROR));
        $key = 'conversation:'.$settings['silo_id'].':'.$user->id.':'.$sessionId.':'.$requestId;
        // Never acquire the global campaign lock while holding the native tenant/session lock.
        $reservation = $this->budget->reserve('ai', $key, $digest, $settings['maximum_microusd']);
        abort_if($reservation['replayed'], 409, 'That paid turn already completed; no second call will be made. Its short answer may no longer be recoverable.');
        $this->assertCurrent($tenant, $user, $area, $this->row($tenant, $user, $area, $sessionId));
        $this->budget->claimDispatch($reservation['id'], $digest);
        try {
            $this->assertCurrent($tenant, $user, $area, $this->row($tenant, $user, $area, $sessionId));
            $response = $this->provider->complete($settings, $claim['payload'], $requestId);
            $value = $this->value($response['reply'], $claim['snapshot']['sources']);
            $this->budget->settle($reservation['id'], 'accepted', ['provider_id' => $response['provider_id'], 'purpose' => 'campus_text_conversation'], $response['maximum_actual_microusd']);
        } catch (\Throwable) {
            try {
                $this->budget->settle($reservation['id'], 'uncertain', ['purpose' => 'campus_text_conversation', 'status' => 'unverifiable_completion']);
            } catch (\Throwable) {
            }
            $this->budget->pause();
            abort(503, 'The provider outcome is uncertain or invalid. Its full maximum stays reserved and the shared trial is paused. Do not retry with a new key.');
        }

        return $this->store->locked($tenant, function () use ($tenant, $user, $area, $sessionId, $requestId, $value, $reservation, $response): array {
            $row = $this->row($tenant, $user, $area, $sessionId);
            $current = $this->assertCurrent($tenant, $user, $area, $row);
            abort_unless(($row->data['pending_uuid'] ?? null) === $requestId, 409);
            $data = $row->data;
            $result = ['mode' => 'openai_generated', 'provider_dispatch' => true, 'provider_simulated' => app()->environment('testing'), 'provider_calls_live_verified' => false,
                'reply' => $value['answer'], 'scope' => ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'area_id' => $area],
                'session_id' => $sessionId, 'request_id' => $requestId, 'sample_data' => true, 'as_of' => now()->toIso8601String(),
                'sources' => array_values(array_filter($current['sources'], fn ($source) => in_array($source['ref'], $value['refs'], true))),
                'calculations' => $current['calculations'], 'usage' => ['receipt_id' => $reservation['id'], 'held_or_charged_ceiling_microusd' => $response['maximum_actual_microusd'], 'provider_invoice_reconciled' => false],
                'notice' => 'OpenAI-generated text; check the linked current sources. Calculations are server computed. No work changed, message sent, saved assistant or persistent preference memory. This conversation expires after ten minutes.'];
            $row->data = array_replace($data, ['history' => array_slice([...$data['history'], ['role' => 'user', 'text' => $data['pending_payload']['question']],
                ['role' => 'assistant', 'text' => $value['answer']]], -6), 'turns' => $data['turns'] + 1, 'seen' => [...$data['seen'], $requestId],
                'last_uuid' => $requestId, 'last_result' => $result, 'pending_uuid' => null, 'pending_payload' => null]);
            $row->status = 'active';
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'conversation.turn_recorded', ['request_id' => $requestId, 'receipt_id' => $reservation['id']]);

            return $result;
        });
    }

    public function end(Tenant $tenant, User $user, string $area, string $id): array
    {
        $access = app(WorkspaceAccess::class);
        $access->area($tenant, $access->actor($tenant, $user), $area);

        return $this->store->locked($tenant, function () use ($tenant, $user, $area, $id): array {
            $row = $this->row($tenant, $user, $area, $id, true);
            $row->data = array_intersect_key($row->data, array_flip(['user_id', 'expires_at']));
            $row->status = 'ended';
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'conversation.ended_content_scrubbed');

            return ['status' => 'ended', 'session_id' => $id, 'message' => 'Conversation content cleared. Any provider reservation and metering evidence remain.'];
        });
    }
}
