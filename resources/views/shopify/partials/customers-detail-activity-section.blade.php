@php
    $activity = (array) ($activity ?? []);
    $activityCount = (int) ($activityCount ?? count($activity));
    $activitySummary = $activityCount > 0
        ? number_format($activityCount) . ' recent item' . ($activityCount === 1 ? '' : 's') . ' across rewards, adjustments, and messaging activity.'
        : 'No recent activity recorded yet.';
@endphp

<div class="customers-detail-section-header">
    <div>
        <p class="customers-detail-eyebrow">Recent activity</p>
        <h3 class="customers-detail-card-title">Recent Activity</h3>
        <p class="customers-detail-card-copy" data-customer-activity-summary>{{ $activitySummary }}</p>
    </div>
</div>
<div class="customers-detail-table-wrap">
    <table class="customers-detail-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Label</th>
                <th>Candle Cash</th>
                <th>Actor</th>
                <th>Status</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
            @forelse($activity as $row)
                <tr>
                    <td>{{ $row['occurred_at_display'] ?? '—' }}</td>
                    <td>{{ $row['type'] ?? '—' }}</td>
                    <td>{{ $row['label'] ?? '—' }}</td>
                    <td>{{ $row['candle_cash_display'] !== null ? $row['candle_cash_display'] : '—' }}</td>
                    <td>{{ $row['actor'] ?? '—' }}</td>
                    <td>{{ $row['status'] ?? '—' }}</td>
                    <td>{{ $row['detail'] ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="customers-detail-empty">No recent activity recorded yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
