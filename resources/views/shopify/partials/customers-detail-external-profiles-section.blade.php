@php
    $externalProfiles = $externalProfiles ?? collect();
    $externalProfilesCount = (int) ($externalProfilesCount ?? ($externalProfiles instanceof \Illuminate\Support\Collection ? $externalProfiles->count() : count((array) $externalProfiles)));
    $externalProfilesSummary = $externalProfilesCount > 0
        ? number_format($externalProfilesCount) . ' linked provider profile' . ($externalProfilesCount === 1 ? '' : 's') . ' currently attached to this customer.'
        : 'No external profiles linked yet.';
@endphp

<div class="customers-detail-section-header">
    <div>
        <p class="customers-detail-eyebrow">External profiles</p>
        <h3 class="customers-detail-card-title">Linked source records</h3>
        <p class="customers-detail-card-copy" data-customer-external-profiles-summary>{{ $externalProfilesSummary }}</p>
    </div>
</div>
<div class="customers-detail-table-wrap">
    <table class="customers-detail-table">
        <thead>
            <tr>
                <th>Provider</th>
                <th>Integration</th>
                <th>Store</th>
                <th>External ID</th>
                <th>Last Activity</th>
                <th>Legacy Growave Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($externalProfiles as $externalProfile)
                <tr>
                    <td>{{ $externalProfile->provider ?: '—' }}</td>
                    <td>{{ $externalProfile->integration ?: '—' }}</td>
                    <td>{{ $externalProfile->store_key ?: '—' }}</td>
                    <td>{{ $externalProfile->external_customer_id ?: '—' }}</td>
                    <td>{{ optional($externalProfile->last_activity_at)->format('Y-m-d H:i') ?: '—' }}</td>
                    <td>{{ $externalProfile->points_balance !== null ? number_format((int) $externalProfile->points_balance) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="customers-detail-empty">No external profiles linked yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
