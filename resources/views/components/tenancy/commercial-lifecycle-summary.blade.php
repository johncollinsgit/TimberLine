@props([
    'commercialSummary' => [],
    'billingInterest' => [],
    'billingNextStep' => [],
    'planLabelByKey' => [],
    'addonLabelByKey' => [],
    'billingReturn' => '',
    'showStatusLine' => true,
])

@php
    $commercialSummary = is_array($commercialSummary) ? $commercialSummary : [];
    $billingInterest = is_array($billingInterest) ? $billingInterest : [];
    $billingNextStep = is_array($billingNextStep) ? $billingNextStep : [];
    $planLabelByKey = is_array($planLabelByKey) ? $planLabelByKey : [];
    $addonLabelByKey = is_array($addonLabelByKey) ? $addonLabelByKey : [];

    $commercialLifecycle = (string) data_get($commercialSummary, 'lifecycle_state', '');
    $commercialReason = (string) data_get($commercialSummary, 'reason', '');
    $customerMessage = is_array(data_get($commercialSummary, 'customer_message')) ? (array) data_get($commercialSummary, 'customer_message') : [];
    $customerMessageTitle = trim((string) data_get($customerMessage, 'title', 'Billing'));
    $customerMessageBody = trim((string) data_get($customerMessage, 'body', ''));

    $stripeSummary = is_array(data_get($commercialSummary, 'stripe')) ? (array) data_get($commercialSummary, 'stripe') : [];
    $preferredPlanKey = strtolower(trim((string) data_get($billingInterest, 'preferred_plan_key', '')));
    $addonsInterest = array_values(array_filter(array_map(static fn ($value) => strtolower(trim((string) $value)), (array) data_get($billingInterest, 'addons_interest', [])), static fn ($value) => $value !== ''));
    $confirmedPlanKey = strtolower(trim((string) data_get($stripeSummary, 'confirmed_plan_key', '')));
    $confirmedAddonKeys = array_values(array_filter(array_map(static fn ($value) => strtolower(trim((string) $value)), (array) data_get($stripeSummary, 'confirmed_addon_keys', [])), static fn ($value) => $value !== ''));
@endphp

<div class="space-y-3">
    @if(in_array($billingReturn, ['success', 'cancel', 'return'], true))
        <div class="fb-state text-sm">
            @if($billingReturn === 'success')
                Returned from checkout. Billing confirmation is processing now.
            @elseif($billingReturn === 'return')
                Returned from billing management.
            @else
                Checkout was cancelled. You can restart billing when ready.
            @endif
        </div>
    @endif

    @if($customerMessageTitle !== '' || $customerMessageBody !== '')
        @php
            $tone = 'fb-state';
            if ($commercialLifecycle === 'fulfilled') {
                $tone = 'fb-state fb-state--success';
            } elseif (in_array($commercialLifecycle, ['billing_confirmed_pending_fulfillment', 'action_required'], true)) {
                $tone = 'fb-state fb-state--warning';
            }
        @endphp
        <div class="{{ $tone }} text-sm">
            <div class="font-semibold">{{ $customerMessageTitle }}</div>
            @if($customerMessageBody !== '')
                <div class="mt-1">{{ $customerMessageBody }}</div>
            @endif
        </div>
    @endif

    @if($confirmedPlanKey !== '' || $confirmedAddonKeys !== [])
        <div class="text-sm text-[var(--fb-text-secondary)]">
            <div class="font-semibold text-[var(--fb-text-primary)]">Confirmed billing</div>
            @if($confirmedPlanKey !== '')
                <div class="mt-1">
                    Tier:
                    <span class="font-semibold text-[var(--fb-text-primary)]">{{ $planLabelByKey[$confirmedPlanKey] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', $confirmedPlanKey)) }}</span>
                </div>
            @endif
            @if($confirmedAddonKeys !== [])
                <div class="mt-1">
                    Add-ons:
                    <span class="font-semibold text-[var(--fb-text-primary)]">
                        {{ collect($confirmedAddonKeys)->map(fn ($key) => $addonLabelByKey[strtolower(trim((string) $key))] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', (string) $key)))->implode(', ') }}
                    </span>
                </div>
            @endif
            @if($preferredPlanKey !== '' && $confirmedPlanKey !== '' && $preferredPlanKey !== $confirmedPlanKey)
                <div class="mt-1">Saved preference differs from confirmed billing tier.</div>
            @endif
        </div>
    @endif

    @if($preferredPlanKey !== '' || $addonsInterest !== [])
        <div class="text-sm text-[var(--fb-text-secondary)]">
            <div class="font-semibold text-[var(--fb-text-primary)]">Saved interest</div>
            @if($preferredPlanKey !== '')
                <div class="mt-1">
                    Preferred tier:
                    <span class="font-semibold text-[var(--fb-text-primary)]">{{ $planLabelByKey[$preferredPlanKey] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', $preferredPlanKey)) }}</span>
                </div>
            @endif
            @if($addonsInterest !== [])
                <div class="mt-1">
                    Add-ons of interest:
                    <span class="font-semibold text-[var(--fb-text-primary)]">
                        {{ collect($addonsInterest)->map(fn ($key) => $addonLabelByKey[strtolower(trim((string) $key))] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', (string) $key)))->implode(', ') }}
                    </span>
                </div>
            @endif
        </div>
    @endif

    @if(filled($billingNextStep['title'] ?? null) || filled($billingNextStep['description'] ?? null))
        <div class="text-sm text-[var(--fb-text-secondary)]">
            <div class="font-semibold text-[var(--fb-text-primary)]">{{ (string) ($billingNextStep['title'] ?? 'Next step') }}</div>
            @if(filled($billingNextStep['description'] ?? null))
                <div class="mt-1">{{ (string) $billingNextStep['description'] }}</div>
            @endif
        </div>
    @endif

    {{ $slot }}

    @if($showStatusLine && $commercialLifecycle !== '' && $commercialLifecycle !== 'unavailable')
        <div class="text-xs text-[var(--fb-text-secondary)]">
            Status: <span class="font-semibold text-[var(--fb-text-primary)]">{{ str_replace('_', ' ', $commercialLifecycle) }}</span>
            @if($commercialReason !== '')
                · reason: {{ str_replace('_', ' ', $commercialReason) }}
            @endif
        </div>
    @endif
</div>
