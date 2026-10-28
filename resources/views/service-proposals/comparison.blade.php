<section class="lmc-comparison" aria-labelledby="lmc-comparison-heading">
    <h2 id="lmc-comparison-heading">{{ $comparison['heading'] }}</h2>
    <p>{{ $comparison['intro'] }}</p>
    <div class="lmc-comparison-scroll" tabindex="0" role="region" aria-label="Workspace option comparison, scroll horizontally on small screens">
        <table class="lmc-comparison-table">
            <thead><tr><th scope="col">What is included</th>
                @foreach($comparison['options'] as $option)
                    <th scope="col" @class(['lmc-comparison-plan', 'lmc-comparison-bundle' => $loop->last])>
                        <span>{{ $option['name'] }}</span><small>{{ $option['subtitle'] }}</small>
                        <strong>${{ number_format($option['monthly_cents'] / 100) }}<small> / month</small></strong>
                        <small>{{ $option['activation_note'] }}</small>
                        <b>${{ number_format($option['setup_cents'] / 100) }} one-time setup{{ $loop->last ? ' total' : '' }}</b>
                    </th>
                @endforeach
            </tr></thead>
            <tbody>@foreach($comparison['rows'] as $row)
                <tr><th scope="row">{{ $row['label'] }}</th>@foreach($row['values'] as $value)<td>{{ $value }}</td>@endforeach</tr>
            @endforeach</tbody>
        </table>
    </div>
    <section class="lmc-comparison-value">
        <div><h3>{{ $comparison['value']['heading'] }}</h3><p><strong>${{ number_format($comparison['value']['monthly_saving_cents'] / 100) }}</strong> less per month at full activation<br><strong>${{ number_format($comparison['value']['setup_saving_cents'] / 100) }}</strong> less in one-time setup</p></div>
        <p>{{ $comparison['value']['basis'] }}</p>
    </section>
    <section class="lmc-comparison-stages"><h3>The bundle grows as departments activate</h3>
        @foreach($comparison['stages'] as $stage)
            <div class="lmc-comparison-stage"><div><b>{{ $stage['label'] }}</b><small>${{ number_format($stage['setup_cents'] / 100) }} setup</small></div><div class="lmc-comparison-track" aria-hidden="true"><span class="lmc-comparison-bar lmc-comparison-bar-{{ $loop->index }}"></span></div><strong>${{ number_format($stage['monthly_total_cents'] / 100) }} <small>total / month</small></strong></div>
        @endforeach
        <p class="muted">{{ $comparison['activation_note'] }}</p>
    </section>
    <p class="lmc-comparison-scope">{{ $comparison['scope_note'] }}</p>
    <section><h3>The next decision</h3><p>{{ $comparison['next_step'] }}</p></section>
    <p class="muted">USD. Nonbinding review. This comparison does not select a package or approve an agreement, payment or additional usage.</p>
</section>
