        @if($channelPulse)
            <section class="eb-channel-pulse" aria-label="Channel performance" wire:poll.30s.visible>
                <div class="eb-channel-pulse__context">
                    <a href="{{ $channelPulse['href'] ?? route('sales-channels.index') }}">All channels</a>
                    <label>
                        <span class="sr-only">Time window</span>
                        <select wire:model.live="range" aria-label="Time window">
                            @foreach($rangeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $value === '1d' ? 'Today' : $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="eb-channel-pulse__metrics">
                    @foreach(($channelPulse['metrics'] ?? []) as $metric)
                        <button
                            type="button"
                            class="eb-channel-pulse__metric {{ ($channelChart['key'] ?? 'sales') === ($metric['key'] ?? '') ? 'is-selected' : '' }}"
                            wire:click="selectPulseMetric('{{ $metric['key'] ?? 'sales' }}')"
                            aria-pressed="{{ ($channelChart['key'] ?? 'sales') === ($metric['key'] ?? '') ? 'true' : 'false' }}"
                        >
                            <span>{{ $metric['label'] ?? 'Metric' }}</span>
                            <strong>{{ $metric['value'] ?? '—' }}</strong>
                            <small>
                                @if(is_array($metric['trend'] ?? null))
                                    <b class="eb-channel-pulse__trend eb-channel-pulse__trend--{{ $metric['trend']['tone'] ?? 'neutral' }}">{{ $metric['trend']['label'] ?? '' }}</b>
                                @endif
                                @if($metric['live'] ?? false)<i class="eb-channel-pulse__live" aria-hidden="true"></i>@endif
                                {{ $metric['detail'] ?? '' }}
                            </small>
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="eb-channel-chart" wire:key="channel-chart-{{ $channelChart['key'] ?? 'sales' }}-{{ $dateRange['key'] ?? '1m' }}" wire:transition wire:loading.class="is-loading" wire:target="selectPulseMetric,range" aria-labelledby="channel-chart-title">
                <header class="eb-channel-chart__header">
                    <div>
                        <h2 id="channel-chart-title">{{ $channelChart['title'] ?? 'Channel performance over time' }}</h2>
                        <strong>{{ $channelChart['value'] ?? '—' }}</strong>
                        <p>{{ $channelChart['subtitle'] ?? '' }}</p>
                    </div>
                    <div class="eb-channel-chart__legend" aria-label="Chart comparison">
                        <span><i class="eb-channel-chart__legend-line"></i>{{ $channelChart['current_label'] ?? $dateRange['label'] ?? 'Current period' }}</span>
                        <span><i class="eb-channel-chart__legend-line eb-channel-chart__legend-line--prior"></i>{{ $channelChart['previous_label'] ?? 'Prior period' }}</span>
                    </div>
                </header>
                @if($channelChart['has_data'] ?? false)
                    <div class="eb-channel-chart__plot" role="img" aria-label="{{ $channelChart['title'] ?? 'Channel performance' }} line chart">
                        <svg viewBox="0 0 724 210" preserveAspectRatio="none" aria-hidden="true">
                            <defs>
                                <linearGradient id="eb-channel-chart-area" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0%" stop-color="#149dd8" stop-opacity="0.42" />
                                    <stop offset="100%" stop-color="#149dd8" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <line x1="24" x2="700" y1="34" y2="34" />
                            <line x1="24" x2="700" y1="86" y2="86" />
                            <line x1="24" x2="700" y1="138" y2="138" />
                            <line x1="24" x2="700" y1="190" y2="190" />
                            <polyline class="eb-channel-chart__prior" points="{{ $chartPreviousPoints }}" />
                            <polygon class="eb-channel-chart__area" points="24,190 {{ $chartCurrentPoints }} 700,190" />
                            <polyline class="eb-channel-chart__current" points="{{ $chartCurrentPoints }}" />
                        </svg>
                        <div class="eb-channel-chart__axis" aria-hidden="true">
                            @foreach($chartAxisIndexes as $index)
                                <span>{{ $chartLabels[$index] ?? '' }}</span>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="eb-channel-chart__empty">{{ $channelChart['empty_message'] ?? 'No data is available for this period yet.' }}</div>
                @endif
            </section>
        @endif
