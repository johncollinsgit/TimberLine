<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTask;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CandleCashTaskEligibilityService
{
    /**
     * @return array{
     *   visible:bool,
     *   claimable:bool,
     *   locked:bool,
     *   state:string,
     *   reason:?string,
     *   locked_message:?string,
     *   locked_cta_text:?string,
     *   locked_cta_url:?string,
     *   completion_count:int,
     *   pending_count:int,
     *   completed_count:int,
     *   membership_status:?string
     * }
     */
    public function evaluate(CandleCashTask $task, ?MarketingProfile $profile, array $context = []): array
    {
        $now = $context['now'] ?? now();
        $date = method_exists($now, 'toDateString') ? $now->toDateString() : now()->toDateString();

        if ($task->archived_at || ! $task->enabled) {
            return $this->result(false, false, false, 'disabled', 'disabled', $task, 0, 0, 0, null);
        }

        if ($task->start_date && $task->start_date->toDateString() > $date) {
            return $this->result(false, false, false, 'scheduled', 'scheduled', $task, 0, 0, 0, null);
        }

        if ($task->end_date && $task->end_date->toDateString() < $date) {
            return $this->result(false, false, false, 'expired', 'expired', $task, 0, 0, 0, null);
        }

        if (! $profile) {
            if ($task->eligibility_type === 'candle_club_only') {
                if (! $task->visible_to_noneligible_customers) {
                    return $this->result(false, false, false, 'hidden', 'login_required', $task, 0, 0, 0, null);
                }

                return $this->result(true, false, true, 'locked', 'not_eligible', $task, 0, 0, 0, null);
            }

            return $this->result(true, false, $task->visible_to_noneligible_customers, 'login_required', 'login_required', $task, 0, 0, 0, null);
        }

        $summary = $task->completions()
            ->where('marketing_profile_id', $profile->id)
            ->selectRaw('count(*) as aggregate')
            ->selectRaw("sum(case when status in ('pending','submitted','started') then 1 else 0 end) as pending_count")
            ->selectRaw("sum(case when status in ('awarded','approved') then 1 else 0 end) as completed_count")
            ->first();

        $completionCount = (int) ($summary?->aggregate ?? 0);
        $pendingCount = (int) ($summary?->pending_count ?? 0);
        $completedCount = (int) ($summary?->completed_count ?? 0);
        $membershipStatus = $this->membershipStatusForProfile($profile);

        if ($this->isMembershipBlocked($task, $profile, $membershipStatus)) {
            return $this->result(
                (bool) $task->visible_to_noneligible_customers,
                false,
                (bool) $task->visible_to_noneligible_customers,
                'locked',
                'not_eligible',
                $task,
                $completionCount,
                $pendingCount,
                $completedCount,
                $membershipStatus
            );
        }

        if ($pendingCount > 0) {
            return $this->result(true, false, false, 'pending', 'pending_review', $task, $completionCount, $pendingCount, $completedCount, $membershipStatus);
        }

        if ($task->max_completions_per_customer > 0 && $completedCount >= (int) $task->max_completions_per_customer) {
            return $this->result(true, false, false, 'completed', 'max_completions_reached', $task, $completionCount, $pendingCount, $completedCount, $membershipStatus);
        }

        return $this->result(true, true, false, 'available', null, $task, $completionCount, $pendingCount, $completedCount, $membershipStatus);
    }

    /**
     * @param Collection<int,CandleCashTask> $tasks
     * @return Collection<int,array{task:CandleCashTask,eligibility:array<string,mixed>}>
     */
    public function decorate(Collection $tasks, ?MarketingProfile $profile, array $context = []): Collection
    {
        return $tasks->map(fn (CandleCashTask $task): array => [
            'task' => $task,
            'eligibility' => $this->evaluate($task, $profile, $context),
        ]);
    }

    public function membershipStatusForProfile(?MarketingProfile $profile): ?string
    {
        if (! $profile) {
            return null;
        }

        $channels = collect((array) $profile->source_channels)
            ->map(fn ($value): string => Str::lower(trim((string) $value)));
        if ($channels->contains('candle_club')) {
            return 'active_candle_club_member';
        }

        $hasGroup = $profile->groups()
            ->whereRaw('lower(name) like ?', ['%candle club%'])
            ->exists();
        if ($hasGroup) {
            return 'active_candle_club_member';
        }

        $orderIds = $profile->links()
            ->where('source_type', 'order')
            ->pluck('source_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        if ($orderIds->isNotEmpty()) {
            $hasClubLine = OrderLine::query()
                ->whereIn('order_id', $orderIds)
                ->where(function ($query): void {
                    $query->whereRaw("lower(coalesce(raw_title, '')) like ?", ['%candle club%'])
                        ->orWhereRaw("lower(coalesce(raw_variant, '')) like ?", ['%candle club%']);
                })
                ->exists();
            if ($hasClubLine) {
                return 'active_candle_club_member';
            }
        }

        $shopifyCustomerIds = $profile->links()
            ->where('source_type', 'shopify_customer')
            ->pluck('source_id')
            ->map(function ($value): ?string {
                $sourceId = trim((string) $value);
                if ($sourceId === '') {
                    return null;
                }
                if (str_contains($sourceId, ':')) {
                    [, $sourceId] = explode(':', $sourceId, 2);
                }

                return trim($sourceId) !== '' ? trim($sourceId) : null;
            })
            ->filter()
            ->values();

        if ($shopifyCustomerIds->isNotEmpty()) {
            $orderIds = Order::query()
                ->whereIn('shopify_customer_id', $shopifyCustomerIds)
                ->pluck('id');
            if ($orderIds->isNotEmpty()) {
                $hasClubLine = OrderLine::query()
                    ->whereIn('order_id', $orderIds)
                    ->where(function ($query): void {
                        $query->whereRaw("lower(coalesce(raw_title, '')) like ?", ['%candle club%'])
                            ->orWhereRaw("lower(coalesce(raw_variant, '')) like ?", ['%candle club%']);
                    })
                    ->exists();
                if ($hasClubLine) {
                    return 'active_candle_club_member';
                }
            }
        }

        return null;
    }

    protected function isMembershipBlocked(CandleCashTask $task, MarketingProfile $profile, ?string $membershipStatus): bool
    {
        $eligibilityType = trim((string) $task->eligibility_type);
        if ($eligibilityType === '' || $eligibilityType === 'everyone') {
            return false;
        }

        if ($eligibilityType === 'candle_club_only') {
            return $membershipStatus !== 'active_candle_club_member';
        }

        $requiredTags = collect((array) $task->required_customer_tags)
            ->map(fn ($value): string => Str::lower(trim((string) $value)))
            ->filter();
        if ($requiredTags->isNotEmpty()) {
            $groupTags = $profile->groups()
                ->pluck('name')
                ->map(fn ($value): string => Str::slug((string) $value));

            if ($requiredTags->intersect($groupTags)->isEmpty()) {
                return true;
            }
        }

        if ($task->required_membership_status) {
            return trim((string) $task->required_membership_status) !== trim((string) $membershipStatus);
        }

        return false;
    }

    /**
     * @return array{visible:bool,claimable:bool,locked:bool,state:string,reason:?string,locked_message:?string,locked_cta_text:?string,locked_cta_url:?string,completion_count:int,pending_count:int,completed_count:int,membership_status:?string}
     */
    protected function result(
        bool $visible,
        bool $claimable,
        bool $locked,
        string $state,
        ?string $reason,
        CandleCashTask $task,
        int $completionCount,
        int $pendingCount,
        int $completedCount,
        ?string $membershipStatus
    ): array {
        return [
            'visible' => $visible,
            'claimable' => $claimable,
            'locked' => $locked,
            'state' => $state,
            'reason' => $reason,
            'locked_message' => $task->locked_message,
            'locked_cta_text' => $task->locked_cta_text,
            'locked_cta_url' => $task->locked_cta_url,
            'completion_count' => $completionCount,
            'pending_count' => $pendingCount,
            'completed_count' => $completedCount,
            'membership_status' => $membershipStatus,
        ];
    }
}
