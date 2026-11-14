@props(['birthday' => null])
<section class="customer-birthday eb-themed-panel" aria-label="Customer birthday">
    <h2>Birthday</h2>
    @if(is_array($birthday))
        @if($birthday['month'] !== null && $birthday['day'] !== null)
            <p class="customer-birthday-date">{{ sprintf('%02d/%02d', $birthday['month'], $birthday['day']) }}</p>
        @else
            <p>Birthday date incomplete</p>
        @endif
        @if($birthday['is_uncertain'] === true)<p>Birthday needs review</p>@endif
        <p>Source: {{ $birthday['source'] ? \Illuminate\Support\Str::headline($birthday['source']) : 'Not recorded' }}</p>
        @if($birthday['last_reward_issued_year'] !== null)<p>Last recorded birthday reward: {{ $birthday['last_reward_issued_year'] }}</p>@endif
    @else
        <p>Birthday not recorded</p>
    @endif
</section>
@once
<style>
    .customer-birthday { padding: 20px; margin: 16px 0; border: 1px solid var(--eb-border, #dedfe2); border-radius: 16px; background: var(--eb-surface, #fff); color: var(--eb-text-primary, #18181b); }
    .customer-birthday h2 { margin: 0; font: 600 18px/1.4 system-ui, sans-serif; color: var(--eb-text-primary, #18181b); }
    .customer-birthday p { margin: 8px 0 0; color: var(--eb-text-secondary, #52525b); }
    .customer-birthday .customer-birthday-date { color: var(--eb-text-primary, #18181b); font-weight: 600; font-variant-numeric: tabular-nums; }
</style>
@endonce
