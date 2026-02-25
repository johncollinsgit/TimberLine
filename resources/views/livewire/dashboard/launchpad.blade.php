<div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 mf-container mf-responsive-shell min-w-0">
    <div class="space-y-6 sm:space-y-8 min-w-0">
      <section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-8 shadow-[0_24px_60px_-40px_rgba(0,0,0,0.65)]">
        <div class="mx-auto w-full max-w-2xl">
          <div class="mb-4 text-center">
            <h1 class="text-2xl sm:text-3xl font-semibold text-white">Welcome Back</h1>
            <p class="mt-2 text-sm text-white/65">Use search or jump into a common workflow.</p>
          </div>

          <form wire:submit="submitSearch" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label for="dashboard-launchpad-search" class="sr-only">What would you like to do?</label>
            <input
              id="dashboard-launchpad-search"
              type="text"
              wire:model.defer="search"
              placeholder="What would you like to do?"
              class="w-full rounded-3xl border border-white/15 bg-white/10 px-5 py-4 text-base text-white placeholder:text-white/45 shadow-[0_12px_32px_-22px_rgba(0,0,0,.55)] focus:border-white/30 focus:outline-none focus:ring-4 focus:ring-white/10"
              autocomplete="off"
            />
            <button
              type="submit"
              class="inline-flex shrink-0 items-center justify-center rounded-3xl border border-emerald-300/25 bg-emerald-500/15 px-5 py-4 text-sm font-semibold text-white hover:bg-emerald-500/25 focus:outline-none focus:ring-4 focus:ring-emerald-300/15"
            >
              Go
            </button>
          </form>
        </div>
      </section>

      <section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-6 shadow-[0_20px_50px_-36px_rgba(0,0,0,0.55)]">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-lg sm:text-xl font-semibold text-white">Today at a Glance</h2>
            <p class="mt-1 text-sm text-white/60">The quickest operational pulse for today.</p>
          </div>
          <a href="{{ route('analytics.index') }}" wire:navigate class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
            Open Analytics
          </a>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div class="rounded-3xl border border-white/10 bg-white/5 p-4 sm:p-5">
            <div class="text-xs uppercase tracking-[0.24em] text-white/55">Waiting to be Poured</div>
            <div class="mt-3 text-3xl font-semibold text-white">{{ number_format((int) ($glance['waiting_to_pour'] ?? 0)) }}</div>
            <div class="mt-2 text-xs text-white/55">Orders in review/pouring pipeline</div>
          </div>
          <div class="rounded-3xl border border-white/10 bg-white/5 p-4 sm:p-5">
            <div class="text-xs uppercase tracking-[0.24em] text-white/55">Waiting to Ship</div>
            <div class="mt-3 text-3xl font-semibold text-white">{{ number_format((int) ($glance['waiting_to_ship'] ?? 0)) }}</div>
            <div class="mt-2 text-xs text-white/55">Orders ready for ship-room handling</div>
          </div>
          <div class="rounded-3xl border border-white/10 bg-white/5 p-4 sm:p-5">
            <div class="text-xs uppercase tracking-[0.24em] text-white/55">Active Markets</div>
            <div class="mt-3 text-3xl font-semibold text-white">{{ number_format((int) ($glance['active_markets'] ?? 0)) }}</div>
            <div class="mt-2 text-xs text-white/55">Upcoming or active market events</div>
          </div>
        </div>
      </section>

      <section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-6 shadow-[0_20px_50px_-36px_rgba(0,0,0,0.55)]">
        <div class="mb-5">
          <h2 class="text-lg sm:text-xl font-semibold text-white">Popular Actions</h2>
          <p class="mt-1 text-sm text-white/60">Common tasks for getting work moving quickly.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          @foreach($popularActions as $action)
            <a
              href="{{ $action['url'] }}"
              wire:navigate
              class="group relative aspect-square overflow-hidden rounded-3xl border border-white/10 bg-white/5 p-4 sm:p-5 shadow-[0_18px_42px_-30px_rgba(0,0,0,0.45)] transition hover:-translate-y-0.5 hover:bg-white/10 hover:shadow-[0_24px_54px_-28px_rgba(0,0,0,0.55)] focus:outline-none focus:ring-4 focus:ring-white/10"
            >
              <div class="pointer-events-none absolute inset-x-0 top-0 h-20 bg-gradient-to-b from-white/5 to-transparent"></div>
              <div class="relative flex h-full min-w-0 flex-col overflow-hidden">
                <div class="inline-flex w-fit items-center gap-2 rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-white/55">
                  <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-300/70"></span>
                  Action
                </div>
                <div class="mt-3 min-w-0">
                  <div class="text-sm sm:text-base font-semibold text-white leading-tight break-words">
                    {{ $action['title'] }}
                  </div>
                  <div class="mt-2 text-xs sm:text-sm text-white/65 leading-snug overflow-hidden" style="display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;">
                    {{ $action['description'] }}
                  </div>
                </div>
                <div class="mt-auto pt-3">
                  <span class="inline-flex max-w-full items-center gap-1 whitespace-nowrap rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-xs font-semibold text-white/85 transition group-hover:bg-white/10 group-hover:text-white">
                    Go <span aria-hidden="true">→</span>
                  </span>
                </div>
              </div>
            </a>
          @endforeach
        </div>
      </section>
    </div>
  </div>
