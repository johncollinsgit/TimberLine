<?php

use App\Http\Controllers\AdminMasterDataController;
use App\Http\Controllers\Birthdays\BirthdayPagesController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Marketing\CandleCashPagesController;
use App\Http\Controllers\Marketing\GoogleBusinessProfileController;
use App\Http\Controllers\Marketing\MarketingCampaignsController;
use App\Http\Controllers\Marketing\MarketingAllOptedInSendController;
use App\Http\Controllers\Marketing\MarketingCustomersController;
use App\Http\Controllers\Marketing\MarketingGroupsController;
use App\Http\Controllers\Marketing\MarketingIdentityReviewController;
use App\Http\Controllers\Marketing\MarketingMessageTemplatesController;
use App\Http\Controllers\Marketing\MarketingOperationsController;
use App\Http\Controllers\Marketing\MarketingPagesController;
use App\Http\Controllers\Marketing\MarketingPublicEventController;
use App\Http\Controllers\Marketing\MarketingProvidersIntegrationsController;
use App\Http\Controllers\Marketing\MarketingRecommendationsController;
use App\Http\Controllers\Marketing\MarketingSegmentsController;
use App\Http\Controllers\Marketing\MarketingShopifyIntegrationController;
use App\Http\Controllers\Marketing\MarketingConsentCaptureController;
use App\Http\Controllers\Marketing\SendGridWebhookController;
use App\Http\Controllers\Marketing\TwilioWebhookController;
use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\ShopifyEmbeddedAppController;
use App\Http\Controllers\ShopifyEmbeddedCustomersController;
use App\Http\Controllers\ShopifyEmbeddedRewardsController;
use App\Http\Controllers\ShopifyEmbeddedSettingsController;
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
use App\Services\Marketing\BirthdayReportingService;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyStores;
use App\Support\Auth\HomeRedirect;
use App\Support\Wiki\WikiRepository;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', function (
    Request $request,
    ShopifyEmbeddedAppContext $contextService,
    ShopifyEmbeddedAppController $controller,
    BirthdayReportingService $birthdayReporting
) {
    if ($contextService->hasPageContext($request)) {
        return $controller->show($request, $contextService, $birthdayReporting);
    }

    if (auth()->check()) {
        return redirect()->to(HomeRedirect::pathFor(auth()->user()));
    }

    return redirect()->route('login');
})->name('home');

