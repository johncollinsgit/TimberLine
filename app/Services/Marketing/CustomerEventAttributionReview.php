<?php

namespace App\Services\Marketing;

use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\User;
use App\Services\PaymentsCore\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Reviewed relationships update existing links; original purchases, consent and rewards are untouched. */
class CustomerEventAttributionReview
{
    public function review(User $actor, int $tenantId, MarketingProfile $profile, array $input): array
    {
        abort_unless(app(CustomerCommerceLinkService::class)->canLink($actor, $tenantId), 403);
        $identities = app(CanonicalMarketingProfileResolver::class);
        $canonical = $identities->canonical($profile, $tenantId);
        abort_unless($canonical && $canonical->archived_at === null, 404);
        $i = Validator::make($input, [
            'request_id' => 'required|uuid', 'source_link_id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:0',
            'kind' => ['required', Rule::in(['acquisition', 'purchase_event', 'membership_conversion'])],
            'reason' => 'required|string|min:8|max:1000', 'evidence_reference' => 'required|string|min:3|max:500',
            'classification' => ['nullable', Rule::in(['new', 'returning', 'unknown'])], 'prehistory_reviewed' => 'sometimes|boolean',
            'event_id' => 'nullable|integer|min:1', 'joined_at' => 'nullable|date_format:Y-m-d\TH:i:sP', 'clear' => 'sometimes|boolean',
        ])->validate();
        foreach (['source_link_id', 'expected_revision', 'event_id'] as $field) {
            if (isset($i[$field])) {
                $i[$field] = (int) $i[$field];
            }
        }
        foreach (['prehistory_reviewed', 'clear'] as $field) {
            if (isset($i[$field])) {
                $i[$field] = (bool) $i[$field];
            }
        }
        $hash = Contract::hash($i);
        $requestKey = $tenantId.':'.$i['request_id'];

        return app(CustomerAccountFence::class)->forTenant($tenantId, function () use ($actor, $tenantId, $canonical, $identities, $i, $hash, $requestKey) {
            abort_unless(app(CustomerCommerceLinkService::class)->canLink($actor->fresh(), $tenantId), 403);
            abort_unless($identities->byId($tenantId, (int) $canonical->id)?->id === $canonical->id, 409);
            $link = MarketingProfileLink::forTenantId($tenantId)->whereKey($i['source_link_id'])->lockForUpdate()->firstOrFail();
            abort_unless($identities->byId($tenantId, (int) $link->marketing_profile_id)?->id === $canonical->id, 404);
            $old = MarketingIdentityReview::where('source_type', 'customer_event_attribution_review')->where('source_id', $requestKey)->first();
            if ($old) {
                abort_unless(($old->payload['input_hash'] ?? null) === $hash && (int) $old->reviewed_by === (int) $actor->id, 409, 'This request ID already belongs to another attribution review.');

                return $old->payload['result'];
            }
            $meta = $link->source_meta ?? [];
            $revision = (int) ($meta['event_attribution_revision'] ?? 0);
            abort_unless($revision === $i['expected_revision'], 409, 'Attribution changed. Refresh before reviewing it again.');
            $values = app(CustomerEventValueService::class);
            $journey = $values->forProfile($canonical, $tenantId);
            $option = collect($journey['review_options']['links'])->firstWhere('source_link_id', (int) $link->id);
            $key = match ($i['kind']) {
                'acquisition' => 'acquisition_review', 'purchase_event' => 'event_attribution', 'membership_conversion' => 'membership_conversion_review',
            };
            $clear = ($i['clear'] ?? false) || ($i['kind'] === 'acquisition' && ($i['classification'] ?? null) === 'unknown');
            // A retained review can always be cleared by the current authorized owner, even after its evidence expires.
            if (! $clear || ! isset($meta[$key])) {
                $this->require($option && in_array($i['kind'], $option['allowed_kinds'], true), 'source_link_id', 'Choose a currently supported original source relationship.');
            }
            $review = null;
            if (! $clear) {
                $review = ['reviewed_by' => (int) $actor->id, 'reviewed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    'evidence_reference' => trim($i['evidence_reference'])];
                if ($i['kind'] === 'acquisition') {
                    $this->require(($i['prehistory_reviewed'] ?? false) === true && in_array($i['classification'] ?? null, ['new', 'returning'], true), 'prehistory_reviewed', 'Explicitly review the customer history before classifying acquisition.');
                    $this->require(! $journey['coverage']['has_more_purchases'] && ! array_intersect($journey['issues'], ['customer_history_unavailable', 'customer_identity_review_required', 'purchase_timing_or_amount_unknown']), 'source_link_id', 'Resolve incomplete or ambiguous recorded history before classifying acquisition.');
                    $anchor = CarbonImmutable::parse($journey['first_recorded_capture']['captured_at']);
                    $prior = collect($journey['purchase_journey'])->contains(fn ($purchase) => $purchase['confirmed_paid_purchase'] && CarbonImmutable::parse($purchase['occurred_at']) < $anchor);
                    $this->require(! $prior || $i['classification'] !== 'new', 'classification', 'An earlier confirmed paid purchase establishes a returning customer.');
                    $review += ['classification' => $i['classification'], 'history_complete_through' => $anchor->toIso8601String(),
                        'captured_at' => $anchor->toIso8601String(), 'event_id' => $journey['first_recorded_capture']['event']['id']];
                } else {
                    $event = $values->reviewedEvent($tenantId, (int) ($i['event_id'] ?? 0));
                    $this->require($event !== null, 'event_id', 'Choose a reviewed event in this tenant.');
                    $review['event_id'] = $event['id'];
                    if ($i['kind'] === 'membership_conversion') {
                        $this->require($event['id'] === $option['capture_event_id'] && ($i['joined_at'] ?? null) === $option['original_join_at'], 'joined_at', 'Use the captured event and its verified original subscription join timestamp.');
                        $review['joined_at'] = $option['original_join_at'];
                    }
                }
            }
            $before = $meta[$key] ?? null;
            if ($clear) {
                unset($meta[$key]);
            } else {
                $meta[$key] = $review;
            }
            $meta['event_attribution_revision'] = $revision + 1;
            $link->update(['source_meta' => $meta]);
            $result = ['source_link_id' => (int) $link->id, 'source_link_revision' => $revision + 1, 'kind' => $i['kind'], 'review' => $review];
            MarketingIdentityReview::create(['status' => 'resolved', 'proposed_marketing_profile_id' => $canonical->id,
                'source_type' => 'customer_event_attribution_review', 'source_id' => $requestKey, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'resolution_notes' => trim($i['reason']), 'payload' => ['tenant_id' => $tenantId, 'action' => 'review_customer_event_attribution',
                    'link_id' => (int) $link->id, 'kind' => $i['kind'], 'before' => $before, 'after' => $review, 'input_hash' => $hash, 'result' => $result]]);

            return $result;
        });
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
