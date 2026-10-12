<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingEmailDelivery;
use App\Models\MessagingContactChannelState;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\MarketingEmailFailurePolicy;
use App\Services\Marketing\MarketingEmailUnsubscribeService;
use App\Services\Marketing\WeeklyRecapActivityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class WeeklyRecapActivityController extends Controller
{
    public function show(Request $request, string $metric, WeeklyRecapActivityService $service)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant && array_key_exists($metric, WeeklyRecapActivityService::METRICS), 404);
        $validated = $request->validate([
            'started_at' => ['required', 'date'], 'ended_at' => ['required', 'date', 'after:started_at'],
            'exclusive_end' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $start = CarbonImmutable::parse($validated['started_at'])->utc();
        $end = CarbonImmutable::parse($validated['ended_at'])->utc();
        abort_if($start->diffInHours($end) > 24 * 91, 422, 'Select a reporting window of 90 days or less.');
        $exclusive = (bool) ($validated['exclusive_end'] ?? true);
        $links = $service->links((int) $tenant->id, $start, $end, $exclusive);
        $amount = null;
        if ($metric === 'unsubscribed') {
            $collection = $service->unsubscribes((int) $tenant->id, $start, $end);
            $page = (int) ($validated['page'] ?? 1);
            $rows = new LengthAwarePaginator($collection->forPage($page, 50)->values(), $collection->count(), 50, $page, [
                'path' => $request->url(), 'query' => $request->query(),
            ]);
            $rows->through(fn ($row) => [
                'name' => $row['name'] ?: 'Customer', 'email' => $row['email'], 'at' => $row['at'],
                'detail' => 'Email unsubscribe recorded', 'status' => 'Unsubscribed',
                'profile_url' => $this->profileUrl($row['profile_id'] ?? null, (int) $tenant->id),
            ]);
        } else {
            $query = $service->query($metric, (int) $tenant->id, $start, $end, $exclusive);
            if (str_starts_with($metric, 'candle_cash_')) {
                $amount = app(CandleCashService::class)->amountFromPoints((float) (clone $query)->sum($metric === 'candle_cash_added' ? 'candle_cash_delta' : 'candle_cash_spent'));
            } elseif (str_starts_with($metric, 'birthday_')) {
                $amount = (float) (clone $query)->where('reward_type', 'discount_code')->sum('reward_value');
            }
            $rows = $query->paginate(50)->withQueryString();
            $rows->through(fn ($record) => $this->row($metric, $record, (int) $tenant->id));
        }

        return response()->view('marketing.weekly-recap-activity', [
            'metric' => $metric, 'label' => WeeklyRecapActivityService::METRICS[$metric],
            'tenant' => $tenant, 'rows' => $rows, 'links' => $links, 'amount' => $amount,
            'windowLabel' => $start->setTimezone('America/New_York')->format('M j, Y, g:i a T').' – '.($exclusive ? $end->subSecond() : $end)->setTimezone('America/New_York')->format('M j, Y, g:i a T'),
        ])->header('Cache-Control', 'private, no-store');
    }

    protected function row(string $metric, $record, int $tenantId): array
    {
        $profile = $metric === 'customers_added' ? $record : ($record->marketingProfile ?? $record->profile);
        // Corrupt historical associations must not reveal a profile from another workspace.
        if ($profile && (int) $profile->tenant_id !== $tenantId) {
            $profile = null;
        }
        $at = match ($metric) {
            'birthday_issued', 'candle_cash_issued' => $record->issued_at,
            'birthday_redeemed', 'candle_cash_redeemed' => $record->redeemed_at,
            'emails_sent' => $record->sent_at,
            'emails_failed' => $record->failed_at,
            'wishlist_added', 'storefront_health' => $record->occurred_at,
            default => $record->created_at,
        };
        $detail = match ($metric) {
            'customers_added' => 'New address recorded'.($record->source ? ' · '.$record->source : ''),
            'birthday_issued', 'birthday_redeemed' => ($record->reward_name ?: 'Birthday gift').' · '.($record->reward_code ?: 'No code').($record->reward_type === 'discount_code' ? ' · $'.number_format((float) $record->reward_value, 2) : '').($record->order_number ? ' · Order '.$record->order_number : ''),
            'candle_cash_added' => '$'.number_format(app(CandleCashService::class)->amountFromPoints((float) $record->candle_cash_delta), 2).' · '.($record->description ?: $record->source),
            'candle_cash_issued', 'candle_cash_redeemed' => ($record->redemption_code ?: 'Reward code').' · $'.number_format(app(CandleCashService::class)->amountFromPoints((float) $record->candle_cash_spent), 2).($record->external_order_id ? ' · Order '.$record->external_order_id : ''),
            'emails_sent', 'emails_failed' => $record->message_subject ?: $record->source_label ?: $record->template_key ?: 'Email',
            default => $record->event_type.($record->issue_type ? ' · '.$record->issue_type : '').(data_get($record->meta, 'product_title') ? ' · '.data_get($record->meta, 'product_title') : ''),
        };
        $row = [
            'name' => trim((string) $profile?->first_name.' '.(string) $profile?->last_name) ?: 'Customer',
            'email' => $record instanceof MarketingEmailDelivery ? $record->email : $profile?->email,
            'at' => $at?->setTimezone('America/New_York')->format('M j, g:i a T'),
            'detail' => $detail, 'status' => $metric === 'customers_added' ? 'Added' : $record->status,
            'profile_url' => $this->profileUrl($profile?->id, $tenantId),
        ];
        if ($record instanceof MarketingEmailDelivery) {
            $event = (array) data_get($record->raw_payload, 'last_event', []);
            $result = (array) data_get($record->raw_payload, 'provider_result', []);
            $decision = data_get($record->metadata, 'failure_policy') ?: app(MarketingEmailFailurePolicy::class)->classify($event ?: [...$result, 'event' => 'send_failure']);
            $state = MessagingContactChannelState::query()->forTenantId($tenantId)->where('email', strtolower(trim((string) $record->email)))->first();
            $blocked = app(MarketingEmailUnsubscribeService::class)->suppressed($tenantId, (string) $record->email);
            $row += [
                'provider' => $record->provider, 'provider_message_id' => $record->provider_message_id,
                'failure_reason' => $decision['reason'] ?: ($result['error_message'] ?? data_get($record->metadata, 'delivery_reconciliation.reason')),
                'failure_category' => $decision['category'] ?? null,
                'future_sends' => $blocked ? 'Stopped · '.(match ($state?->email_status) {
                    'unsubscribed' => 'Customer unsubscribed',
                    'bounced' => 'Permanent address failure',
                    'suppressed' => $state->email_status_reason === 'spam_complaint' ? 'Spam complaint' : 'Address suppressed',
                    default => 'Customer opted out',
                }) : (($decision['category'] === 'consent_required' || ($profile && ! $profile->accepts_email_marketing)) ? 'Awaiting email marketing consent' : 'Address remains eligible'),
                'action' => $decision['category'] === 'none' ? null : $decision['action'],
                'events' => collect((array) data_get($record->raw_payload, 'events', []))->map(fn ($event) => [
                    'event' => $event['event'] ?? 'unknown', 'at' => $event['at'] ?? null,
                    'reason' => $event['reason'] ?? null, 'status' => $event['status'] ?? null,
                ])->all(),
            ];
        }

        return $row;
    }

    protected function profileUrl(?int $profileId, int $tenantId): ?string
    {
        return $profileId ? route('marketing.customers.show', ['marketingProfile' => $profileId, 'tenant' => $tenantId]) : null;
    }
}