Route::get('/rewards', [ShopifyEmbeddedRewardsController::class, 'index'])->name('shopify.embedded.rewards');
Route::get('/rewards/earn', [ShopifyEmbeddedRewardsController::class, 'earn'])->name('shopify.embedded.rewards.earn');
Route::get('/rewards/redeem', [ShopifyEmbeddedRewardsController::class, 'redeem'])->name('shopify.embedded.rewards.redeem');
Route::get('/rewards/referrals', [ShopifyEmbeddedRewardsController::class, 'referrals'])->name('shopify.embedded.rewards.referrals');
Route::get('/rewards/birthdays', [ShopifyEmbeddedRewardsController::class, 'birthdays'])->name('shopify.embedded.rewards.birthdays');
Route::get('/rewards/vip', [ShopifyEmbeddedRewardsController::class, 'vip'])->name('shopify.embedded.rewards.vip');
Route::get('/rewards/notifications', [ShopifyEmbeddedRewardsController::class, 'notifications'])->name('shopify.embedded.rewards.notifications');
Route::get('/customers', [ShopifyEmbeddedCustomersController::class, 'manage'])->name('shopify.embedded.customers');
Route::get('/customers/manage', [ShopifyEmbeddedCustomersController::class, 'manage'])->name('shopify.embedded.customers.manage');
Route::get('/customers/activity', [ShopifyEmbeddedCustomersController::class, 'activity'])->name('shopify.embedded.customers.activity');
Route::get('/customers/questions', [ShopifyEmbeddedCustomersController::class, 'questions'])->name('shopify.embedded.customers.questions');
Route::get('/customers/manage/{marketingProfile}', [ShopifyEmbeddedCustomersController::class, 'detail'])->name('shopify.embedded.customers.detail');
Route::patch('/customers/manage/{marketingProfile}', [ShopifyEmbeddedCustomersController::class, 'update'])->name('shopify.embedded.customers.update');
Route::post('/customers/manage/{marketingProfile}/consent', [ShopifyEmbeddedCustomersController::class, 'updateConsent'])->name('shopify.embedded.customers.update-consent');

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
            Route::get('/customers/data', [MarketingCustomersController::class, 'data'])->name('customers.data');
            Route::get('/customers/create', [MarketingCustomersController::class, 'create'])->name('customers.create');
            Route::post('/customers/create', [MarketingCustomersController::class, 'storeCreate'])->name('customers.store-create');
            Route::get('/customers/{marketingProfile}', [MarketingCustomersController::class, 'show'])->name('customers.show');
            Route::patch('/customers/{marketingProfile}', [MarketingCustomersController::class, 'update'])->name('customers.update');
            Route::post('/customers/{marketingProfile}/birthday', [MarketingCustomersController::class, 'updateBirthday'])->name('customers.update-birthday');
            Route::post('/customers/{marketingProfile}/consent', [MarketingCustomersController::class, 'updateConsent'])->name('customers.update-consent');
            Route::post('/customers/{marketingProfile}/candle-cash/grant', [MarketingCustomersController::class, 'grantCandleCash'])->name('customers.candle-cash.grant');
            Route::post('/customers/{marketingProfile}/candle-cash/redeem', [MarketingCustomersController::class, 'redeemCandleCash'])->name('customers.candle-cash.redeem');
            Route::post('/customers/{marketingProfile}/candle-cash/redemptions/{redemption}/mark-redeemed', [MarketingCustomersController::class, 'markCandleCashRedemptionRedeemed'])
                ->name('customers.candle-cash.redemptions.mark-redeemed');
            Route::post('/customers/{marketingProfile}/candle-cash/redemptions/{redemption}/cancel', [MarketingCustomersController::class, 'cancelCandleCashRedemption'])
                ->name('customers.candle-cash.redemptions.cancel');
            Route::get('/messages', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'messages')
                ->name('messages');
            Route::get('/send/all-opted-in', [MarketingAllOptedInSendController::class, 'show'])->name('send.all-opted-in');
            Route::post('/send/all-opted-in', [MarketingAllOptedInSendController::class, 'submit'])->name('send.all-opted-in.submit');
            Route::get('/identity-review', [MarketingIdentityReviewController::class, 'index'])->name('identity-review');
            Route::get('/identity-review/{review}', [MarketingIdentityReviewController::class, 'show'])->name('identity-review.show');
            Route::post('/identity-review/{review}/resolve-existing', [MarketingIdentityReviewController::class, 'resolveExisting'])->name('identity-review.resolve-existing');
            Route::post('/identity-review/{review}/resolve-new', [MarketingIdentityReviewController::class, 'resolveNew'])->name('identity-review.resolve-new');
            Route::post('/identity-review/{review}/ignore', [MarketingIdentityReviewController::class, 'ignore'])->name('identity-review.ignore');
            Route::get('/orders', [MarketingPagesController::class, 'show'])
                ->defaults('section', 'orders')
                ->name('orders');
            Route::get('/groups', [MarketingGroupsController::class, 'index'])->name('groups');
            Route::get('/groups/create', [MarketingGroupsController::class, 'create'])->name('groups.create');
            Route::post('/groups', [MarketingGroupsController::class, 'store'])->name('groups.store');
            Route::get('/groups/{group}', [MarketingGroupsController::class, 'show'])->name('groups.show');
            Route::get('/groups/{group}/edit', [MarketingGroupsController::class, 'edit'])->name('groups.edit');
            Route::patch('/groups/{group}', [MarketingGroupsController::class, 'update'])->name('groups.update');
            Route::post('/groups/{group}/members', [MarketingGroupsController::class, 'addMember'])->name('groups.members.add');
            Route::delete('/groups/{group}/members/{marketingProfile}', [MarketingGroupsController::class, 'removeMember'])->name('groups.members.remove');
            Route::post('/groups/{group}/import-csv', [MarketingGroupsController::class, 'importCsv'])->name('groups.import-csv');
            Route::get('/groups/{group}/send', [MarketingGroupsController::class, 'sendForm'])->name('groups.send');
            Route::post('/groups/{group}/send', [MarketingGroupsController::class, 'send'])->name('groups.send.execute');
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
            Route::post('/campaigns/{campaign}/send-approved-email', [MarketingCampaignsController::class, 'sendApprovedEmail'])->name('campaigns.send-approved-email');
            Route::post('/campaigns/{campaign}/send-selected-email', [MarketingCampaignsController::class, 'sendSelectedEmail'])->name('campaigns.send-selected-email');
            Route::post('/campaigns/{campaign}/recipients/{recipient}/retry-email', [MarketingCampaignsController::class, 'retryRecipientEmail'])->name('campaigns.recipients.retry-email');
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

            Route::get('/recommendations', [MarketingRecommendationsController::class, 'index'])->name('recommendations');
            Route::post('/recommendations/generate-global', [MarketingRecommendationsController::class, 'generateGlobal'])->name('recommendations.generate-global');
            Route::post('/recommendations/profile/{profile}', [MarketingRecommendationsController::class, 'createForProfile'])->name('recommendations.create-for-profile');
            Route::post('/recommendations/{recommendation}/approve', [MarketingRecommendationsController::class, 'approve'])->name('recommendations.approve');
            Route::post('/recommendations/{recommendation}/reject', [MarketingRecommendationsController::class, 'reject'])->name('recommendations.reject');
            Route::post('/recommendations/{recommendation}/dismiss', [MarketingRecommendationsController::class, 'dismiss'])->name('recommendations.dismiss');
            Route::get('/candle-cash', [CandleCashPagesController::class, 'dashboard'])->name('candle-cash');
            Route::prefix('candle-cash')
                ->name('candle-cash.')
                ->group(function () {
                    Route::get('/tasks', [CandleCashPagesController::class, 'tasks'])->name('tasks');
                    Route::post('/tasks', [CandleCashPagesController::class, 'storeTask'])->name('tasks.store');
                    Route::patch('/tasks/{task}', [CandleCashPagesController::class, 'updateTask'])->name('tasks.update');
                    Route::post('/tasks/{task}/toggle', [CandleCashPagesController::class, 'toggleTask'])->name('tasks.toggle');
                    Route::post('/tasks/{task}/archive', [CandleCashPagesController::class, 'archiveTask'])->name('tasks.archive');
                    Route::get('/queue', [CandleCashPagesController::class, 'queue'])->name('queue');
                    Route::post('/queue/{completion}/approve', [CandleCashPagesController::class, 'approveCompletion'])->name('queue.approve');
                    Route::post('/queue/{completion}/reject', [CandleCashPagesController::class, 'rejectCompletion'])->name('queue.reject');
                    Route::get('/reviews', [CandleCashPagesController::class, 'reviews'])->name('reviews');
                    Route::post('/reviews/{review}/approve', [CandleCashPagesController::class, 'approveReview'])->name('reviews.approve');
                    Route::post('/reviews/{review}/reject', [CandleCashPagesController::class, 'rejectReview'])->name('reviews.reject');
                    Route::post('/reviews/{review}/delete', [CandleCashPagesController::class, 'deleteReview'])->name('reviews.delete');
                    Route::get('/customers', [CandleCashPagesController::class, 'customers'])->name('customers');
                    Route::post('/customers/{marketingProfile}/adjust', [CandleCashPagesController::class, 'adjustCustomer'])->name('customers.adjust');
                    Route::get('/referrals', [CandleCashPagesController::class, 'referrals'])->name('referrals');
                    Route::post('/referrals/{referral}/reprocess', [CandleCashPagesController::class, 'reprocessReferral'])->name('referrals.reprocess');
                    Route::get('/settings', [CandleCashPagesController::class, 'settings'])->name('settings');
                    Route::post('/settings', [CandleCashPagesController::class, 'saveSettings'])->name('settings.save');
                    Route::get('/google-business/connect', [GoogleBusinessProfileController::class, 'connect'])->name('google-business.connect');
                    Route::get('/google-business/status', [GoogleBusinessProfileController::class, 'status'])->name('google-business.status');
                    Route::post('/google-business/disconnect', [GoogleBusinessProfileController::class, 'disconnect'])->name('google-business.disconnect');
                    Route::post('/google-business/sync', [GoogleBusinessProfileController::class, 'sync'])->name('google-business.sync');
                    Route::post('/google-business/select-location', [GoogleBusinessProfileController::class, 'selectLocation'])->name('google-business.select-location');
                });
            Route::get('/operations/reconciliation', [MarketingOperationsController::class, 'reconciliation'])
                ->name('operations.reconciliation');
            Route::post('/operations/reconciliation/issues/{event}/resolve', [MarketingOperationsController::class, 'resolveIssue'])
                ->name('operations.reconciliation.issues.resolve');
            Route::post('/operations/reconciliation/retry', [MarketingOperationsController::class, 'retryReconciliation'])
                ->name('operations.reconciliation.retry');
            Route::post('/operations/reconciliation/redemptions/{redemption}/mark-redeemed', [MarketingOperationsController::class, 'markRedemptionRedeemed'])
                ->name('operations.reconciliation.redemptions.mark-redeemed');
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

