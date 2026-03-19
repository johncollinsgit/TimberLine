<?php

use App\Support\Diagnostics\ShopifyEmbeddedCsrfDiagnostics;
use App\Support\Auth\HomeRedirect;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Middleware\FrameGuard;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->remove(FrameGuard::class);
        $middleware->validateCsrfTokens(except: [
            'shopify/app/api/dashboard/candle-cash-reminders',
            'shopify/app/api/rewards/earn/*',
            'shopify/app/api/rewards/redeem/*',
        ]);

        $middleware->redirectUsersTo(function (Request $request): string {
            return HomeRedirect::pathFor($request->user());
        });

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'marketing.storefront.verify' => \App\Http\Middleware\VerifyMarketingStorefrontRequest::class,
            'tenant.access' => \App\Http\Middleware\EnsureTenantAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $e): void {
            try {
                $request = request();

                if (! ShopifyEmbeddedCsrfDiagnostics::shouldLog($request, $e)) {
                    return;
                }

                Log::warning('shopify.embedded.csrf.exception', ShopifyEmbeddedCsrfDiagnostics::forRequest($request, [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'csrf_exception_source' => $e instanceof TokenMismatchException
                        ? 'middleware'
                        : (($e instanceof HttpExceptionInterface && $e->getStatusCode() === 419) ? 'http_419' : 'other'),
                ]));
            } catch (Throwable $diagError) {
                Log::error('ShopifyEmbeddedCsrfDiagnosticsLoggerFailed', [
                    'error' => $diagError->getMessage(),
                    'file' => $diagError->getFile(),
                    'line' => $diagError->getLine(),
                ]);
            }
        });

        $exceptions->report(function (Throwable $e): void {
            try {
                $request = request();
                if (! $request || ! $request->is('retail/plan')) {
                    return;
                }

                $queue = strtolower(trim((string) $request->query('queue', 'retail')));
                $allowedQueues = ['retail', 'wholesale', 'markets'];
                $checks = [];

                $checks[] = [
                    'label' => '01 route path is /retail/plan',
                    'status' => 'pass',
                    'detail' => 'path=' . $request->path(),
                ];
                $checks[] = [
                    'label' => '02 queue query value is valid',
                    'status' => in_array($queue, $allowedQueues, true) ? 'pass' : 'not_pass',
                    'detail' => 'queue=' . ($queue !== '' ? $queue : '(empty)'),
                ];
                $checks[] = [
                    'label' => '03 route conflict with ?queue=markets',
                    'status' => 'pass',
                    'detail' => 'No route conflict; query string changes component mode, not route match.',
                ];

                $safe = function (callable $fn) {
                    try {
                        return $fn();
                    } catch (Throwable $t) {
                        return 'check_failed: ' . class_basename($t) . ': ' . $t->getMessage();
                    }
                };

                $checks[] = [
                    'label' => '04 retail.plan route registered',
                    'status' => ($safe(fn () => Route::has('retail.plan')) === true) ? 'pass' : 'not_pass',
                    'detail' => is_string($v = $safe(fn () => Route::has('retail.plan')))
                        ? $v
                        : ('route_has=' . (($v ?? false) ? 'yes' : 'no')),
                ];

                $retailTables = $safe(fn () => [
                    Schema::hasTable('retail_plans'),
                    Schema::hasTable('retail_plan_items'),
                ]);
                $checks[] = [
                    'label' => '05 retail plan tables',
                    'status' => is_array($retailTables) && $retailTables[0] && $retailTables[1] ? 'pass' : (is_array($retailTables) ? 'not_pass' : 'check'),
                    'detail' => is_array($retailTables)
                        ? 'retail_plans=' . ($retailTables[0] ? 'yes' : 'no') . ', retail_plan_items=' . ($retailTables[1] ? 'yes' : 'no')
                        : $retailTables,
                ];

                $queueTypeCol = $safe(fn () => Schema::hasColumn('retail_plans', 'queue_type'));
                $checks[] = [
                    'label' => '06 retail_plans.queue_type column',
                    'status' => $queueTypeCol === true ? 'pass' : ($queueTypeCol === false ? 'not_pass' : 'check'),
                    'detail' => is_bool($queueTypeCol) ? ($queueTypeCol ? 'present' : 'missing') : $queueTypeCol,
                ];

                $inventoryQtyCol = $safe(fn () => Schema::hasColumn('retail_plan_items', 'inventory_quantity'));
                $checks[] = [
                    'label' => '07 retail_plan_items.inventory_quantity',
                    'status' => $inventoryQtyCol === true ? 'pass' : ($inventoryQtyCol === false ? 'not_pass' : 'check'),
                    'detail' => is_bool($inventoryQtyCol) ? ($inventoryQtyCol ? 'present' : 'missing') : $inventoryQtyCol,
                ];

                $lookupTables = $safe(fn () => [Schema::hasTable('scents'), Schema::hasTable('sizes')]);
                $checks[] = [
                    'label' => '08 scents/sizes tables',
                    'status' => is_array($lookupTables) && $lookupTables[0] && $lookupTables[1] ? 'pass' : (is_array($lookupTables) ? 'not_pass' : 'check'),
                    'detail' => is_array($lookupTables)
                        ? 'scents=' . ($lookupTables[0] ? 'yes' : 'no') . ', sizes=' . ($lookupTables[1] ? 'yes' : 'no')
                        : $lookupTables,
                ];

                if ($queue === 'markets') {
                    $marketTables = $safe(fn () => [
                        Schema::hasTable('market_pour_lists'),
                        Schema::hasTable('market_pour_list_lines'),
                        Schema::hasTable('events'),
                    ]);
                    $checks[] = [
                        'label' => '09 markets tables',
                        'status' => is_array($marketTables) && $marketTables[0] && $marketTables[1] && $marketTables[2] ? 'pass' : (is_array($marketTables) ? 'not_pass' : 'check'),
                        'detail' => is_array($marketTables)
                            ? 'market_pour_lists=' . ($marketTables[0] ? 'yes' : 'no') . ', market_pour_list_lines=' . ($marketTables[1] ? 'yes' : 'no') . ', events=' . ($marketTables[2] ? 'yes' : 'no')
                            : $marketTables,
                    ];

                    $marketExtras = $safe(fn () => [
                        Route::has('events.index'),
                        Route::has('markets.lists.index'),
                        Schema::hasColumn('order_lines', 'wick_type'),
                    ]);
                    $checks[] = [
                        'label' => '10 markets extras (routes + wick_type)',
                        'status' => is_array($marketExtras) && $marketExtras[0] && $marketExtras[1] && $marketExtras[2] ? 'pass' : (is_array($marketExtras) ? 'not_pass' : 'check'),
                        'detail' => is_array($marketExtras)
                            ? 'events.index=' . ($marketExtras[0] ? 'yes' : 'no') . ', markets.lists.index=' . ($marketExtras[1] ? 'yes' : 'no') . ', order_lines.wick_type=' . ($marketExtras[2] ? 'yes' : 'no')
                            : $marketExtras,
                    ];
                } else {
                    $orderTables = $safe(fn () => [Schema::hasTable('orders'), Schema::hasTable('order_lines')]);
                    $checks[] = [
                        'label' => '09 orders/order_lines tables',
                        'status' => is_array($orderTables) && $orderTables[0] && $orderTables[1] ? 'pass' : (is_array($orderTables) ? 'not_pass' : 'check'),
                        'detail' => is_array($orderTables)
                            ? 'orders=' . ($orderTables[0] ? 'yes' : 'no') . ', order_lines=' . ($orderTables[1] ? 'yes' : 'no')
                            : $orderTables,
                    ];

                    $orderCols = $safe(fn () => [
                        Schema::hasColumn('orders', 'published_at'),
                        Schema::hasColumn('order_lines', 'ordered_qty'),
                        Schema::hasColumn('order_lines', 'extra_qty'),
                    ]);
                    $checks[] = [
                        'label' => '10 core order columns',
                        'status' => is_array($orderCols) && $orderCols[0] && $orderCols[1] && $orderCols[2] ? 'pass' : (is_array($orderCols) ? 'not_pass' : 'check'),
                        'detail' => is_array($orderCols)
                            ? 'orders.published_at=' . ($orderCols[0] ? 'yes' : 'no') . ', order_lines.ordered_qty=' . ($orderCols[1] ? 'yes' : 'no') . ', order_lines.extra_qty=' . ($orderCols[2] ? 'yes' : 'no')
                            : $orderCols,
                    ];
                }

                Log::error('RetailPlanDiagnostics', [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'route_name' => $request->route()?->getName(),
                    'user_id' => $request->user()?->id,
                    'queue' => $queue,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'checks' => $checks,
                ]);
            } catch (Throwable $diagError) {
                Log::error('RetailPlanDiagnosticsLoggerFailed', [
                    'error' => $diagError->getMessage(),
                    'file' => $diagError->getFile(),
                    'line' => $diagError->getLine(),
                ]);
            }
        });
    })->create();
