<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnomalyService
{
    public function detect(Space $space, Collection $entries): array
    {
        $dismissed = Record::where('space_id', $space->id)->where('kind', 'anomaly_normal')->where('active', true)->get()->mapWithKeys(fn ($r) => [$r->data['transaction_id'].':'.$r->data['transaction_version'] => true]);
        $result = [];
        $eligible = $entries->filter(fn ($e) => $e['editable'] && $e['amount_cents'] < 0 && $e['flow'] === 'expense' && ! ClassificationService::isAmbiguousMerchant($e['merchant']));
        foreach ($eligible->groupBy(fn ($e) => ClassificationService::merchant($e['merchant'])) as $rows) {
            foreach ($rows->where('category', '!=', 'uncategorized') as $row) {
                if (isset($dismissed[$row['id'].':'.$row['version']])) {
                    continue;
                }
                // Leave the purchase itself out of its supporting history.
                $past = $rows->where('reviewed', true)->where('category', '!=', 'uncategorized')->where('id', '!=', $row['id']);
                $groups = $past->groupBy('category')->sortByDesc(fn ($group) => $group->count());
                $support = $groups->first() ?? collect();
                $majority = $groups->keys()->first();
                $strong = $support->count() >= 3 && $support->count() * 100 >= $past->count() * 80;
                $pattern = ['status' => $strong ? ($majority === $row['category'] ? 'consistent' : 'review') : 'insufficient_history', 'suggested_category' => $strong ? $majority : null, 'reason' => $strong ? $support->count().' of '.$past->count().' other reviewed purchases at this merchant use '.$majority.'.' : 'At least three other reviewed purchases and 80% agreement are needed.', 'support_ids' => $strong ? $support->pluck('id')->all() : []];
                $context = app(CategoryContextService::class)->check($row, $space->kind === 'business');
                if ($pattern['status'] !== 'review' && $context['fit']['status'] !== 'review') {
                    continue;
                }
                $choices = array_values(array_unique(array_filter([
                    $context['fit']['status'] === 'review' ? $context['fit']['suggested_category'] : null,
                    $pattern['status'] === 'review' ? $pattern['suggested_category'] : null,
                ])));
                $reasons = array_filter([$context['fit']['status'] === 'review' ? $context['fit']['reason'] : null, $pattern['status'] === 'review' ? $pattern['reason'] : null]);
                $result[] = ['transaction' => $row, 'suggested_category' => count($choices) === 1 ? $choices[0] : null, 'category_choices' => $choices, 'support_ids' => $pattern['support_ids'], 'reason' => implode(' ', $reasons), 'rating' => count($choices) > 1 ? 'Conflicting signals — review purpose' : 'Review category', 'checks' => ['fit' => $context['fit'], 'pattern' => $pattern, 'practice' => $context['practice']]];
            }
        }

        return array_slice($result, 0, 100);
    }

    public function resolve(User $user, Space $space, Transaction $tx, int $version, string $decision, ?string $category = null): void
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless($tx->space_id === $space->id, 404);
        DB::transaction(function () use ($user, $space, $tx, $version, $decision, $category): void {
            $tx = Transaction::where('space_id', $space->id)->lockForUpdate()->findOrFail($tx->id);
            abort_unless($tx->version === $version, 409, 'Transaction changed. Refresh the review.');
            $anomaly = collect($this->detect($space, collect(app(LedgerService::class)->entries($space))))->first(fn ($a) => $a['transaction']['id'] === $tx->id);
            abort_unless($anomaly, 409, 'This pattern has changed. Refresh the review.');
            if ($decision === 'change') {
                $category ??= $anomaly['suggested_category'];
                abort_unless(in_array($category, $anomaly['category_choices'], true), 422, 'Choose a suggested category after reviewing the conflicting evidence.');
                app(LedgerService::class)->classify($user, $space, $tx, ['version' => $version, 'category' => $category, 'flow' => $tx->flow, 'face_punched' => $tx->face_punched, 'bullshit_spending' => $tx->bullshit_spending]);
            } else {
                Record::create(['space_id' => $space->id, 'kind' => 'anomaly_normal', 'name' => 'Normal exception', 'data' => ['transaction_id' => $tx->id, 'transaction_version' => $version]]);
            }
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'anomaly_'.$decision, 'record_id' => $tx->id, 'after' => ['version' => $version, 'suggested_category' => $anomaly['suggested_category']]]);
        });
    }
}