Route::get('/marketing/candle-cash/google-business/callback', [GoogleBusinessProfileController::class, 'callback'])
    ->name('marketing.candle-cash.google-business.callback');

    Route::middleware(['role:admin,marketing_manager'])
        ->prefix('birthdays')
        ->name('birthdays.')
        ->group(function () {
            Route::get('/', [BirthdayPagesController::class, 'customers'])->name('customers');
            Route::post('/customers/import/preview', [BirthdayPagesController::class, 'previewImport'])->name('customers.import.preview');
            Route::post('/customers/import', [BirthdayPagesController::class, 'runImport'])->name('customers.import.run');
            Route::post('/customers/{marketingProfile}/issue-reward', [BirthdayPagesController::class, 'issueReward'])->name('customers.issue-reward');

            Route::get('/analytics', [BirthdayPagesController::class, 'analytics'])->name('analytics');
            Route::get('/campaigns', [BirthdayPagesController::class, 'campaigns'])->name('campaigns');
            Route::get('/rewards', [BirthdayPagesController::class, 'rewards'])->name('rewards');
            Route::post('/rewards/{issuance}/activate', [BirthdayPagesController::class, 'activateReward'])->name('rewards.activate');
            Route::post('/rewards/{issuance}/status', [BirthdayPagesController::class, 'updateRewardStatus'])->name('rewards.status');
            Route::get('/settings', [BirthdayPagesController::class, 'settings'])->name('settings');
            Route::post('/settings', [BirthdayPagesController::class, 'saveSettings'])->name('settings.save');
            Route::get('/activity', [BirthdayPagesController::class, 'activity'])->name('activity');
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

Route::prefix('webhooks/sendgrid')->group(function () {
    Route::post('/events', [SendGridWebhookController::class, 'events'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('marketing.webhooks.sendgrid-events');
});

Route::prefix('marketing/consent')->name('marketing.consent.')->middleware('throttle:30,1')->group(function () {
    Route::get('/optin', [MarketingConsentCaptureController::class, 'showOptin'])->name('optin');
    Route::post('/optin', [MarketingConsentCaptureController::class, 'storeOptin'])->name('optin.store');
    Route::get('/verify', [MarketingConsentCaptureController::class, 'showVerify'])->name('verify');
    Route::post('/verify', [MarketingConsentCaptureController::class, 'storeVerify'])->name('verify.store');
});

Route::prefix('events/{eventSlug}')->name('marketing.public.events.')->middleware('throttle:30,1')->group(function () {
    Route::get('/optin', [MarketingPublicEventController::class, 'showOptin'])->name('optin');
    Route::post('/optin', [MarketingPublicEventController::class, 'storeOptin'])->name('optin.store');
    Route::get('/rewards', [MarketingPublicEventController::class, 'showEventRewards'])->name('rewards');
});
Route::get('/rewards/lookup', [MarketingPublicEventController::class, 'rewardsLookup'])
    ->middleware('throttle:30,1')
    ->name('marketing.public.rewards-lookup');
Route::post('/rewards/lookup/redeem', [MarketingPublicEventController::class, 'redeemRewardsLookup'])
    ->middleware('throttle:30,1')
    ->name('marketing.public.rewards-redeem');
Route::get('/account/rewards', [MarketingPublicEventController::class, 'rewardsLookup'])
    ->middleware('throttle:30,1')
    ->name('marketing.public.account-rewards');
Route::post('/account/rewards/redeem', [MarketingPublicEventController::class, 'redeemRewardsLookup'])
    ->middleware('throttle:30,1')
    ->name('marketing.public.account-rewards.redeem');
Route::get('/marketing/consent/confirm', [MarketingPublicEventController::class, 'showConsentConfirm'])
    ->middleware('throttle:30,1')
    ->name('marketing.public.consent-confirm');

Route::prefix('shopify/marketing')
    ->name('marketing.shopify.')
    ->middleware(['marketing.storefront.verify', 'throttle:120,1'])
    ->group(function () {
    Route::get('/rewards/balance', [MarketingShopifyIntegrationController::class, 'rewardBalance'])->name('rewards.balance');
    Route::get('/rewards/available', [MarketingShopifyIntegrationController::class, 'availableRewards'])->name('rewards.available');
    Route::get('/rewards/history', [MarketingShopifyIntegrationController::class, 'rewardHistory'])->name('rewards.history');
    Route::get('/customer/status', [MarketingShopifyIntegrationController::class, 'customerStatus'])->name('customer.status');
    Route::get('/health', [MarketingShopifyIntegrationController::class, 'proxyHealth'])->name('health');
    Route::post('/rewards/redeem', [MarketingShopifyIntegrationController::class, 'requestRedemption'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('rewards.redeem');
    Route::post('/rewards/event', [MarketingShopifyIntegrationController::class, 'logRewardEvent'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('rewards.event');
    Route::get('/consent/status', [MarketingShopifyIntegrationController::class, 'consentStatus'])->name('consent.status');
    Route::post('/consent/request', [MarketingShopifyIntegrationController::class, 'requestConsentOptin'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('consent.request');
    Route::post('/consent/optin', [MarketingShopifyIntegrationController::class, 'requestConsentOptin'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('consent.optin');
    Route::post('/consent/confirm', [MarketingShopifyIntegrationController::class, 'confirmConsentOptin'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('consent.confirm');
    Route::get('/birthday/status', [MarketingShopifyIntegrationController::class, 'birthdayStatus'])->name('birthday.status');
    Route::post('/birthday/capture', [MarketingShopifyIntegrationController::class, 'captureBirthday'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('birthday.capture');
    Route::post('/birthday/claim', [MarketingShopifyIntegrationController::class, 'claimBirthdayReward'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('birthday.claim');
    Route::get('/candle-cash/status', [MarketingShopifyIntegrationController::class, 'candleCashStatus'])->name('candle-cash.status');
    Route::post('/candle-cash/tasks/submit', [MarketingShopifyIntegrationController::class, 'submitCandleCashTask'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('candle-cash.tasks.submit');
    Route::get('/product-reviews/status', [MarketingShopifyIntegrationController::class, 'productReviewStatus'])->name('product-reviews.status');
    Route::post('/product-reviews/submit', [MarketingShopifyIntegrationController::class, 'submitProductReview'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('product-reviews.submit');
    Route::post('/google-business/review/start', [MarketingShopifyIntegrationController::class, 'startGoogleBusinessReview'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('google-business.review.start');
});

Route::prefix('shopify/marketing/v1')
    ->name('marketing.shopify.v1.')
    ->middleware(['marketing.storefront.verify', 'throttle:120,1'])
    ->group(function () {
    Route::get('/rewards/balance', [MarketingShopifyIntegrationController::class, 'rewardBalance'])->name('rewards.balance');
    Route::get('/rewards/available', [MarketingShopifyIntegrationController::class, 'availableRewards'])->name('rewards.available');
    Route::get('/rewards/history', [MarketingShopifyIntegrationController::class, 'rewardHistory'])->name('rewards.history');
    Route::get('/customer/status', [MarketingShopifyIntegrationController::class, 'customerStatus'])->name('customer.status');
    Route::get('/health', [MarketingShopifyIntegrationController::class, 'proxyHealth'])->name('health');
    Route::post('/rewards/redeem', [MarketingShopifyIntegrationController::class, 'requestRedemption'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('rewards.redeem');
    Route::post('/rewards/event', [MarketingShopifyIntegrationController::class, 'logRewardEvent'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('rewards.event');
    Route::get('/consent/status', [MarketingShopifyIntegrationController::class, 'consentStatus'])->name('consent.status');
    Route::post('/consent/request', [MarketingShopifyIntegrationController::class, 'requestConsentOptin'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('consent.request');
    Route::post('/consent/optin', [MarketingShopifyIntegrationController::class, 'requestConsentOptin'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('consent.optin');
    Route::post('/consent/confirm', [MarketingShopifyIntegrationController::class, 'confirmConsentOptin'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('consent.confirm');
    Route::get('/birthday/status', [MarketingShopifyIntegrationController::class, 'birthdayStatus'])->name('birthday.status');
    Route::post('/birthday/capture', [MarketingShopifyIntegrationController::class, 'captureBirthday'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('birthday.capture');
    Route::post('/birthday/claim', [MarketingShopifyIntegrationController::class, 'claimBirthdayReward'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('birthday.claim');
    Route::get('/candle-cash/status', [MarketingShopifyIntegrationController::class, 'candleCashStatus'])->name('candle-cash.status');
    Route::post('/candle-cash/tasks/submit', [MarketingShopifyIntegrationController::class, 'submitCandleCashTask'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('candle-cash.tasks.submit');
    Route::get('/product-reviews/status', [MarketingShopifyIntegrationController::class, 'productReviewStatus'])->name('product-reviews.status');
    Route::post('/product-reviews/submit', [MarketingShopifyIntegrationController::class, 'submitProductReview'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('product-reviews.submit');
    Route::post('/google-business/review/start', [MarketingShopifyIntegrationController::class, 'startGoogleBusinessReview'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('google-business.review.start');
});

Route::prefix('shopify')->group(function () {
    Route::get('/app', [ShopifyEmbeddedAppController::class, 'show'])->name('shopify.app');
    Route::get('/app/rewards', [ShopifyEmbeddedRewardsController::class, 'index'])->name('shopify.app.rewards');
    Route::get('/app/customers', [ShopifyEmbeddedCustomersController::class, 'manage'])->name('shopify.app.customers');
    Route::get('/app/customers/manage', [ShopifyEmbeddedCustomersController::class, 'manage'])->name('shopify.app.customers.manage');
    Route::get('/app/customers/activity', [ShopifyEmbeddedCustomersController::class, 'activity'])->name('shopify.app.customers.activity');
    Route::get('/app/customers/questions', [ShopifyEmbeddedCustomersController::class, 'questions'])->name('shopify.app.customers.questions');
    Route::get('/app/customers/manage/{marketingProfile}', [ShopifyEmbeddedCustomersController::class, 'detail'])->name('shopify.app.customers.detail');
    Route::patch('/app/customers/manage/{marketingProfile}', [ShopifyEmbeddedCustomersController::class, 'update'])->name('shopify.app.customers.update');
    Route::post('/app/customers/manage/{marketingProfile}/consent', [ShopifyEmbeddedCustomersController::class, 'updateConsent'])->name('shopify.app.customers.update-consent');
    Route::get('/app/settings', [ShopifyEmbeddedSettingsController::class, 'show'])->name('shopify.app.settings');
    Route::prefix('app/api')->name('shopify.app.api.')->group(function () {
        Route::get('/rewards', [ShopifyEmbeddedRewardsController::class, 'data'])->name('rewards');
        Route::patch('/rewards/earn/{task}', [ShopifyEmbeddedRewardsController::class, 'updateEarnRule'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.earn.update');
        Route::patch('/rewards/redeem/{reward}', [ShopifyEmbeddedRewardsController::class, 'updateRedeemRule'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.redeem.update');
    });
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
