@props(['snapshot' => null, 'rewardsLabel' => 'Rewards'])
@if(is_array($snapshot))
    @once
        <style>
            .customer-commerce{border:1px solid #dedfe2;border-radius:16px;padding:20px;background:#fff;color:#18181b;font:14px/1.5 system-ui,sans-serif;margin:16px 0}
            .customer-commerce h2{font:600 18px/1.4 system-ui,sans-serif;margin:0}
            .customer-commerce h3{font:600 15px/1.4 system-ui,sans-serif;margin:0}
            .customer-commerce p{margin:6px 0 0;color:#52525b}
            .customer-commerce-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
            .customer-commerce-balance{font-weight:600;margin-top:4px}
            .customer-commerce-muted{color:#71717a}
            .customer-commerce-warning{background:#fffbeb;color:#78350f!important;padding:12px;border-radius:8px;margin-top:16px!important}
            .customer-commerce-table{overflow-x:auto;margin-top:20px}
            .customer-commerce table{width:100%;border-collapse:collapse;text-align:left;font:inherit}
            .customer-commerce th{font-weight:500;color:#71717a;border-bottom:1px solid #dedfe2;padding:8px 12px 8px 0}
            .customer-commerce td{padding:12px 12px 12px 0;border-bottom:1px solid #f0f0f1;vertical-align:top}
            .customer-commerce th:last-child,.customer-commerce td:last-child{text-align:right;padding-right:0}
            .customer-commerce-refund,.customer-commerce-coverage{font-size:12px;color:#71717a}
            .customer-commerce-rewards{margin-top:20px;padding-top:16px;border-top:1px solid #dedfe2}
            .customer-commerce ul{list-style:none;margin:8px 0 0;padding:0}
            .customer-commerce li{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid #f0f0f1}
            .customer-commerce .customer-commerce-coverage{margin-top:12px}
        </style>
    @endonce
    <section class="customer-commerce" aria-label="Purchases and rewards">
        <div class="customer-commerce-header">
            <div>
                <h2 >Purchases &amp; {{ $rewardsLabel }}</h2>
                <p >Linked online and in-person purchases, together.</p>
            </div>
            <div >
                <div class="customer-commerce-muted">{{ $rewardsLabel }} balance</div>
                <div class="customer-commerce-balance">
                    @if(data_get($snapshot, 'rewards.balance') !== null)
                        {{ data_get($snapshot, 'rewards.balance') }} reward credits
                    @else
                        No rewards balance recorded
                    @endif
                </div>
            </div>
        </div>
        @if(data_get($snapshot, 'issues', []) !== [])
            <p class="customer-commerce-warning">Some customer links need review before their purchases can be shown.</p>
        @endif
        <div class="customer-commerce-table">
            <table >
                <thead><tr ><th >Purchase</th><th >Channel</th><th >Status</th><th >Amount</th></tr></thead>
                <tbody>
                    @forelse(data_get($snapshot, 'purchases', []) as $purchase)
                        <tr >
                            <td >{{ $purchase['number'] }}</td>
                            <td >{{ ['online' => 'Online', 'in_person' => 'In person', 'unknown' => 'Not recorded'][$purchase['channel']] ?? 'Not recorded' }}</td>
                            <td >{{ ['paid' => 'Payment confirmed', 'partially_refunded' => 'Partially refunded', 'refunded' => 'Refunded', 'unverified' => 'Payment not verified'][$purchase['status']] ?? 'Payment not verified' }}</td>
                            <td >
                                @if($purchase['amount_minor'] !== null && $purchase['currency'])
                                    {{ $purchase['currency'] }} {{ number_format($purchase['amount_minor'] / 100, 2) }}
                                    @if($purchase['refunded_minor'] !== null && $purchase['refunded_minor'] > 0)
                                        <div class="customer-commerce-refund">{{ $purchase['currency'] }} {{ number_format($purchase['refunded_minor'] / 100, 2) }} refunded</div>
                                    @endif
                                @else
                                    Not recorded
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="customer-commerce-muted">No linked purchase records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="customer-commerce-rewards">
            <h3 >{{ $rewardsLabel }} history</h3>
            <ul >
                @forelse(data_get($snapshot, 'rewards.history', []) as $entry)
                    <li >
                        <span>{{ $entry['label'] }} <span class="customer-commerce-muted">{{ substr($entry['occurred_at'] ?? '', 0, 10) }}</span></span>
                        <span>{{ $entry['amount'] }} reward credits</span>
                    </li>
                @empty
                    <li class="customer-commerce-muted">No rewards activity recorded.</li>
                @endforelse
            </ul>
        </div>
        <p class="customer-commerce-coverage">Recent linked records are shown. Purchases from accounts that have not been connected may be missing.</p>
    </section>
@endif
