<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use App\Livewire\Shipping\Orders as ShippingOrders;
use App\Livewire\Pouring\Queue as PouringQueue;
use App\Livewire\PouringRoom\Stacks as PouringStacks;
use App\Livewire\PouringRoom\StackOrders as PouringStackOrders;
use App\Livewire\PouringRoom\OrderDetail as PouringOrderDetail;
use App\Livewire\PouringRoom\AllCandles as PouringAllCandles;
use App\Livewire\PouringRoom\Calendar as PouringCalendar;
use App\Livewire\PouringRoom\Timeline as PouringTimeline;
use App\Livewire\Dashboard\Launchpad as DashboardLaunchpad;
use App\Livewire\Retail\Plan as RetailPlan;
use App\Livewire\Admin\AdminHome;
use App\Livewire\Admin\ImportRuns as AdminImportRuns;
use App\Livewire\Admin\Catalog\ScentsCrud as AdminScentsCrud;
use App\Livewire\Admin\Catalog\SizesCrud as AdminSizesCrud;
use App\Livewire\Admin\Catalog\WicksCrud as AdminWicksCrud;
use App\Livewire\Admin\Oils\OilAbbreviationsCrud as AdminOilAbbreviationsCrud;
use App\Livewire\Admin\Oils\OilBlendsCrud as AdminOilBlendsCrud;
use App\Livewire\Admin\Users\UsersIndex as AdminUsersIndex;
use App\Livewire\Admin\Wholesale\CustomScentsCrud as AdminWholesaleCustomScentsCrud;
use App\Livewire\Inventory\Index as InventoryIndex;
use App\Livewire\Events\Index as EventsIndex;
use App\Livewire\Events\Show as EventsShow;
use App\Livewire\Events\Create as EventsCreate;
use App\Livewire\Events\Import as EventsImport;
use App\Livewire\Events\ImportMarketBoxPlans as EventsImportMarketBoxPlans;
use App\Livewire\Events\Browse as EventsBrowse;
use App\Livewire\Events\BrowseShow as EventsBrowseShow;
use App\Livewire\Markets\MarketPourLists;
use App\Livewire\Markets\MarketPourListBuilder;
use App\Livewire\Markets\MarketPourListShow;
use App\Livewire\Markets\DirectoryIndex as MarketsDirectoryIndex;
use App\Livewire\Markets\MarketHistoryShow as MarketsMarketHistoryShow;
use App\Livewire\Markets\YearOverview as MarketsYearOverview;
use App\Livewire\Markets\EventBrowserShow as MarketsEventBrowserShow;
use App\Livewire\Pouring\Requests as PourRequests;
use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\AdminMasterDataController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\UiPreferencesController;
use App\Http\Controllers\WikiController;
use App\Http\Controllers\WikiAdminController;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyStores;
use App\Support\Auth\HomeRedirect;
use App\Models\Blend;
use App\Models\CandleClubScent;
use App\Models\WholesaleCustomScent;
use App\Support\Wiki\WikiRepository;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->to(HomeRedirect::pathFor(auth()->user()));
    }

    return redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::middleware(['role:admin,manager'])->group(function () {
        Route::get('/dashboard', DashboardLaunchpad::class)->name('dashboard');
    });

    /*
    |--------------------------------------------------------------------------
    | Production OS
    |--------------------------------------------------------------------------
    */

    // Shipping
    Route::middleware(['role:admin,manager'])->group(function () {
        Route::get('/shipping/orders', ShippingOrders::class)
            ->name('shipping.orders');
    });

    // Pouring
    Route::middleware(['role:admin,manager,pouring'])->group(function () {
        Route::get('/pouring', PouringStacks::class)->name('pouring.index');
        Route::get('/pouring/queue', PouringStacks::class)->name('pouring.queue');
        Route::get('/pouring/stack/{channel}', PouringStackOrders::class)->name('pouring.stack');
        Route::get('/pouring/order/{order}', PouringOrderDetail::class)->name('pouring.order');
        Route::get('/pouring/all-candles', PouringAllCandles::class)->name('pouring.all-candles');
        Route::get('/pouring/calendar', PouringCalendar::class)->name('pouring.calendar');
        Route::get('/pouring/timeline', PouringTimeline::class)->name('pouring.timeline');
        Route::get('/pouring/bulk', PouringAllCandles::class)->name('pouring.bulk');
    });
    Route::middleware(['role:admin,manager'])->group(function () {
        Route::get('/retail/plan', RetailPlan::class)->name('retail.plan');
    });

    // Admin landing page
    Route::middleware(['role:admin,manager'])->group(function () {
        Route::get('/admin', AdminHome::class)
            ->name('admin.index');

        Route::get('/admin/catalog', function () {
            return redirect()->route('admin.index', ['tab' => 'catalog']);
        })->name('admin.catalog');
        Route::get('/admin/system-controls', function () {
            return redirect()->route('admin.index');
        });
        Route::get('/admin/scent-intake', function () {
            return redirect()->route('admin.index', ['tab' => 'scent-intake']);
        })->name('admin.scent-intake');

        Route::get('/admin/mapping-exceptions', function () {
            return redirect()->route('admin.index', ['tab' => 'scent-intake']);
        })->name('admin.mapping-exceptions');

        Route::get('/admin/import-runs', AdminImportRuns::class)
            ->name('admin.import-runs');

        // Optional direct module routes
        Route::get('/admin/users', AdminUsersIndex::class)->name('admin.users');
        Route::get('/admin/catalog/scents', AdminScentsCrud::class)->name('admin.catalog.scents');
        Route::get('/admin/catalog/sizes', AdminSizesCrud::class)->name('admin.catalog.sizes');
        Route::get('/admin/catalog/wicks', AdminWicksCrud::class)->name('admin.catalog.wicks');
        Route::get('/admin/wholesale/custom-scents', AdminWholesaleCustomScentsCrud::class)->name('admin.wholesale.custom-scents');
        Route::get('/admin/oils/blends', AdminOilBlendsCrud::class)->name('admin.oils.blends');
        Route::get('/admin/oils/abbreviations', AdminOilAbbreviationsCrud::class)->name('admin.oils.abbreviations');
        Route::get('/admin/master-data', [AdminMasterDataController::class, 'index'])->name('admin.master.ui');
        Route::get('/admin/master/{resource}', [AdminMasterDataController::class, 'list'])->name('admin.master.index-data');
        Route::post('/admin/master/{resource}', [AdminMasterDataController::class, 'store'])->name('admin.master.store');
        Route::patch('/admin/master/{resource}/{record}', [AdminMasterDataController::class, 'update'])->name('admin.master.update');
        Route::delete('/admin/master/{resource}/{record}', [AdminMasterDataController::class, 'destroy'])->name('admin.master.destroy');

        // Admin tools
        Route::post('/admin/tools/clear-orders', function () {
            \DB::transaction(function () {
                \App\Models\MappingException::query()->delete();
                \App\Models\ShopifyImportException::query()->delete();
                \App\Models\OrderLine::query()->delete();
                \App\Models\Order::query()->delete();
                if (\Schema::hasTable('shopify_import_runs')) {
                    \App\Models\ShopifyImportRun::query()->delete();
                }
            });
            return back()->with('status', 'Orders cleared.');
        })->name('admin.tools.clear-orders');

        Route::post('/admin/tools/import/retail', function () {
            Artisan::call('shopify:import-orders', ['store' => 'retail', '--days' => 200]);
            return back()->with('status', 'Retail import started.');
        })->name('admin.tools.import-retail');

        Route::post('/admin/tools/import/wholesale', function () {
            Artisan::call('shopify:import-orders', ['store' => 'wholesale', '--days' => 200]);
            return back()->with('status', 'Wholesale import started.');
        })->name('admin.tools.import-wholesale');

        Route::post('/admin/tools/import-market-boxes', function () {
            Artisan::call('markets:import-boxes');
            return back()->with('status', trim(Artisan::output()) ?: 'Market box import started.');
        })->name('admin.tools.import-market-boxes');
    });

    // Analytics
    Route::middleware(['role:admin,manager'])->group(function () {
        Route::view('/analytics', 'analytics.index')
            ->name('analytics.index');
    });

    // Inventory
    Route::middleware(['role:admin,manager'])->group(function () {
        Route::get('/inventory', InventoryIndex::class)->name('inventory.index');
    });

    Route::middleware(['role:admin,manager'])->group(function () {
        Route::get('/markets', MarketsDirectoryIndex::class)->name('markets.browser.index');
        Route::get('/markets/market/{market:slug}', MarketsMarketHistoryShow::class)->name('markets.browser.market');
        Route::get('/markets/year/{year}', MarketsYearOverview::class)->name('markets.browser.year');
        Route::get('/markets/event/{event}', MarketsEventBrowserShow::class)->name('markets.browser.event');
        Route::get('/events', EventsIndex::class)->name('events.index');
        Route::get('/events/new', EventsCreate::class)->name('events.create');
        Route::get('/events/import', EventsImport::class)->name('events.import');
        Route::get('/events/import-market-box-plans', EventsImportMarketBoxPlans::class)->name('events.import-market-box-plans');
        Route::get('/events/browse', EventsBrowse::class)->name('events.browse');
        Route::get('/events/browse/{eventInstance}', EventsBrowseShow::class)->name('events.browse.show');
        Route::get('/events/{event}', EventsShow::class)->name('events.show');
        Route::get('/market-pour-lists', MarketPourLists::class)->name('markets.lists.index');
        Route::get('/market-pour-lists/new', MarketPourListBuilder::class)->name('markets.lists.create');
        Route::get('/market-pour-lists/{list}', MarketPourListShow::class)->name('markets.lists.show');
    });

    Route::middleware(['role:admin,manager,pouring'])->group(function () {
        Route::get('/pouring/requests', PourRequests::class)->name('pouring.requests');
    });

    // Wiki (read-only)
    Route::get('/wiki', [WikiController::class, 'index'])->name('wiki.index');
    Route::get('/wiki/categories', [WikiController::class, 'categories'])->name('wiki.categories');
    Route::get('/wiki/category/{slug}', [WikiController::class, 'category'])->name('wiki.category');
    Route::get('/wiki/wholesale-processes', [WikiController::class, 'wholesaleProcesses'])->name('wiki.wholesale-processes');
    Route::get('/wiki/article/{slug}', [WikiController::class, 'article'])->name('wiki.article');
    Route::get('/wiki/random', [WikiController::class, 'random'])->name('wiki.random');
    Route::middleware(['role:admin'])->prefix('wiki/admin')->name('wiki.admin.')->group(function () {
        Route::get('/article/create', [WikiAdminController::class, 'createArticle'])->name('article.create');
        Route::post('/article', [WikiAdminController::class, 'storeArticle'])->name('article.store');
        Route::get('/article/{slug}/edit', [WikiAdminController::class, 'editArticle'])->name('article.edit');
        Route::put('/article/{slug}', [WikiAdminController::class, 'updateArticle'])->name('article.update');
        Route::delete('/article/{slug}', [WikiAdminController::class, 'deleteArticle'])->name('article.delete');

        Route::get('/category/create', [WikiAdminController::class, 'createCategory'])->name('category.create');
        Route::post('/category', [WikiAdminController::class, 'storeCategory'])->name('category.store');
        Route::get('/category/{slug}/edit', [WikiAdminController::class, 'editCategory'])->name('category.edit');
        Route::put('/category/{slug}', [WikiAdminController::class, 'updateCategory'])->name('category.update');
        Route::delete('/category/{slug}', [WikiAdminController::class, 'deleteCategory'])->name('category.delete');
    });

    Route::get('/wiki/oil-blends', function () {
        abort_if(!app(WikiRepository::class)->article('oil-blends'), 404);
        $blends = Blend::query()
            ->with(['components.baseOil'])
            ->orderBy('name')
            ->get();
        return view('wiki.oil-blends', ['blends' => $blends]);
    })->name('wiki.oil-blends');

    Route::get('/wiki/wholesale-custom-scents', function () {
        abort_if(!app(WikiRepository::class)->article('wholesale-custom-scents'), 404);
        $records = WholesaleCustomScent::query()
            ->with('canonicalScent')
            ->orderBy('account_name')
            ->orderBy('custom_scent_name')
            ->get()
            ->groupBy('account_name');
        return view('wiki.wholesale-custom-scents', ['records' => $records]);
    })->name('wiki.wholesale-custom-scents');

    Route::get('/wiki/candle-club', function () {
        abort_if(!app(WikiRepository::class)->article('candle-club'), 404);
        $records = CandleClubScent::query()
            ->with('scent')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();
        return view('wiki.candle-club', ['records' => $records]);
    })->name('wiki.candle-club');
});

