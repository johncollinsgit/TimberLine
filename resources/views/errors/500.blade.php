<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Server error</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100">
    @php
        $rpDiagnostics = null;

        try {
            $req = request();
            $isRetailPlanPath = $req && $req->is('retail/plan');

            if ($isRetailPlanPath) {
                $queue = strtolower(trim((string) $req->query('queue', 'retail')));
                $allowedQueues = ['retail', 'wholesale', 'markets'];
                $checks = [];

                $addCheck = function (string $label, string $status, string $detail) use (&$checks): void {
                    $checks[] = [
                        'label' => $label,
                        'status' => $status, // pass | not_pass | check
                        'detail' => $detail,
                    ];
                };

                $safeSchema = function (callable $fn, string $fallback = 'Schema check failed') {
                    try {
                        return $fn();
                    } catch (\Throwable $e) {
                        return $fallback . ': ' . class_basename($e) . ' - ' . $e->getMessage();
                    }
                };

                $routeName = null;
                try {
                    $routeName = $req?->route()?->getName();
                } catch (\Throwable $e) {
                    $routeName = null;
                }

                $exceptionSummary = null;
                $exceptionHint = null;
                if (isset($exception) && $exception instanceof \Throwable) {
                    $rawMessage = trim((string) $exception->getMessage());
                    $exceptionSummary = [
                        'class' => get_class($exception),
                        'message' => $rawMessage !== '' ? $rawMessage : '(no message)',
                        'file' => basename((string) $exception->getFile()),
                        'line' => (int) $exception->getLine(),
                    ];

                    $messageLower = strtolower($rawMessage);
                    if (str_contains($messageLower, 'route [') && str_contains($messageLower, 'not defined')) {
                        $exceptionHint = 'A named route used by the retail plan page is missing in the deployed route cache.';
                    } elseif (str_contains($messageLower, 'unknown column') || str_contains($messageLower, 'no such column')) {
                        $exceptionHint = 'Code expects a DB column that is not present in production yet (migration/schema drift).';
                    } elseif (str_contains($messageLower, 'base table') || str_contains($messageLower, 'no such table')) {
                        $exceptionHint = 'Code expects a DB table that is missing in production yet (migration/schema drift).';
                    } elseif (str_contains($messageLower, 'call to') && str_contains($messageLower, 'format()')) {
                        $exceptionHint = 'A date value is not a date object in production data; the page tried to format it.';
                    } elseif (str_contains($messageLower, 'trying to access array offset') || str_contains($messageLower, 'undefined array key')) {
                        $exceptionHint = 'The page expected data in a specific shape, but a key was missing in production data.';
                    } elseif (str_contains($messageLower, 'livewire')) {
                        $exceptionHint = 'The failure occurred while rendering or updating the Livewire Retail Plan component.';
                    }
                }

                $queueIsValid = in_array($queue, $allowedQueues, true);
                $addCheck(
                    '01 Route path is /retail/plan',
                    'pass',
                    'Matched path "' . ($req?->path() ?? '') . '" exactly. Query string does not change route matching.'
                );

                $addCheck(
                    '02 Queue query value is valid',
                    $queueIsValid ? 'pass' : 'not_pass',
                    $queueIsValid
                        ? 'queue=' . $queue . ' (allowed: retail, wholesale, markets)'
                        : 'queue=' . ($queue === '' ? '(empty)' : $queue) . ' is not a supported queue value'
                );

                $addCheck(
                    '03 Route conflict between /retail/plan and ?queue=markets',
                    'pass',
                    'No route conflict: both URLs hit the same named route; only the Livewire queue mode changes.'
                );

                $retailPlanRouteExists = false;
                try {
                    $retailPlanRouteExists = \Illuminate\Support\Facades\Route::has('retail.plan');
                } catch (\Throwable $e) {
                    $retailPlanRouteExists = false;
                }
                $addCheck(
                    '04 Named route retail.plan is registered',
                    $retailPlanRouteExists ? 'pass' : 'not_pass',
                    $retailPlanRouteExists
                        ? 'Route exists' . ($routeName ? ' (current route: ' . $routeName . ')' : '')
                        : 'Route::has(\'retail.plan\') returned false (route cache/deploy mismatch possible)'
                );

                $retailPlanTables = $safeSchema(function () {
                    $hasPlans = \Illuminate\Support\Facades\Schema::hasTable('retail_plans');
                    $hasItems = \Illuminate\Support\Facades\Schema::hasTable('retail_plan_items');
                    return [$hasPlans, $hasItems];
                });
                if (is_array($retailPlanTables)) {
                    [$hasPlans, $hasItems] = $retailPlanTables;
                    $addCheck(
                        '05 Core retail plan tables exist',
                        ($hasPlans && $hasItems) ? 'pass' : 'not_pass',
                        'retail_plans=' . ($hasPlans ? 'yes' : 'no') . ', retail_plan_items=' . ($hasItems ? 'yes' : 'no')
                    );
                } else {
                    $addCheck('05 Core retail plan tables exist', 'check', $retailPlanTables);
                }

                $queueTypeColumn = $safeSchema(function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('retail_plans', 'queue_type');
                });
                $addCheck(
                    '06 retail_plans.queue_type column exists',
                    $queueTypeColumn === true ? 'pass' : ($queueTypeColumn === false ? 'not_pass' : 'check'),
                    $queueTypeColumn === true
                        ? 'Present'
                        : ($queueTypeColumn === false
                            ? 'Missing column; code has fallback, but old data/state may still behave differently'
                            : (string) $queueTypeColumn)
                );

                $inventoryQtyColumn = $safeSchema(function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('retail_plan_items', 'inventory_quantity');
                });
                $addCheck(
                    '07 retail_plan_items.inventory_quantity column exists',
                    $inventoryQtyColumn === true ? 'pass' : ($inventoryQtyColumn === false ? 'not_pass' : 'check'),
                    $inventoryQtyColumn === true
                        ? 'Present'
                        : ($inventoryQtyColumn === false
                            ? 'Missing column; non-markets and publish paths can fail when inventory extras are used'
                            : (string) $inventoryQtyColumn)
                );

                $scentsAndSizes = $safeSchema(function () {
                    $hasScents = \Illuminate\Support\Facades\Schema::hasTable('scents');
                    $hasSizes = \Illuminate\Support\Facades\Schema::hasTable('sizes');
                    return [$hasScents, $hasSizes];
                });
                if (is_array($scentsAndSizes)) {
                    [$hasScents, $hasSizes] = $scentsAndSizes;
                    $addCheck(
                        '08 Lookup tables (scents, sizes) exist',
                        ($hasScents && $hasSizes) ? 'pass' : 'not_pass',
                        'scents=' . ($hasScents ? 'yes' : 'no') . ', sizes=' . ($hasSizes ? 'yes' : 'no')
                    );
                } else {
                    $addCheck('08 Lookup tables (scents, sizes) exist', 'check', $scentsAndSizes);
                }

                if ($queue === 'markets') {
                    $marketTables = $safeSchema(function () {
                        return [
                            \Illuminate\Support\Facades\Schema::hasTable('market_pour_lists'),
                            \Illuminate\Support\Facades\Schema::hasTable('market_pour_list_lines'),
                            \Illuminate\Support\Facades\Schema::hasTable('events'),
                        ];
                    });

                    if (is_array($marketTables)) {
                        [$hasMarketLists, $hasMarketListLines, $hasEvents] = $marketTables;
                        $addCheck(
                            '09 Markets planner tables exist',
                            ($hasMarketLists && $hasMarketListLines && $hasEvents) ? 'pass' : 'not_pass',
                            'market_pour_lists=' . ($hasMarketLists ? 'yes' : 'no')
                            . ', market_pour_list_lines=' . ($hasMarketListLines ? 'yes' : 'no')
                            . ', events=' . ($hasEvents ? 'yes' : 'no')
                        );
                    } else {
                        $addCheck('09 Markets planner tables exist', 'check', $marketTables);
                    }

                    $marketRouteHelpers = [false, false, false];
                    try {
                        $marketRouteHelpers = [
                            \Illuminate\Support\Facades\Route::has('events.index'),
                            \Illuminate\Support\Facades\Route::has('markets.lists.index'),
                            \Illuminate\Support\Facades\Schema::hasColumn('order_lines', 'wick_type'),
                        ];
                    } catch (\Throwable $e) {
                        $marketRouteHelpers = null;
                    }

                    if (is_array($marketRouteHelpers)) {
                        [$hasEventsRoute, $hasMarketListsRoute, $hasWickType] = $marketRouteHelpers;
                        $allGood = $hasEventsRoute && $hasMarketListsRoute && $hasWickType;
                        $addCheck(
                            '10 Markets queue extras (routes + order_lines.wick_type)',
                            $allGood ? 'pass' : 'not_pass',
                            'events.index=' . ($hasEventsRoute ? 'yes' : 'no')
                            . ', markets.lists.index=' . ($hasMarketListsRoute ? 'yes' : 'no')
                            . ', order_lines.wick_type=' . ($hasWickType ? 'yes' : 'no')
                        );
                    } else {
                        $addCheck('10 Markets queue extras (routes + order_lines.wick_type)', 'check', 'Unable to inspect route/column checks');
                    }
                } else {
                    $ordersTables = $safeSchema(function () {
                        return [
                            \Illuminate\Support\Facades\Schema::hasTable('orders'),
                            \Illuminate\Support\Facades\Schema::hasTable('order_lines'),
                        ];
                    });

                    if (is_array($ordersTables)) {
                        [$hasOrders, $hasOrderLines] = $ordersTables;
                        $addCheck(
                            '09 Orders tables exist (retail/wholesale path)',
                            ($hasOrders && $hasOrderLines) ? 'pass' : 'not_pass',
                            'orders=' . ($hasOrders ? 'yes' : 'no') . ', order_lines=' . ($hasOrderLines ? 'yes' : 'no')
                        );
                    } else {
                        $addCheck('09 Orders tables exist (retail/wholesale path)', 'check', $ordersTables);
                    }

                    $ordersColumns = $safeSchema(function () {
                        return [
                            \Illuminate\Support\Facades\Schema::hasColumn('orders', 'published_at'),
                            \Illuminate\Support\Facades\Schema::hasColumn('order_lines', 'ordered_qty'),
                            \Illuminate\Support\Facades\Schema::hasColumn('order_lines', 'extra_qty'),
                        ];
                    });

                    if (is_array($ordersColumns)) {
                        [$hasPublishedAt, $hasOrderedQty, $hasExtraQty] = $ordersColumns;
                        $allGood = $hasPublishedAt && $hasOrderedQty && $hasExtraQty;
                        $addCheck(
                            '10 Retail/wholesale order columns exist',
                            $allGood ? 'pass' : 'not_pass',
                            'orders.published_at=' . ($hasPublishedAt ? 'yes' : 'no')
                            . ', order_lines.ordered_qty=' . ($hasOrderedQty ? 'yes' : 'no')
                            . ', order_lines.extra_qty=' . ($hasExtraQty ? 'yes' : 'no')
                        );
                    } else {
                        $addCheck('10 Retail/wholesale order columns exist', 'check', $ordersColumns);
                    }
                }

                $rpDiagnostics = [
                    'path' => '/' . ltrim((string) $req->path(), '/'),
                    'queue' => $queue,
                    'route_name' => $routeName,
                    'exception' => $exceptionSummary,
                    'exception_hint' => $exceptionHint,
                    'checks' => $checks,
                ];
            }
        } catch (\Throwable $diagError) {
            $rpDiagnostics = [
                'path' => '/retail/plan',
                'queue' => null,
                'route_name' => null,
                'exception' => null,
                'exception_hint' => null,
                'checks' => [
                    [
                        'label' => 'Diagnostics panel initialization',
                        'status' => 'not_pass',
                        'detail' => class_basename($diagError) . ': ' . $diagError->getMessage(),
                    ],
                ],
            ];
        }
    @endphp
    <main class="mx-auto flex min-h-screen max-w-2xl items-center px-6">
        <section class="w-full rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl">
            <p class="text-xs uppercase tracking-[0.3em] text-zinc-400">500</p>
            <h1 class="mt-3 text-3xl font-semibold">The server had a moment.</h1>
            <p class="mt-3 text-sm text-zinc-300">
                Something broke on our side. Try again in a minute. If it keeps happening, send the time and what you clicked.
            </p>
            @include('errors.partials.disappointment-quote')
            <div class="mt-6">
                <a href="{{ route('home') }}"
                   class="inline-flex items-center rounded-xl border border-emerald-400/30 bg-emerald-500/15 px-4 py-2 text-sm text-emerald-100 hover:bg-emerald-500/25">
                    Back to Backstage
                </a>
            </div>

            @if($rpDiagnostics)
                <div class="mt-8 rounded-2xl border border-amber-300/20 bg-amber-500/5 p-4">
                    <div class="text-xs uppercase tracking-[0.25em] text-amber-200/70">Retail Plan Diagnostics</div>
                    <p class="mt-2 text-sm text-amber-50/90">
                        This panel is only shown because the failing request path is <code class="rounded bg-black/30 px-1.5 py-0.5 text-xs">{{ $rpDiagnostics['path'] }}</code>.
                        It checks common causes for <code class="rounded bg-black/30 px-1.5 py-0.5 text-xs">/retail/plan</code> and <code class="rounded bg-black/30 px-1.5 py-0.5 text-xs">/retail/plan?queue=markets</code>.
                    </p>

                    <div class="mt-3 grid gap-2 text-xs text-zinc-200 sm:grid-cols-2">
                        <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                            <span class="text-zinc-400">Queue</span>
                            <div class="mt-1 font-semibold text-white">{{ $rpDiagnostics['queue'] ?? 'retail' }}</div>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                            <span class="text-zinc-400">Route Name</span>
                            <div class="mt-1 font-semibold text-white">{{ $rpDiagnostics['route_name'] ?? 'unknown' }}</div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl border border-white/10 bg-black/25 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">What Happened</div>
                        @if(!empty($rpDiagnostics['exception']))
                            <div class="mt-2 text-sm text-white">
                                <div><span class="text-zinc-400">Exception:</span> {{ $rpDiagnostics['exception']['class'] }}</div>
                                <div class="mt-1"><span class="text-zinc-400">Message:</span> {{ $rpDiagnostics['exception']['message'] }}</div>
                                <div class="mt-1"><span class="text-zinc-400">Location:</span> {{ $rpDiagnostics['exception']['file'] }}:{{ $rpDiagnostics['exception']['line'] }}</div>
                                @if(!empty($rpDiagnostics['exception_hint']))
                                    <div class="mt-2 rounded-lg border border-amber-300/20 bg-amber-500/10 px-2.5 py-2 text-amber-50/90">
                                        <span class="text-amber-200/70">Why (likely):</span> {{ $rpDiagnostics['exception_hint'] }}
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="mt-2 text-sm text-zinc-300">
                                Exception details are not available in this error view. Use the checklist below plus the server log entry timestamp.
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 space-y-2">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-400">10 Likely Issue Checks</div>
                        @foreach(($rpDiagnostics['checks'] ?? []) as $check)
                            @php
                                $status = $check['status'] ?? 'check';
                                $badgeClass = match ($status) {
                                    'pass' => 'border-emerald-300/30 bg-emerald-500/15 text-emerald-100',
                                    'not_pass' => 'border-red-300/30 bg-red-500/15 text-red-100',
                                    default => 'border-amber-300/30 bg-amber-500/15 text-amber-100',
                                };
                                $labelText = match ($status) {
                                    'pass' => 'PASS',
                                    'not_pass' => 'NOT PASS',
                                    default => 'CHECK',
                                };
                            @endphp
                            <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="text-sm font-medium text-white">{{ $check['label'] ?? 'Check' }}</div>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $badgeClass }}">
                                        {{ $labelText }}
                                    </span>
                                </div>
                                <div class="mt-1 text-xs text-zinc-300">
                                    {{ $check['detail'] ?? '' }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    </main>
</body>
</html>
