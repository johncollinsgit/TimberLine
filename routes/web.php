<?php

use App\Http\Controllers\AdminMasterDataController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Marketing\MarketingCampaignsController;
use App\Http\Controllers\Marketing\MarketingCustomersController;
use App\Http\Controllers\Marketing\MarketingIdentityReviewController;
use App\Http\Controllers\Marketing\MarketingMessagesController;
use App\Http\Controllers\Marketing\MarketingMessageTemplatesController;
use App\Http\Controllers\Marketing\MarketingPagesController;
use App\Http\Controllers\Marketing\MarketingProvidersIntegrationsController;
use App\Http\Controllers\Marketing\MarketingRecommendationsController;
use App\Http\Controllers\Marketing\MarketingSegmentsController;
use App\Http\Controllers\Marketing\MarketingShortLinkRedirectController;
use App\Http\Controllers\Marketing\TwilioWebhookController;
use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\UiPreferencesController;
use App\Http\Controllers\WikiAdminController;
use App\Http\Controllers\WikiController;
use App\Livewire\Admin\AdminHome;
use App\Livewire\Admin\Catalog\ScentsCrud as AdminScentsCrud;
use App\Livewire\Admin\Catalog\SizesCrud as AdminSizesCrud;
use App\Livewire\Admin\Catalog\WicksCrud as AdminWicksCrud;
use App\Livewire\Admin\ImportRuns as AdminImportRuns;
use App\Livewire\Admin\Oils\OilAbbreviationsCrud as AdminOilAbbreviationsCrud;
use App\Livewire\Admin\Oils\OilBlendsCrud as AdminOilBlendsCrud;
use App\Livewire\Admin\ScentWizard as AdminScentWizard;
use App\Livewire\Admin\Users\UsersIndex as AdminUsersIndex;
use App\Livewire\Admin\Wholesale\CustomScentsCrud as AdminWholesaleCustomScentsCrud;
use App\Livewire\Dashboard\Launchpad as DashboardLaunchpad;
use App\Livewire\Events\Browse as EventsBrowse;
use App\Livewire\Events\BrowseShow as EventsBrowseShow;
use App\Livewire\Events\Create as EventsCreate;
use App\Livewire\Events\Import as EventsImport;
use App\Livewire\Events\ImportMarketBoxPlans as EventsImportMarketBoxPlans;
use App\Livewire\Events\Index as EventsIndex;
use App\Livewire\Events\Show as EventsShow;
use App\Livewire\Inventory\Index as InventoryIndex;
use App\Livewire\Markets\DirectoryIndex as MarketsDirectoryIndex;
use App\Livewire\Markets\EventBrowserShow as MarketsEventBrowserShow;
use App\Livewire\Markets\MarketHistoryShow as MarketsMarketHistoryShow;
use App\Livewire\Markets\MarketPourListBuilder;
use App\Livewire\Markets\MarketPourLists;
use App\Livewire\Markets\MarketPourListShow;
use App\Livewire\Markets\YearOverview as MarketsYearOverview;
use App\Livewire\Pouring\Requests as PourRequests;
use App\Livewire\PouringRoom\AllCandles as PouringAllCandles;
use App\Livewire\PouringRoom\Calendar as PouringCalendar;
use App\Livewire\PouringRoom\OrderDetail as PouringOrderDetail;
use App\Livewire\PouringRoom\StackOrders as PouringStackOrders;
use App\Livewire\PouringRoom\Stacks as PouringStacks;
use App\Livewire\PouringRoom\Timeline as PouringTimeline;
use App\Livewire\Retail\Plan as RetailPlan;
use App\Livewire\Shipping\Orders as ShippingOrders;
use App\Models\Blend;
use App\Models\CandleClubScent;
use App\Models\WholesaleCustomScent;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyStores;
use App\Support\Auth\HomeRedirect;
use App\Support\Wiki\WikiRepository;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->to(HomeRedirect::pathFor(auth()->user()));
    }

    return redirect()->route('login');
})->name('home');

$marketingShortLinkPrefix = trim((string) config('marketing.links.path_prefix', 'go'), '/');
if ($marketingShortLinkPrefix === '') {
    $marketingShortLinkPrefix = 'go';
}