if (app()->environment('local') && config('app.debug')) {
    Route::middleware(['auth', 'verified'])->prefix('debug/shopify')->group(function () {
        Route::get('/ping/{store}', function (string $store) {
            $config = ShopifyStores::find($store);
            if (!$config) {
                abort(404);
            }

            $client = new ShopifyClient($config['shop'], $config['token']);
            return response()->json($client->get('shop.json'));
        });

        Route::get('/import/{store}', function (string $store) {
            Artisan::call('shopify:import-orders', ['store' => $store]);
            return response()->json([
                'status' => 'ok',
                'output' => Artisan::output(),
            ]);
        });
    });
}

Route::prefix('webhooks/shopify')->group(function () {
    Route::post('/orders/create', [ShopifyWebhookController::class, 'ordersCreate'])
        ->withoutMiddleware([VerifyCsrfToken::class]);
    Route::post('/orders/updated', [ShopifyWebhookController::class, 'ordersUpdated'])
        ->withoutMiddleware([VerifyCsrfToken::class]);
    Route::post('/orders/cancelled', [ShopifyWebhookController::class, 'ordersCancelled'])
        ->withoutMiddleware([VerifyCsrfToken::class]);
    Route::post('/refunds/create', [ShopifyWebhookController::class, 'refundsCreate'])
        ->withoutMiddleware([VerifyCsrfToken::class]);
});

Route::prefix('shopify')->group(function () {
    Route::get('/auth/{store}', [ShopifyAuthController::class, 'auth'])->name('shopify.auth');
    Route::get('/reinstall/{store}', [ShopifyAuthController::class, 'reinstall'])->name('shopify.reinstall');
    Route::get('/callback/{store}', [ShopifyAuthController::class, 'callback'])->name('shopify.callback');
});

Route::middleware('guest')->prefix('auth/google')->name('auth.google.')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [GoogleAuthController::class, 'callback'])->name('callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/ui/preferences', [UiPreferencesController::class, 'update'])->name('ui.preferences.update');
    Route::post('/ui/preferences/sidebar-order', [UiPreferencesController::class, 'updateSidebarOrder'])->name('ui.preferences.sidebar-order');
    Route::post('/ui/preferences/theme', [UiPreferencesController::class, 'updateTheme'])->name('ui.preferences.theme');
});

require __DIR__.'/settings.php';
