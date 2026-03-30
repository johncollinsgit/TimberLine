<div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 mf-container mf-responsive-shell min-w-0">
    <div class="space-y-6 sm:space-y-8 min-w-0">
      <section class="mf-app-card rounded-3xl p-5 sm:p-8">
        <div class="mx-auto w-full max-w-2xl">
          <div class="mb-4 text-center">
            <h1 class="text-2xl sm:text-3xl font-semibold text-[var(--fb-text)]">Welcome back</h1>
            <p class="mt-2 text-sm text-[var(--fb-muted)]">Search for a task or jump into a common workflow.</p>
          </div>

          <form wire:submit="submitSearch" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label for="dashboard-launchpad-search" class="sr-only">What would you like to do?</label>
            <input
              id="dashboard-launchpad-search"
              type="text"
              wire:model.defer="search"
              placeholder="What would you like to do?"
              class="w-full rounded-3xl border border-[var(--fb-border)] bg-white px-5 py-4 text-base text-[var(--fb-text)] placeholder:text-[var(--fb-muted)] focus:outline-none"
              style="box-shadow: var(--fb-shadow-soft);"
              autocomplete="off"
            />
            <button
              type="submit"
              class="inline-flex shrink-0 items-center justify-center rounded-3xl border border-[var(--fb-brand)] bg-[var(--fb-brand)] px-5 py-4 text-sm font-semibold text-white hover:bg-[var(--fb-brand-2)] hover:border-[var(--fb-brand-2)] focus:outline-none"
            >
              Go
            </button>
          </form>
        </div>
      </section>

      <x-ui.page-explainer
        title="Dashboard guide"
        what="This page helps operators move quickly into shipping, pouring, events, and analytics work."
        why="A single launchpad reduces context switching and makes daily execution more consistent."
        when="Use it at the start of the day or when you need to route quickly to high-priority tasks."
      />

      <section class="mf-app-card rounded-3xl p-5 sm:p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <h2 class="text-lg sm:text-xl font-semibold text-[var(--fb-text)]">Today at a Glance</h2>
            <p class="mt-1 text-sm text-[var(--fb-muted)]">The quickest operational pulse for today.</p>
          </div>
          <a href="{{ route('analytics.index') }}" wire:navigate class="inline-flex items-center rounded-full border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-3 py-1.5 text-xs font-semibold text-[var(--fb-brand)] hover:text-[var(--fb-brand-2)]">
            Open Analytics
          </a>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div class="rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 sm:p-5">
            <div class="text-xs uppercase tracking-[0.24em] text-[var(--fb-muted)]">Waiting to be Poured</div>
            <div class="mt-3 text-3xl font-semibold text-[var(--fb-text)]">{{ number_format((int) ($glance['waiting_to_pour'] ?? 0)) }}</div>
            <div class="mt-2 text-xs text-[var(--fb-muted)]">Orders in review/pouring pipeline</div>
          </div>
          <div class="rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 sm:p-5">
            <div class="text-xs uppercase tracking-[0.24em] text-[var(--fb-muted)]">Waiting to Ship</div>
            <div class="mt-3 text-3xl font-semibold text-[var(--fb-text)]">{{ number_format((int) ($glance['waiting_to_ship'] ?? 0)) }}</div>
            <div class="mt-2 text-xs text-[var(--fb-muted)]">Orders ready for ship-room handling</div>
          </div>
          <div class="rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 sm:p-5">
            <div class="text-xs uppercase tracking-[0.24em] text-[var(--fb-muted)]">Active Markets</div>
            <div class="mt-3 text-3xl font-semibold text-[var(--fb-text)]">{{ number_format((int) ($glance['active_markets'] ?? 0)) }}</div>
            <div class="mt-2 text-xs text-[var(--fb-muted)]">Upcoming or active market events</div>
          </div>
        </div>
      </section>

      <section class="mf-app-card rounded-3xl p-5 sm:p-6">
        <div class="mb-5">
          <h2 class="text-lg sm:text-xl font-semibold text-[var(--fb-text)]">Popular Actions</h2>
          <p class="mt-1 text-sm text-[var(--fb-muted)]">Common tasks for getting work moving quickly.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          @foreach($popularActions as $action)
            <a
              href="{{ $action['url'] }}"
              class="group relative aspect-square overflow-hidden rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 sm:p-5 transition hover:-translate-y-0.5 focus:outline-none"
              style="box-shadow: var(--fb-shadow-soft);"
            >
              <div class="pointer-events-none absolute inset-x-0 top-0 h-20 bg-gradient-to-b from-white/80 to-transparent"></div>
              <div class="relative flex h-full min-w-0 flex-col overflow-hidden">
                <div class="inline-flex w-fit items-center gap-2 rounded-full border border-[var(--fb-border)] bg-white px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--fb-muted)]">
                  <span class="inline-block h-1.5 w-1.5 rounded-full bg-[var(--fb-accent)]"></span>
                  Action
                </div>
                <div class="mt-3 min-w-0">
                  <div class="text-sm sm:text-base font-semibold text-[var(--fb-text)] leading-tight break-words">
                    {{ $action['title'] }}
                  </div>
                  <div class="mt-2 text-xs sm:text-sm text-[var(--fb-muted)] leading-snug overflow-hidden" style="display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;">
                    {{ $action['description'] }}
                  </div>
                </div>
              </div>
            </a>
          @endforeach
        </div>
      </section>
    </div>
  </div>
