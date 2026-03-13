<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CandleCashTaskService
{
    public function __construct(
        protected CandleCashService $candleCashService,
        protected CandleCashTaskEligibilityService $eligibilityService,
        protected MarketingStorefrontEventLogger $eventLogger
    ) {
    }

    /**
     * @return Collection<int,CandleCashTask>
     */
    public function activeTasks(): Collection
    {
        return CandleCashTask::query()
            ->where('enabled', true)
            ->whereNull('archived_at')
            ->where(function ($query): void {
                $query->whereNull('start_date')->orWhere('start_date', '<=', now()->toDateString());
            })
            ->where(function ($query): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int,array{task:CandleCashTask,eligibility:array<string,mixed>}>
     */
    public function storefrontTasks(?MarketingProfile $profile): Collection
    {
        return $this->eligibilityService
            ->decorate($this->activeTasks(), $profile)
            ->filter(fn (array $row): bool => (bool) data_get($row, 'eligibility.visible', false))
            ->values();
    }

    public function programConfig(): array
    {
        return (array) optional(MarketingSetting::query()->where('key', 'candle_cash_program_config')->first())->value;
    }

    public function referralConfig(): array
    {
        return (array) optional(MarketingSetting::query()->where('key', 'candle_cash_referral_config')->first())->value;
    }

    public function frontendConfig(): array
    {
        return (array) optional(MarketingSetting::query()->where('key', 'candle_cash_frontend_config')->first())->value;
    }

    public function taskByHandle(string $handle): ?CandleCashTask
    {
        return CandleCashTask::query()->where('handle', trim($handle))->first();
    }

    public function awardSystemTask(MarketingProfile $profile, string|CandleCashTask $task, array $context = []): array
    {
        $taskModel = $task instanceof CandleCashTask ? $task : $this->taskByHandle((string) $task);
        if (! $taskModel) {
            return ['ok' => false, 'state' => 'task_not_found', 'completion' => null, 'error' => 'task_not_found'];
        }

        return $this->createOrResolveCompletion($profile, $taskModel, [
            'source_type' => (string) ($context['source_type'] ?? 'system_task'),
            'source_id' => (string) ($context['source_id'] ?? ''),
            'request_key' => (string) ($context['request_key'] ?? ''),
            'proof_url' => $context['proof_url'] ?? null,
            'proof_text' => $context['proof_text'] ?? null,
            'submission_payload' => is_array($context['submission_payload'] ?? null) ? $context['submission_payload'] : null,
            'metadata' => is_array($context['metadata'] ?? null) ? $context['metadata'] : null,
        ], autoApprove: true, logBlocked: false);
    }

    public function submitCustomerTask(MarketingProfile $profile, CandleCashTask|string $task, array $payload = [], array $context = []): array
    {
        $taskModel = $task instanceof CandleCashTask ? $task : $this->taskByHandle((string) $task);
        if (! $taskModel) {
            return ['ok' => false, 'state' => 'task_not_found', 'completion' => null, 'error' => 'task_not_found'];
        }

        if (in_array((string) $taskModel->task_type, ['auto_event', 'order_triggered', 'referral_triggered'], true)) {
            $requestPayload = [
                'source_type' => (string) ($context['source_type'] ?? 'storefront_task'),
                'source_id' => (string) ($context['source_id'] ?? ''),
                'request_key' => (string) ($context['request_key'] ?? ''),
                'proof_url' => $payload['proof_url'] ?? null,
                'proof_text' => $payload['proof_text'] ?? null,
                'submission_payload' => $payload !== [] ? $payload : null,
                'metadata' => is_array($context['metadata'] ?? null) ? $context['metadata'] : null,
            ];

            $this->recordBlockedAttempt($profile, $taskModel, $requestPayload, 'system_triggered_task');

            return ['ok' => false, 'state' => 'blocked', 'completion' => null, 'error' => 'system_triggered_task'];
        }

        return $this->createOrResolveCompletion($profile, $taskModel, [
            'source_type' => (string) ($context['source_type'] ?? 'storefront_task'),
            'source_id' => (string) ($context['source_id'] ?? ''),
            'request_key' => (string) ($context['request_key'] ?? ''),
            'proof_url' => $payload['proof_url'] ?? null,
            'proof_text' => $payload['proof_text'] ?? null,
            'submission_payload' => $payload !== [] ? $payload : null,
            'metadata' => is_array($context['metadata'] ?? null) ? $context['metadata'] : null,
        ], autoApprove: ! $taskModel->requires_manual_approval && ! $taskModel->requires_customer_submission, logBlocked: true);
    }

    public function approveCompletion(CandleCashTaskCompletion $completion, ?int $approvedBy = null, ?string $note = null): CandleCashTaskCompletion
    {
        if ($completion->awarded_at && $completion->candle_cash_transaction_id) {
            return $completion->fresh(['task', 'profile', 'transaction']);
        }

        return DB::transaction(function () use ($completion, $approvedBy, $note): CandleCashTaskCompletion {
            $completion->refresh();
            $task = $completion->task()->firstOrFail();
            $profile = $completion->profile()->firstOrFail();
            $points = $completion->reward_points > 0
                ? (int) $completion->reward_points
                : $this->candleCashService->pointsFromAmount((float) $completion->reward_amount);

            $result = $this->candleCashService->addPoints(
                profile: $profile,
                points: $points,
                type: 'earn',
                source: 'candle_cash_task',
                sourceId: $completion->completion_key ?: ('task-completion:' . $completion->id),
                description: trim((string) ($task->title ?: 'Candle Cash task')) . ' reward'
            );

            $completion->forceFill([
                'status' => 'awarded',
                'review_notes' => $note ?: $completion->review_notes,
                'approved_by' => $approvedBy ?: $completion->approved_by,
                'reward_amount' => $completion->reward_amount > 0 ? $completion->reward_amount : $task->reward_amount,
                'reward_points' => $points,
                'candle_cash_transaction_id' => (int) ($result['transaction_id'] ?? 0) ?: $completion->candle_cash_transaction_id,
                'reviewed_at' => now(),
                'awarded_at' => now(),
            ])->save();

            return $completion->fresh(['task', 'profile', 'transaction']);
        });
    }

    public function rejectCompletion(CandleCashTaskCompletion $completion, ?int $approvedBy = null, ?string $note = null): CandleCashTaskCompletion
    {
        $completion->forceFill([
            'status' => 'rejected',
            'approved_by' => $approvedBy ?: $completion->approved_by,
            'review_notes' => $note ?: $completion->review_notes,
            'reviewed_at' => now(),
        ])->save();

        return $completion->fresh(['task', 'profile']);
    }

    public function customerSummary(MarketingProfile $profile): array
    {
        $balance = $this->candleCashService->currentBalance($profile);
        $lifetimeEarned = (int) $profile->candleCashTransactions()->where('points', '>', 0)->sum('points');
        $lifetimeRedeemed = abs((int) $profile->candleCashTransactions()->where('points', '<', 0)->sum('points'));
        $pendingRewards = (int) CandleCashTaskCompletion::query()
            ->where('marketing_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'submitted', 'started'])
            ->count();
        $referralCount = (int) $profile->candleCashReferralsMade()->count();

        return [
            'current_balance_points' => $balance,
            'current_balance_amount' => $this->candleCashService->amountFromPoints($balance),
            'lifetime_earned_points' => $lifetimeEarned,
            'lifetime_earned_amount' => $this->candleCashService->amountFromPoints($lifetimeEarned),
            'lifetime_redeemed_points' => $lifetimeRedeemed,
            'lifetime_redeemed_amount' => $this->candleCashService->amountFromPoints($lifetimeRedeemed),
            'pending_rewards' => $pendingRewards,
            'referral_count' => $referralCount,
            'completed_tasks' => (int) CandleCashTaskCompletion::query()
                ->where('marketing_profile_id', $profile->id)
                ->whereIn('status', ['awarded', 'approved'])
                ->count(),
        ];
    }

    /**
     * @return array{ok:bool,state:string,completion:?CandleCashTaskCompletion,error:?string}
     */
    protected function createOrResolveCompletion(MarketingProfile $profile, CandleCashTask $task, array $payload, bool $autoApprove, bool $logBlocked): array
    {
        $eligibility = $this->eligibilityService->evaluate($task, $profile);
        if (! ($eligibility['claimable'] ?? false)) {
            if ($logBlocked) {
                $this->recordBlockedAttempt($profile, $task, $payload, (string) ($eligibility['reason'] ?? 'not_eligible'));
            }

            return [
                'ok' => false,
                'state' => (string) ($eligibility['state'] ?? 'blocked'),
                'completion' => null,
                'error' => (string) ($eligibility['reason'] ?? 'not_eligible'),
            ];
        }

        $completionKey = $this->completionKey($task, $profile, $payload);
        if ($completionKey !== '') {
            $existing = CandleCashTaskCompletion::query()->where('completion_key', $completionKey)->first();
            if ($existing) {
                return [
                    'ok' => in_array((string) $existing->status, ['awarded', 'approved', 'pending', 'submitted'], true),
                    'state' => (string) $existing->status,
                    'completion' => $existing,
                    'error' => null,
                ];
            }
        }

        $requiresSubmission = (bool) $task->requires_customer_submission;
        $proofText = trim((string) ($payload['proof_text'] ?? ''));
        $proofUrl = trim((string) ($payload['proof_url'] ?? ''));
        if ($requiresSubmission && $proofText === '' && $proofUrl === '') {
            return [
                'ok' => false,
                'state' => 'submission_required',
                'completion' => null,
                'error' => 'submission_required',
            ];
        }

        $status = $autoApprove ? 'awarded' : 'pending';
        $rewardAmount = (float) $task->reward_amount;
        $rewardPoints = $this->candleCashService->pointsFromAmount($rewardAmount);

        $completion = DB::transaction(function () use ($profile, $task, $payload, $status, $completionKey, $rewardAmount, $rewardPoints, $autoApprove): CandleCashTaskCompletion {
            $completion = CandleCashTaskCompletion::query()->create([
                'candle_cash_task_id' => $task->id,
                'marketing_profile_id' => $profile->id,
                'status' => $status,
                'completion_key' => $completionKey !== '' ? $completionKey : null,
                'request_key' => trim((string) ($payload['request_key'] ?? '')) ?: null,
                'reward_amount' => $rewardAmount,
                'reward_points' => $rewardPoints,
                'source_type' => trim((string) ($payload['source_type'] ?? '')) ?: null,
                'source_id' => trim((string) ($payload['source_id'] ?? '')) ?: null,
                'proof_url' => trim((string) ($payload['proof_url'] ?? '')) ?: null,
                'proof_text' => trim((string) ($payload['proof_text'] ?? '')) ?: null,
                'submission_payload' => is_array($payload['submission_payload'] ?? null) ? $payload['submission_payload'] : null,
                'started_at' => now(),
                'submitted_at' => $autoApprove ? now() : now(),
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
            ]);

            if (! $autoApprove) {
                return $completion;
            }

            $result = $this->candleCashService->addPoints(
                profile: $profile,
                points: $rewardPoints,
                type: 'earn',
                source: 'candle_cash_task',
                sourceId: $completionKey !== '' ? $completionKey : ('task-completion:' . $completion->id),
                description: trim((string) $task->title) . ' reward'
            );

            $completion->forceFill([
                'candle_cash_transaction_id' => (int) ($result['transaction_id'] ?? 0),
                'reviewed_at' => now(),
                'awarded_at' => now(),
            ])->save();

            return $completion;
        });

        return [
            'ok' => true,
            'state' => $status,
            'completion' => $completion->fresh(['task', 'profile', 'transaction']),
            'error' => null,
        ];
    }

    protected function recordBlockedAttempt(MarketingProfile $profile, CandleCashTask $task, array $payload, string $reason): void
    {
        CandleCashTaskCompletion::query()->create([
            'candle_cash_task_id' => $task->id,
            'marketing_profile_id' => $profile->id,
            'status' => 'blocked',
            'request_key' => trim((string) ($payload['request_key'] ?? '')) ?: null,
            'reward_amount' => (float) $task->reward_amount,
            'reward_points' => $this->candleCashService->pointsFromAmount((float) $task->reward_amount),
            'source_type' => trim((string) ($payload['source_type'] ?? '')) ?: null,
            'source_id' => trim((string) ($payload['source_id'] ?? '')) ?: null,
            'proof_url' => trim((string) ($payload['proof_url'] ?? '')) ?: null,
            'proof_text' => trim((string) ($payload['proof_text'] ?? '')) ?: null,
            'submission_payload' => is_array($payload['submission_payload'] ?? null) ? $payload['submission_payload'] : null,
            'blocked_reason' => $reason,
            'started_at' => now(),
            'submitted_at' => now(),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
        ]);

        $this->eventLogger->log('candle_cash_task_blocked', [
            'status' => 'error',
            'issue_type' => $reason,
            'marketing_profile_id' => $profile->id,
            'source_surface' => 'shopify_widget',
            'endpoint' => 'candle_cash/tasks/claim',
            'source_type' => 'candle_cash_task',
            'source_id' => $task->handle,
            'meta' => [
                'task_id' => $task->id,
                'task_handle' => $task->handle,
                'task_title' => $task->title,
            ],
            'resolution_status' => 'open',
        ]);
    }

    protected function completionKey(CandleCashTask $task, MarketingProfile $profile, array $payload): string
    {
        $sourceType = trim((string) ($payload['source_type'] ?? 'task')) ?: 'task';
        $sourceId = trim((string) ($payload['source_id'] ?? ''));

        if ($sourceId === '') {
            $sourceId = match ((string) $task->task_type) {
                'auto_event', 'order_triggered', 'referral_triggered', 'review_triggered' => $task->handle . ':profile:' . $profile->id,
                default => $task->handle . ':profile:' . $profile->id . ':manual',
            };
        }

        return Str::lower('task:' . $task->handle . '|profile:' . $profile->id . '|source:' . $sourceType . ':' . $sourceId);
    }
}
