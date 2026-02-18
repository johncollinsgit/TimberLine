@php($data = $this->dashboardData)

<div class="space-y-4" data-dashboard-root wire:ignore.self>
  <div class="grid grid-cols-1 md:grid-cols-12 gap-6" data-widget-grid>
    @foreach($widgets as $w)
@php($size = $w['size'] ?? 'full')
@php($span = $size === 'half' ? 'md:col-span-6' : ($size === 'third' ? 'md:col-span-4' : 'md:col-span-12'))
@php($id = $w['id'] ?? '')
@php($title = $w['title'] ?? ($id ?: 'Widget'))


      <section
        class="rounded-2xl border border-white/10 bg-zinc-950/40 backdrop-blur p-5 shadow-sm min-h-[280px] {{ $span }}"
        data-widget
        data-widget-id="{{ $id }}"
        draggable="true"
      >
        <div class="flex items-center justify-between gap-3 mb-3">
          <h3 class="text-sm font-semibold text-white/90">{{ $title }}</h3>
          <span class="text-xs text-white/50 rounded-full border border-white/10 px-2 py-1">drag</span>
        </div>

        {{-- Widget content --}}
        @if($id === 'status_pie')
          <div class="h-[320px]">
            <canvas data-chart="status" class="!h-full !w-full"></canvas>
          </div>

        @elseif($id === 'channel_pie')
          <div class="h-[320px]">
            <canvas data-chart="channel" class="!h-full !w-full"></canvas>
          </div>

        @elseif($id === 'next_due')
          <div class="space-y-2">
            @forelse(($data['nextDue'] ?? []) as $o)
              <div class="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                <div class="min-w-0">
                  <div class="text-sm text-white/90 truncate">
                    #{{ $o['number'] ?? '—' }} — {{ $o['customer'] ?? 'Unknown' }}
                  </div>
                  <div class="text-xs text-white/50">
                    {{ $o['channel'] ?? 'n/a' }} • {{ $o['status'] ?? 'n/a' }}
                  </div>
                </div>
                <div class="text-xs text-white/70">
                  {{ $o['due'] ?? '—' }}
                </div>
              </div>
            @empty
              <div class="text-sm text-white/50">No due dates found.</div>
            @endforelse
          </div>

        @elseif($id === 'orders_table')
          <div class="overflow-x-auto rounded-xl border border-white/10">
            <table class="min-w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">Order</th>
                  <th class="px-3 py-2 text-left font-medium">Customer</th>
                  <th class="px-3 py-2 text-left font-medium">Channel</th>
                  <th class="px-3 py-2 text-left font-medium">Status</th>
                  <th class="px-3 py-2 text-left font-medium">Due</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                @forelse(($data['orders'] ?? []) as $o)
                  <tr class="hover:bg-white/5">
                    <td class="px-3 py-2 text-white/90">#{{ $o['number'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-white/80">{{ $o['customer'] ?? 'Unknown' }}</td>
                    <td class="px-3 py-2 text-white/70">{{ $o['channel'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-white/70">{{ $o['status'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-white/70">{{ $o['due'] ?? '—' }}</td>
                  </tr>
                @empty
                  <tr>
                    <td class="px-3 py-4 text-white/50" colspan="5">No orders found.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>

        @else
          <div class="text-sm text-white/50">
            Unknown widget:
            <span class="text-white/70">{{ $id ?: 'n/a' }}</span>
          </div>
        @endif
      </section>
    @endforeach
  </div>

  {{-- JS payload for Chart.js --}}
  <script type="application/json" data-dashboard-payload>
    @json([
      'statusCounts' => $data['statusCounts'] ?? [],
      'channelCounts' => $data['channelCounts'] ?? [],
    ])
  </script>
</div>