Route::get('/' . $marketingShortLinkPrefix . '/{code}', [MarketingShortLinkRedirectController::class, 'show'])
    ->where('code', '[A-Za-z0-9]+')
    ->name('marketing.short-links.redirect');

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
        Route::get('/admin/scent-intake', function (Request $request) {
            return redirect()->route('admin.index', array_merge(
                ['tab' => 'scent-intake'],
                $request->query()
            ));
        })->name('admin.scent-intake');

        Route::get('/admin/mapping-exceptions', function (Request $request) {
            return redirect()->route('admin.index', array_merge(
                ['tab' => 'scent-intake'],
                $request->query()
            ));
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
        Route::get('/admin/scent-wizard', AdminScentWizard::class)->name('admin.scent-wizard');
        Route::post('/admin/master-data/{resource}/bulk-update', [AdminMasterDataController::class, 'bulkUpdate'])->name('admin.master.bulk-update');
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

    // Marketing
    Route::middleware(['role:admin,marketing_manager'])
        ->prefix('marketing')
        ->name('marketing.')
        ->group(function () {
            Route::get('/', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'overview')
                ->name('overview');
            Route::get('/customers', [MarketingCustomersController::class, 'index'])->name('customers');
            Route::get('/customers/create', [MarketingCustomersController::class, 'create'])->name('customers.create');
            Route::post('/customers/create', [MarketingCustomersController::class, 'storeCreate'])->name('customers.store-create');
            Route::get('/customers/{marketingProfile}', [MarketingCustomersController::class, 'show'])->name('customers.show');
            Route::patch('/customers/{marketingProfile}', [MarketingCustomersController::class, 'update'])->name('customers.update');
            Route::post('/customers/{marketingProfile}/consent', [MarketingCustomersController::class, 'updateConsent'])->name('customers.update-consent');
            Route::get('/identity-review', [MarketingIdentityReviewController::class, 'index'])->name('identity-review');
            Route::get('/identity-review/{review}', [MarketingIdentityReviewController::class, 'show'])->name('identity-review.show');
            Route::post('/identity-review/{review}/resolve-existing', [MarketingIdentityReviewController::class, 'resolveExisting'])->name('identity-review.resolve-existing');
            Route::post('/identity-review/{review}/resolve-new', [MarketingIdentityReviewController::class, 'resolveNew'])->name('identity-review.resolve-new');
            Route::post('/identity-review/{review}/ignore', [MarketingIdentityReviewController::class, 'ignore'])->name('identity-review.ignore');
            Route::get('/orders', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'orders')
                ->name('orders');
            Route::get('/segments', [MarketingSegmentsController::class, 'index'])->name('segments');
            Route::get('/segments/create', [MarketingSegmentsController::class, 'create'])->name('segments.create');
            Route::post('/segments', [MarketingSegmentsController::class, 'store'])->name('segments.store');
            Route::get('/segments/{segment}/edit', [MarketingSegmentsController::class, 'edit'])->name('segments.edit');
            Route::patch('/segments/{segment}', [MarketingSegmentsController::class, 'update'])->name('segments.update');
            Route::get('/segments/{segment}/preview', [MarketingSegmentsController::class, 'preview'])->name('segments.preview');
            Route::post('/segments/{segment}/duplicate', [MarketingSegmentsController::class, 'duplicate'])->name('segments.duplicate');
            Route::post('/segments/{segment}/archive', [MarketingSegmentsController::class, 'archive'])->name('segments.archive');

            Route::get('/campaigns', [MarketingCampaignsController::class, 'index'])->name('campaigns');
            Route::get('/campaigns/create', [MarketingCampaignsController::class, 'create'])->name('campaigns.create');
            Route::post('/campaigns', [MarketingCampaignsController::class, 'store'])->name('campaigns.store');
            Route::get('/campaigns/{campaign}', [MarketingCampaignsController::class, 'show'])->name('campaigns.show');
            Route::get('/campaigns/{campaign}/edit', [MarketingCampaignsController::class, 'edit'])->name('campaigns.edit');
            Route::patch('/campaigns/{campaign}', [MarketingCampaignsController::class, 'update'])->name('campaigns.update');
            Route::post('/campaigns/{campaign}/prepare-recipients', [MarketingCampaignsController::class, 'prepareRecipients'])->name('campaigns.prepare-recipients');
            Route::post('/campaigns/{campaign}/variants', [MarketingCampaignsController::class, 'addVariant'])->name('campaigns.variants.store');
            Route::patch('/campaigns/{campaign}/variants/{variant}', [MarketingCampaignsController::class, 'updateVariant'])->name('campaigns.variants.update');
            Route::post('/campaigns/{campaign}/recipients/{recipient}/approve', [MarketingCampaignsController::class, 'approveRecipient'])->name('campaigns.recipients.approve');
            Route::post('/campaigns/{campaign}/recipients/{recipient}/reject', [MarketingCampaignsController::class, 'rejectRecipient'])->name('campaigns.recipients.reject');
            Route::post('/campaigns/{campaign}/send-approved-sms', [MarketingCampaignsController::class, 'sendApprovedSms'])->name('campaigns.send-approved-sms');
            Route::post('/campaigns/{campaign}/send-selected-sms', [MarketingCampaignsController::class, 'sendSelectedSms'])->name('campaigns.send-selected-sms');
            Route::post('/campaigns/{campaign}/recipients/{recipient}/retry-sms', [MarketingCampaignsController::class, 'retryRecipientSms'])->name('campaigns.recipients.retry-sms');
            Route::post('/campaigns/{campaign}/recommendations/generate', [MarketingCampaignsController::class, 'generateRecommendations'])->name('campaigns.recommendations.generate');
            Route::post('/campaigns/{campaign}/add-profile', [MarketingCampaignsController::class, 'addProfileRecipient'])->name('campaigns.add-profile');

            Route::get('/automations', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'automations')
                ->name('automations');
            Route::get('/message-templates', [MarketingMessageTemplatesController::class, 'index'])->name('message-templates');
            Route::get('/message-templates/create', [MarketingMessageTemplatesController::class, 'create'])->name('message-templates.create');
            Route::post('/message-templates', [MarketingMessageTemplatesController::class, 'store'])->name('message-templates.store');
            Route::get('/message-templates/{template}/edit', [MarketingMessageTemplatesController::class, 'edit'])->name('message-templates.edit');
            Route::patch('/message-templates/{template}', [MarketingMessageTemplatesController::class, 'update'])->name('message-templates.update');
            Route::get('/message-templates/{template}/preview', [MarketingMessageTemplatesController::class, 'preview'])->name('message-templates.preview');
            Route::get('/messages/send', [MarketingMessagesController::class, 'send'])->name('messages.send');
            Route::get('/messages/customers/search', [MarketingMessagesController::class, 'searchCustomers'])->name('messages.search-customers');
            Route::post('/messages/send/audience', [MarketingMessagesController::class, 'saveAudience'])->name('messages.save-audience');
            Route::post('/messages/send/message', [MarketingMessagesController::class, 'saveMessage'])->name('messages.save-message');
            Route::post('/messages/send/test', [MarketingMessagesController::class, 'sendTest'])->name('messages.send-test');
            Route::post('/messages/step', [MarketingMessagesController::class, 'setStep'])->name('messages.set-step');
            Route::post('/messages/send/execute', [MarketingMessagesController::class, 'executeSend'])->name('messages.execute');
            Route::post('/messages/send/reset', [MarketingMessagesController::class, 'resetWizard'])->name('messages.reset');
            Route::get('/messages/deliveries', [MarketingMessagesController::class, 'deliveries'])->name('messages.deliveries');

            Route::get('/recommendations', [MarketingRecommendationsController::class, 'index'])->name('recommendations');
            Route::post('/recommendations/generate-global', [MarketingRecommendationsController::class, 'generateGlobal'])->name('recommendations.generate-global');
            Route::post('/recommendations/profile/{profile}', [MarketingRecommendationsController::class, 'createForProfile'])->name('recommendations.create-for-profile');
            Route::post('/recommendations/{recommendation}/approve', [MarketingRecommendationsController::class, 'approve'])->name('recommendations.approve');
            Route::post('/recommendations/{recommendation}/reject', [MarketingRecommendationsController::class, 'reject'])->name('recommendations.reject');
            Route::post('/recommendations/{recommendation}/dismiss', [MarketingRecommendationsController::class, 'dismiss'])->name('recommendations.dismiss');
            Route::get('/candle-cash', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'candle-cash')
                ->name('candle-cash');
            Route::get('/reviews', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'reviews')
                ->name('reviews');
            Route::get('/settings', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'settings')
                ->name('settings');
            Route::get('/providers-integrations', [MarketingProvidersIntegrationsController::class, 'index'])
                ->name('providers-integrations');
            Route::post('/providers-integrations/sync-square', [MarketingProvidersIntegrationsController::class, 'runSquareSync'])
                ->name('providers-integrations.sync-square');
            Route::post('/providers-integrations/import-legacy', [MarketingProvidersIntegrationsController::class, 'importLegacy'])
                ->name('providers-integrations.import-legacy');
            Route::get('/providers-integrations/event-mappings/create', [MarketingProvidersIntegrationsController::class, 'createMapping'])
                ->name('providers-integrations.mappings.create');
            Route::post('/providers-integrations/event-mappings', [MarketingProvidersIntegrationsController::class, 'storeMapping'])
                ->name('providers-integrations.mappings.store');
            Route::get('/providers-integrations/event-mappings/{mapping}', [MarketingProvidersIntegrationsController::class, 'editMapping'])
                ->name('providers-integrations.mappings.edit');
            Route::patch('/providers-integrations/event-mappings/{mapping}', [MarketingProvidersIntegrationsController::class, 'updateMapping'])
                ->name('providers-integrations.mappings.update');
            Route::get('/suppression-consent', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'suppression-consent')
                ->name('suppression-consent');
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
        abort_if(! app(WikiRepository::class)->article('oil-blends'), 404);
        $blends = Blend::query()
            ->with(['components.baseOil'])
            ->orderBy('name')
            ->get();

        return view('wiki.oil-blends', ['blends' => $blends]);
    })->name('wiki.oil-blends');

    Route::get('/wiki/wholesale-custom-scents', function () {
        abort_if(! app(WikiRepository::class)->article('wholesale-custom-scents'), 404);
        $records = WholesaleCustomScent::query()
            ->with('canonicalScent')
            ->orderBy('account_name')
            ->orderBy('custom_scent_name')
            ->get()
            ->groupBy('account_name');

        return view('wiki.wholesale-custom-scents', ['records' => $records]);
    })->name('wiki.wholesale-custom-scents');

    Route::get('/wiki/candle-club', function () {
        abort_if(! app(WikiRepository::class)->article('candle-club'), 404);
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
            if (! $config) {
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

Route::prefix('webhooks/twilio')->group(function () {
    Route::post('/status', [TwilioWebhookController::class, 'status'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('marketing.webhooks.twilio-status');
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
