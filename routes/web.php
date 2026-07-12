<?php

use App\Http\Controllers\AdminMasterDataController;
use App\Http\Controllers\Birthdays\BirthdayPagesController;
use App\Http\Controllers\ClientProjectController;
use App\Http\Controllers\ClientProjectTicketController;
use App\Http\Controllers\CustomModuleRequestController;
use App\Http\Controllers\Discovery\BrandDiscoveryController;
use App\Http\Controllers\EvergroveServiceInquiryController;
use App\Http\Controllers\EvergroveServicesController;
use App\Http\Controllers\FieldServiceController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Integrations\QuickBooksConnectionController;
use App\Http\Controllers\Landlord\LandlordClientProjectTicketController;
use App\Http\Controllers\Landlord\LandlordCommercialConfigurationController;
use App\Http\Controllers\Landlord\LandlordCustomModuleRequestController;
use App\Http\Controllers\Landlord\LandlordDeveloperDashboardController;
use App\Http\Controllers\Landlord\LandlordOnboardingJourneyDiagnosticsController;
use App\Http\Controllers\Landlord\LandlordSelfServiceReadinessController;
use App\Http\Controllers\Landlord\LandlordServiceInquiryController;
use App\Http\Controllers\Landlord\LandlordTenantDirectoryController;
use App\Http\Controllers\Landlord\LandlordTenantOperationsController;
use App\Http\Controllers\Marketing\CandleCashPagesController;
use App\Http\Controllers\Marketing\GoogleBusinessProfileController;
use App\Http\Controllers\Marketing\MarketingAllOptedInSendController;
use App\Http\Controllers\Marketing\MarketingCampaignsController;
use App\Http\Controllers\Marketing\MarketingConsentCaptureController;
use App\Http\Controllers\Marketing\MarketingCustomersController;
use App\Http\Controllers\Marketing\MarketingGroupsController;
use App\Http\Controllers\Marketing\MarketingIdentityReviewController;
use App\Http\Controllers\Marketing\MarketingMessagesController;
use App\Http\Controllers\Marketing\MarketingMessageTemplatesController;
use App\Http\Controllers\Marketing\MarketingModuleStoreController;
use App\Http\Controllers\Marketing\MarketingOperationsController;
use App\Http\Controllers\Marketing\MarketingPagesController;
use App\Http\Controllers\Marketing\MarketingProvidersIntegrationsController;
use App\Http\Controllers\Marketing\MarketingPublicEventController;
use App\Http\Controllers\Marketing\MarketingRecommendationsController;
use App\Http\Controllers\Marketing\MarketingResultsController;
use App\Http\Controllers\Marketing\MarketingSegmentsController;
use App\Http\Controllers\Marketing\MarketingShopifyIntegrationController;
use App\Http\Controllers\Marketing\MarketingShortLinkRedirectController;
use App\Http\Controllers\Marketing\MarketingWishlistController;
use App\Http\Controllers\Marketing\ModernForestryAppFeedbackController;
use App\Http\Controllers\Marketing\SendGridInboundWebhookController;
use App\Http\Controllers\Marketing\SendGridWebhookController;
use App\Http\Controllers\Marketing\SesWebhookController;
use App\Http\Controllers\Marketing\TwilioWebhookController;
use App\Http\Controllers\Mobile\ModernForestryProductCatalogController;
use App\Http\Controllers\Onboarding\CustomerStartHereController;
use App\Http\Controllers\Onboarding\FirstLoginWorkspaceController;
use App\Http\Controllers\Onboarding\OnboardingHarnessController;
use App\Http\Controllers\Onboarding\OnboardingProvisioningApiController;
use App\Http\Controllers\Onboarding\OnboardingWizardApiController;
use App\Http\Controllers\PlatformAccessRequestController;
use App\Http\Controllers\PlatformProductPagesController;
use App\Http\Controllers\PublicBudConversationController;
use App\Http\Controllers\PublicLegalController;
use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\ShopifyEmbeddedAiAssistantController;
use App\Http\Controllers\ShopifyEmbeddedAppController;
use App\Http\Controllers\ShopifyEmbeddedCustomersController;
use App\Http\Controllers\ShopifyEmbeddedDevelopmentNotesController;
use App\Http\Controllers\ShopifyEmbeddedMessagingController;
use App\Http\Controllers\ShopifyEmbeddedRewardsController;
use App\Http\Controllers\ShopifyEmbeddedSettingsController;
use App\Http\Controllers\ShopifyEmbeddedSubscriptionsController;
use App\Http\Controllers\ShopifyPrivacyWebhookController;
use App\Http\Controllers\ShopifyProductOptionsController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\SubscriptionPublicController;
use App\Http\Controllers\SubscriptionStorefrontController;
use App\Http\Controllers\UiPreferencesController;
use App\Http\Controllers\WholesaleApplicationInboxController;
use App\Http\Controllers\WikiAdminController;
use App\Http\Controllers\WikiController;
use App\Livewire\Admin\AdminHome;
use App\Livewire\Admin\Catalog\CostsCrud as AdminCostsCrud;
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
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedUrlGenerator;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantResolver;
use App\Support\Auth\HomeRedirect;
use App\Support\Wiki\WikiRepository;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

$normalizeHost = static function (mixed $value): ?string {
    $host = strtolower(trim((string) $value));
    if ($host === '') {
        return null;
    }

    $host = preg_replace('#^https?://#', '', $host);
    $host = explode('/', (string) $host)[0] ?? '';
    $host = explode(':', (string) $host)[0] ?? '';
    $host = trim((string) $host, '.');

    return $host !== '' ? $host : null;
};

$evergrovePublicHosts = collect((array) config('evergrove.hosts', []))
    ->map(static fn (mixed $host): ?string => $normalizeHost($host))
    ->filter(static fn (?string $host): bool => $host !== null)
    ->unique()
    ->values()
    ->all();

Route::get('/', function (
    Request $request,
    EvergroveServicesController $evergroveController,
    ShopifyEmbeddedAppContext $contextService,
    ShopifyEmbeddedAppController $controller,
    TenantResolver $tenantResolver,
    TenantDisplayLabelResolver $displayLabelResolver,
    PlatformProductPagesController $platformPagesController,
    TenantCommercialExperienceService $experienceService,
    ModernForestryAlphaBootstrapService $alphaBootstrapService
) use ($evergrovePublicHosts, $normalizeHost) {
    if ($contextService->hasPageContext($request)) {
        return $controller->show($request, $contextService, $tenantResolver, $displayLabelResolver, $experienceService, $alphaBootstrapService);
    }

    $requestHost = $normalizeHost($request->getHost()) ?? '';
    if ($requestHost !== '' && in_array($requestHost, $evergrovePublicHosts, true)) {
        return $evergroveController->home();
    }

    if (auth()->check()) {
        return redirect()->to(HomeRedirect::pathFor(auth()->user()));
    }

    if ($requestHost !== '' && $requestHost === $normalizeHost((string) config('tenancy.landlord.primary_host', ''))) {
        return redirect()->route('login');
    }

    return $platformPagesController->promo($experienceService);
})->name('home');

Route::get('/privacy', [PublicLegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [PublicLegalController::class, 'terms'])->name('legal.terms');

$landlordHosts = collect((array) config('tenancy.landlord.hosts', []))
    ->map(static fn (mixed $host): ?string => $normalizeHost($host))
    ->filter(static fn (?string $host): bool => $host !== null)
    ->unique()
    ->values()
    ->all();
$landlordPrimaryHost = $normalizeHost((string) config('tenancy.landlord.primary_host', '')) ?? '';
if ($landlordPrimaryHost === '' && isset($landlordHosts[0]) && is_string($landlordHosts[0])) {
    $landlordPrimaryHost = $landlordHosts[0];
}
if ($landlordPrimaryHost !== '' && ! in_array($landlordPrimaryHost, $landlordHosts, true)) {
    array_unshift($landlordHosts, $landlordPrimaryHost);
}
$landlordHosts = array_values(array_unique(array_filter($landlordHosts, static fn (string $host): bool => $host !== '')));
$landlordRoutes = static function (): void {
    Route::get('/landlord', [LandlordTenantDirectoryController::class, 'dashboard'])
        ->name('dashboard');
    Route::get('/landlord/readiness', LandlordSelfServiceReadinessController::class)
        ->name('readiness');
    Route::get('/landlord/developer', LandlordDeveloperDashboardController::class)
        ->name('developer');
    Route::get('/landlord/onboarding/journey', [LandlordOnboardingJourneyDiagnosticsController::class, 'index'])
        ->name('onboarding.journey');
    Route::get('/landlord/onboarding/wizard', \App\Livewire\Onboarding\Wizard::class)
        ->middleware('tenant.access')
        ->name('onboarding.wizard');
    Route::get('/landlord/onboarding/intake', [LandlordOnboardingJourneyDiagnosticsController::class, 'intake'])
        ->name('onboarding.intake');
    Route::post('/landlord/onboarding/setup-status/{tenant}', [LandlordOnboardingJourneyDiagnosticsController::class, 'updateSetupStatus'])
        ->name('onboarding.setup-status.update');
    Route::get('/landlord/commercial-intent', [LandlordOnboardingJourneyDiagnosticsController::class, 'commercialIntent'])
        ->name('commercial-intent.index');
    Route::post('/landlord/commercial-intent/{tenant}', [LandlordOnboardingJourneyDiagnosticsController::class, 'updateCommercialIntent'])
        ->name('commercial-intent.update');
    Route::get('/landlord/custom-module-requests', [LandlordCustomModuleRequestController::class, 'index'])
        ->name('custom-module-requests.index');
    Route::post('/landlord/custom-module-requests/{customModuleRequest}', [LandlordCustomModuleRequestController::class, 'update'])
        ->name('custom-module-requests.update');
    Route::get('/landlord/client-project-tickets', [LandlordClientProjectTicketController::class, 'index'])
        ->name('client-project-tickets.index');
    Route::post('/landlord/client-project-tickets/{ticket}', [LandlordClientProjectTicketController::class, 'update'])
        ->name('client-project-tickets.update');
    Route::get('/landlord/service-inquiries', [LandlordServiceInquiryController::class, 'index'])
        ->name('service-inquiries.index');
    Route::get('/landlord/messages', [LandlordServiceInquiryController::class, 'index'])
        ->name('messages.index');
    Route::get('/landlord/commercial', [LandlordCommercialConfigurationController::class, 'index'])
        ->name('commercial.index');
    Route::get('/landlord/commercial/analytics/tenants', [LandlordCommercialConfigurationController::class, 'tenantAnalyticsTable'])
        ->name('commercial.analytics.tenants');
    Route::get('/landlord/commercial/analytics/activity', [LandlordCommercialConfigurationController::class, 'tenantAnalyticsActivity'])
        ->name('commercial.analytics.activity');
    Route::post('/landlord/commercial/catalog/{type}/upsert', [LandlordCommercialConfigurationController::class, 'upsertCatalogEntry'])
        ->name('commercial.catalog.upsert');
    Route::post('/landlord/commercial/templates/{entryKey}/duplicate', [LandlordCommercialConfigurationController::class, 'duplicateTemplate'])
        ->name('commercial.templates.duplicate');
    Route::post('/landlord/commercial/templates/{entryKey}/state', [LandlordCommercialConfigurationController::class, 'setTemplateState'])
        ->name('commercial.templates.state');
    Route::post('/landlord/commercial/templates/reorder', [LandlordCommercialConfigurationController::class, 'reorderTemplates'])
        ->name('commercial.templates.reorder');
    Route::get('/landlord/tenants', [LandlordTenantDirectoryController::class, 'index'])
        ->name('tenants.index');
    Route::get('/landlord/tenants/create', [LandlordTenantDirectoryController::class, 'create'])
        ->name('tenants.create');
    Route::post('/landlord/tenants', [LandlordTenantDirectoryController::class, 'store'])
        ->name('tenants.store');
    Route::match(['put', 'patch'], '/landlord/tenants/{tenant}', [LandlordTenantDirectoryController::class, 'update'])
        ->name('tenants.update');
    Route::delete('/landlord/tenants/{tenant}', [LandlordTenantDirectoryController::class, 'destroy'])
        ->name('tenants.destroy');
    Route::get('/landlord/tenants/{tenant}/blueprint/edit', [LandlordTenantDirectoryController::class, 'editBlueprint'])
        ->name('tenants.blueprint.edit');
    Route::match(['put', 'patch'], '/landlord/tenants/{tenant}/blueprint', [LandlordTenantDirectoryController::class, 'updateBlueprint'])
        ->name('tenants.blueprint.update');
    Route::get('/landlord/tenants/{tenant}', [LandlordTenantDirectoryController::class, 'show'])
        ->name('tenants.show');
    Route::post('/landlord/tenants/{tenant}/forms/templates/{templateKey}', [LandlordTenantDirectoryController::class, 'provisionFormFromTemplate'])
        ->name('tenants.forms.templates.provision');
    Route::post('/landlord/tenants/{tenant}/role', [LandlordTenantDirectoryController::class, 'updateRole'])
        ->name('tenants.role.update');
    Route::post('/landlord/tenants/{tenant}/type', [LandlordTenantDirectoryController::class, 'updateType'])
        ->name('tenants.type.update');
    Route::post('/landlord/tenants/{tenant}/modules', [LandlordTenantDirectoryController::class, 'updateModules'])
        ->name('tenants.modules.update');
    Route::post('/landlord/tenants/{tenant}/users/remove', [LandlordTenantDirectoryController::class, 'removeUser'])
        ->name('tenants.users.remove');
    Route::post('/landlord/tenants/select', [LandlordTenantOperationsController::class, 'selectTenant'])
        ->name('tenants.select');
    Route::post('/landlord/tenants/{tenant}/operations/export', [LandlordTenantOperationsController::class, 'export'])
        ->name('tenants.operations.export');
    Route::post('/landlord/tenants/{tenant}/operations/restore', [LandlordTenantOperationsController::class, 'restore'])
        ->name('tenants.operations.restore');
    Route::post('/landlord/tenants/{tenant}/operations/customers/modify', [LandlordTenantOperationsController::class, 'modifyCustomer'])
        ->name('tenants.operations.customers.modify');
    Route::post('/landlord/tenants/{tenant}/operations/customers/archive', [LandlordTenantOperationsController::class, 'archiveCustomer'])
        ->name('tenants.operations.customers.archive');
    Route::get('/landlord/tenants/{tenant}/operations/exports/{action}', [LandlordTenantOperationsController::class, 'downloadExport'])
        ->name('tenants.operations.exports.download');
    Route::post('/landlord/tenants/{tenant}/commercial/plan', [LandlordCommercialConfigurationController::class, 'assignTenantPlan'])
        ->name('tenants.commercial.plan');
    Route::post('/landlord/tenants/{tenant}/commercial/entitlements/{moduleKey}', [LandlordCommercialConfigurationController::class, 'updateTenantModuleEntitlement'])
        ->name('tenants.commercial.entitlements.update');
    Route::post('/landlord/tenants/{tenant}/commercial/override', [LandlordCommercialConfigurationController::class, 'updateTenantCommercialOverride'])
        ->name('tenants.commercial.override');
    Route::post('/landlord/tenants/{tenant}/commercial/modules/{moduleKey}', [LandlordCommercialConfigurationController::class, 'updateTenantModuleState'])
        ->name('tenants.commercial.modules.update');
    Route::post('/landlord/tenants/{tenant}/commercial/addons/{addonKey}', [LandlordCommercialConfigurationController::class, 'updateTenantAddonState'])
        ->name('tenants.commercial.addons.update');
    Route::post('/landlord/tenants/{tenant}/commercial/billing/stripe/customer-sync', [LandlordCommercialConfigurationController::class, 'syncTenantStripeCustomer'])
        ->name('tenants.commercial.billing.stripe.customer-sync');
    Route::post('/landlord/tenants/{tenant}/commercial/billing/stripe/subscription-prep', [LandlordCommercialConfigurationController::class, 'syncTenantStripeSubscriptionPrep'])
        ->name('tenants.commercial.billing.stripe.subscription-prep');
    Route::post('/landlord/tenants/{tenant}/commercial/billing/stripe/subscription-live-sync', [LandlordCommercialConfigurationController::class, 'syncTenantStripeLiveSubscription'])
        ->name('tenants.commercial.billing.stripe.subscription-live-sync');
    Route::post('/landlord/tenants/{tenant}/commercial/billing/stripe/fulfillment-reconcile', [LandlordCommercialConfigurationController::class, 'reconcileTenantStripeFulfillment'])
        ->name('tenants.commercial.billing.stripe.fulfillment-reconcile');
};

if ($landlordPrimaryHost !== '') {
    Route::domain($landlordPrimaryHost)
        ->middleware(['auth', 'verified', 'landlord.operator'])
        ->name('landlord.')
        ->group($landlordRoutes);
}

Route::get('/rewards', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.rewards', [], false, $request));
})->name('shopify.embedded.rewards');
Route::get('/rewards/earn', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.rewards.earn', [], false, $request));
})->name('shopify.embedded.rewards.earn');
Route::get('/rewards/redeem', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.rewards.redeem', [], false, $request));
})->name('shopify.embedded.rewards.redeem');
Route::get('/rewards/referrals', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.rewards.referrals', [], false, $request));
})->name('shopify.embedded.rewards.referrals');
Route::get('/rewards/birthdays', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.rewards.birthdays', [], false, $request));
})->name('shopify.embedded.rewards.birthdays');
Route::get('/rewards/vip', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.rewards.vip', [], false, $request));
})->name('shopify.embedded.rewards.vip');
Route::get('/rewards/notifications', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.rewards.notifications', [], false, $request));
})->name('shopify.embedded.rewards.notifications');
Route::get('/customers', [ShopifyEmbeddedCustomersController::class, 'redirectLegacyToManage'])->name('shopify.embedded.customers');
Route::get('/customers/manage', [ShopifyEmbeddedCustomersController::class, 'redirectLegacyToManage'])->name('shopify.embedded.customers.manage');
Route::get('/customers/segments', [ShopifyEmbeddedCustomersController::class, 'redirectLegacyToSegments'])->name('shopify.embedded.customers.segments');
Route::get('/customers/activity', [ShopifyEmbeddedCustomersController::class, 'redirectLegacyToActivity'])->name('shopify.embedded.customers.activity');
Route::get('/customers/imports', [ShopifyEmbeddedCustomersController::class, 'redirectLegacyToImports'])->name('shopify.embedded.customers.imports');
Route::get('/customers/questions', [ShopifyEmbeddedCustomersController::class, 'redirectLegacyToImports'])->name('shopify.embedded.customers.questions');
Route::get('/customers/manage/{marketingProfile}', [ShopifyEmbeddedCustomersController::class, 'redirectLegacyToDetail'])->name('shopify.embedded.customers.detail');
Route::get('/messaging', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.messaging', [], false, $request));
})->name('shopify.embedded.messaging');
Route::get('/messaging/analytics', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.messaging.analytics', [], false, $request));
})->name('shopify.embedded.messaging.analytics');
Route::get('/messaging/setup', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.messaging.setup', [], false, $request));
})->name('shopify.embedded.messaging.setup');
Route::get('/messaging/responses', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.messaging.responses', [], false, $request));
})->name('shopify.embedded.messaging.responses');
Route::get('/assistant', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.assistant.start', [], false, $request));
})->name('shopify.embedded.assistant');
Route::get('/assistant/opportunities', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.assistant.opportunities', [], false, $request));
})->name('shopify.embedded.assistant.opportunities');
Route::get('/assistant/drafts', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.assistant.drafts', [], false, $request));
})->name('shopify.embedded.assistant.drafts');
Route::get('/assistant/setup', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.assistant.setup', [], false, $request));
})->name('shopify.embedded.assistant.setup');
Route::get('/assistant/activity', function (Request $request, ShopifyEmbeddedUrlGenerator $urlGenerator) {
    return redirect()->to($urlGenerator->route('shopify.app.assistant.activity', [], false, $request));
})->name('shopify.embedded.assistant.activity');
Route::get('/go/{code}', [MarketingShortLinkRedirectController::class, 'show'])->name('marketing.short-links.redirect');
Route::get('/lander', [EvergroveServicesController::class, 'lander'])->name('evergrove.lander');
Route::get('/tools/project-estimate', [EvergroveServicesController::class, 'projectEstimate'])->name('evergrove.tools.project-estimate');
Route::get('/tools/ai-roi', [EvergroveServicesController::class, 'aiRoi'])->name('evergrove.tools.ai-roi');
Route::get('/tools/automation-savings', [EvergroveServicesController::class, 'automationSavings'])->name('evergrove.tools.automation-savings');
Route::post('/services/inquiries', [EvergroveServiceInquiryController::class, 'store'])->name('evergrove.inquiries.store');
Route::post('/platform/bud/conversations', [PublicBudConversationController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('platform.bud.conversations');
Route::get('/platform/promo', [PlatformProductPagesController::class, 'promo'])->name('platform.promo');
Route::get('/platform/plans', [PlatformProductPagesController::class, 'plans'])->name('platform.plans');
Route::get('/platform/demo', [PlatformProductPagesController::class, 'demo'])->name('platform.demo');
Route::get('/platform/start', [PlatformProductPagesController::class, 'start'])->name('platform.start');
Route::get('/platform/request-submitted', [PlatformProductPagesController::class, 'requestSubmitted'])->name('platform.request-submitted');
Route::get('/platform/contact', [PlatformProductPagesController::class, 'contact'])->name('platform.contact');
Route::post('/platform/access-request', [PlatformAccessRequestController::class, 'store'])->name('platform.access-request');
Route::get('/platform/catalog', [PlatformProductPagesController::class, 'catalogFeed'])->name('platform.catalog.feed');
Route::get('/.well-known/brand-discovery.json', [BrandDiscoveryController::class, 'wellKnown'])->name('discovery.well-known.brand');
Route::get('/api/public/discovery/brand/{tenant}', [BrandDiscoveryController::class, 'byTenant'])->name('discovery.public.brand');
Route::get('/api/public/discovery/structured/{tenant?}', [BrandDiscoveryController::class, 'structured'])->name('discovery.public.structured');
Route::get('/api/mobile/v1/modern-forestry/home', [ModernForestryProductCatalogController::class, 'home'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.home');
Route::get('/api/mobile/v1/modern-forestry/collections', [ModernForestryProductCatalogController::class, 'collections'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.collections');
Route::get('/api/mobile/v1/modern-forestry/collections/{handle}/products', [ModernForestryProductCatalogController::class, 'collectionProducts'])
    ->where('handle', '[A-Za-z0-9][A-Za-z0-9-]*')
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.collections.products');
Route::get('/api/mobile/v1/modern-forestry/products', ModernForestryProductCatalogController::class)
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.products');
Route::get('/api/mobile/v1/modern-forestry/products/{handle}', [ModernForestryProductCatalogController::class, 'show'])
    ->where('handle', '[A-Za-z0-9][A-Za-z0-9-]*')
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.products.show');
Route::post('/api/mobile/v1/modern-forestry/checkout', [ModernForestryProductCatalogController::class, 'checkout'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.checkout');
Route::post('/api/mobile/v1/modern-forestry/auth/session', [ModernForestryProductCatalogController::class, 'authSession'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.auth.session');
Route::get('/api/mobile/v1/modern-forestry/auth/config', [ModernForestryProductCatalogController::class, 'authConfig'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.auth.config');
Route::get('/api/mobile/v1/modern-forestry/auth/callback', [ModernForestryProductCatalogController::class, 'authCallback'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.auth.callback');
Route::post('/api/mobile/v1/modern-forestry/auth/token', [ModernForestryProductCatalogController::class, 'authToken'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.auth.token');
Route::post('/api/mobile/v1/modern-forestry/auth/refresh', [ModernForestryProductCatalogController::class, 'authRefresh'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.auth.refresh');
Route::post('/api/mobile/v1/modern-forestry/auth/review-demo', [ModernForestryProductCatalogController::class, 'authReviewDemo'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.auth.review-demo');
Route::get('/api/mobile/v1/modern-forestry/account', [ModernForestryProductCatalogController::class, 'account'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.account');
Route::get('/api/mobile/v1/modern-forestry/account/candle-club', [ModernForestryProductCatalogController::class, 'candleClub'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.account.candle-club');
Route::post('/api/mobile/v1/modern-forestry/account/candle-club/action', [ModernForestryProductCatalogController::class, 'candleClubAction'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.account.candle-club.action');
Route::post('/api/mobile/v1/modern-forestry/account/candle-club/monthly-scents/{monthlyScent}/feedback', [ModernForestryProductCatalogController::class, 'candleClubScentFeedback'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.account.candle-club.monthly-scents.feedback');
Route::post('/api/mobile/v1/modern-forestry/account/message', [ModernForestryProductCatalogController::class, 'accountMessage'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.account.message');
Route::post('/api/mobile/v1/modern-forestry/account/messages/read', [ModernForestryProductCatalogController::class, 'accountMessagesRead'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.account.messages.read');
Route::post('/api/mobile/v1/modern-forestry/account/profile-photo', [ModernForestryProductCatalogController::class, 'accountProfilePhoto'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:20,1')
    ->name('mobile.modern-forestry.account.profile-photo');
Route::post('/api/mobile/v1/modern-forestry/notifications/push/register', [ModernForestryProductCatalogController::class, 'registerPushDevice'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.notifications.push.register');
Route::get('/api/mobile/v1/modern-forestry/wishlist/status', [ModernForestryProductCatalogController::class, 'wishlistStatus'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.wishlist.status');
Route::post('/api/mobile/v1/modern-forestry/wishlist/add', [ModernForestryProductCatalogController::class, 'wishlistAdd'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.wishlist.add');
Route::post('/api/mobile/v1/modern-forestry/wishlist/remove', [ModernForestryProductCatalogController::class, 'wishlistRemove'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.wishlist.remove');
Route::get('/api/mobile/v1/modern-forestry/scents', [ModernForestryProductCatalogController::class, 'scents'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.scents');
Route::get('/api/mobile/v1/modern-forestry/scent-quiz', [ModernForestryProductCatalogController::class, 'scentQuiz'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.scent-quiz');
Route::post('/api/mobile/v1/modern-forestry/scent-quiz/results', [ModernForestryProductCatalogController::class, 'saveScentQuizResult'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.scent-quiz.results');
Route::get('/api/mobile/v1/modern-forestry/social-share/config', [ModernForestryProductCatalogController::class, 'socialShareConfig'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.social-share.config');
Route::post('/api/mobile/v1/modern-forestry/social-share/started', [ModernForestryProductCatalogController::class, 'socialShareStarted'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.social-share.started');
Route::post('/api/mobile/v1/modern-forestry/social-share/claim', [ModernForestryProductCatalogController::class, 'socialShareClaim'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.social-share.claim');
Route::get('/api/mobile/v1/modern-forestry/rewards', [ModernForestryProductCatalogController::class, 'rewards'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.rewards');
Route::post('/api/mobile/v1/modern-forestry/rewards/redeem', [ModernForestryProductCatalogController::class, 'redeemReward'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.rewards.redeem');
Route::post('/api/mobile/v1/modern-forestry/rewards/release', [ModernForestryProductCatalogController::class, 'releaseReward'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.rewards.release');
Route::post('/api/mobile/v1/modern-forestry/bag/sync', [ModernForestryProductCatalogController::class, 'syncBag'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.bag.sync');
Route::get('/api/mobile/v1/modern-forestry/product-reviews/status', [ModernForestryProductCatalogController::class, 'productReviewStatus'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.product-reviews.status');
Route::post('/api/mobile/v1/modern-forestry/product-reviews/submit', [ModernForestryProductCatalogController::class, 'submitProductReview'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:30,1')
    ->name('mobile.modern-forestry.product-reviews.submit');
Route::get('/api/mobile/v1/modern-forestry/session-status', [ModernForestryProductCatalogController::class, 'sessionStatus'])
    ->middleware('throttle:60,1')
    ->name('mobile.modern-forestry.session-status');
Route::get('/mobile/authorize', \App\Http\Controllers\Mobile\EverbranchMobileAuthorizationController::class)
    ->middleware('throttle:20,1')
    ->name('mobile.everbranch.authorize');
Route::get('/sitemaps/discovery.xml', [BrandDiscoveryController::class, 'sitemap'])->name('discovery.sitemap');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/workspace/create', [FirstLoginWorkspaceController::class, 'show'])
        ->name('workspace.first-login');
    Route::post('/workspace/create', [FirstLoginWorkspaceController::class, 'store'])
        ->name('workspace.first-login.store');

    // Dashboard
    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access'])->group(function () {
        Route::get('/dashboard', DashboardLaunchpad::class)->name('dashboard');
    });
    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access'])
        ->get('/search', [GlobalSearchController::class, 'index'])
        ->name('app.search');

    Route::middleware(['role:admin,manager,marketing_manager'])
        ->prefix('workspaces/{tenant:slug}/integrations/quickbooks')
        ->name('integrations.quickbooks.')
        ->group(function (): void {
            Route::get('/connect', [QuickBooksConnectionController::class, 'connect'])->name('connect');
        });

    Route::middleware(['role:admin,manager,marketing_manager'])
        ->get('/integrations/quickbooks/callback', [QuickBooksConnectionController::class, 'callback'])
        ->name('integrations.quickbooks.callback');

    Route::middleware(['role:admin', 'tenant.access'])
        ->get('/internal/onboarding/harness', [OnboardingHarnessController::class, 'show'])
        ->name('onboarding.harness');

    // Canonical onboarding wizard UI (authenticated, tenant-aware). Uses the onboarding API seams; no redirects.
    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access'])
        ->get('/onboarding', \App\Livewire\Onboarding\Wizard::class)
        ->name('onboarding.wizard');

    // Canonical non-embedded Start Here (authenticated, tenant-aware). Reuses TenantCommercialExperienceService journey payload.
    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access'])
        ->get('/start', [CustomerStartHereController::class, 'show'])
        ->name('app.start');
    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access'])
        ->post('/start/setup-status', [CustomerStartHereController::class, 'updateSetupStatus'])
        ->name('app.setup-status.update');

    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access'])
        ->prefix('client/projects')
        ->name('client.projects.')
        ->group(function (): void {
            Route::get('/', [ClientProjectController::class, 'index'])->name('index');
            Route::get('/requests', [ClientProjectTicketController::class, 'index'])->name('requests.index');
            Route::get('/requests/{ticket}', [ClientProjectTicketController::class, 'show'])->name('requests.show');
            Route::get('/{project}/requests/create', [ClientProjectTicketController::class, 'create'])->name('requests.create');
            Route::post('/{project}/requests', [ClientProjectTicketController::class, 'store'])->name('requests.store');
            Route::get('/{project}', [ClientProjectController::class, 'show'])->name('show');
        });

    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access', 'module:field_service'])
        ->prefix('field-service')
        ->name('field-service.')
        ->group(function (): void {
            Route::get('/', [FieldServiceController::class, 'index'])->name('index');
            Route::get('/calendar', [FieldServiceController::class, 'calendar'])->name('calendar');
            Route::post('/jobs', [FieldServiceController::class, 'storeJob'])->name('jobs.store');
            Route::get('/jobs/{job}', [FieldServiceController::class, 'showJob'])->name('jobs.show');
            Route::post('/jobs/{job}/notes', [FieldServiceController::class, 'storeNote'])->name('notes.store');
            Route::post('/jobs/{job}/tasks', [FieldServiceController::class, 'storeTask'])->name('tasks.store');
            Route::post('/jobs/{job}/photos', [FieldServiceController::class, 'storePhoto'])->name('photos.store');
            Route::post('/materials', [FieldServiceController::class, 'storeMaterial'])->name('materials.store');
            Route::post('/reminders', [FieldServiceController::class, 'updateReminderSettings'])->name('reminders.update');
            Route::post('/vehicles', [FieldServiceController::class, 'storeVehicle'])->name('vehicles.store');
        });

    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access'])
        ->prefix('custom-module-requests')
        ->name('custom-module-requests.')
        ->group(function (): void {
            Route::get('/', [CustomModuleRequestController::class, 'index'])->name('index');
            Route::get('/create', [CustomModuleRequestController::class, 'create'])->name('create');
            Route::post('/', [CustomModuleRequestController::class, 'store'])->name('store');
            Route::get('/{customModuleRequest}', [CustomModuleRequestController::class, 'show'])->name('show');
        });

    // Guarded hosted billing handoff (Stripe hosted checkout / billing portal). Read-only on our side; no plan mutation.
    Route::middleware(['role:admin,manager,marketing_manager', 'tenant.access'])
        ->prefix('billing')
        ->name('billing.')
        ->group(function (): void {
            Route::post('/checkout', [\App\Http\Controllers\Billing\HostedBillingController::class, 'checkout'])
                ->name('checkout');
            Route::post('/portal', [\App\Http\Controllers\Billing\HostedBillingController::class, 'portal'])
                ->name('portal');
            Route::post('/messaging-credit', [\App\Http\Controllers\Billing\MessagingCreditController::class, 'checkout'])
                ->name('messaging-credit.checkout');
        });

    /*
    |--------------------------------------------------------------------------
    | Onboarding Wizard Contracts (Stage 1A seam)
    |--------------------------------------------------------------------------
    |
    | UI-agnostic authenticated endpoints for wizard clients.
    |
    */
    Route::prefix('api/onboarding')
        ->name('onboarding.api.')
        ->middleware(['role:admin,manager,marketing_manager', 'tenant.access'])
        ->group(function (): void {
            Route::get('/wizard-contract', [OnboardingWizardApiController::class, 'contract'])
                ->name('contract');
            Route::post('/blueprint-draft', [OnboardingWizardApiController::class, 'autosaveDraft'])
                ->name('draft.autosave');
            Route::post('/blueprint-finalize', [OnboardingWizardApiController::class, 'finalizeBlueprint'])
                ->name('blueprint.finalize');

            // Internal seam for validating blueprint->fresh-tenant provisioning (no UI).
            Route::post('/blueprint-provision-production-tenant', [OnboardingProvisioningApiController::class, 'provisionProductionTenant'])
                ->middleware(['role:admin'])
                ->name('blueprint.provision-production');

            Route::get('/blueprint-provisioning-status', [OnboardingProvisioningApiController::class, 'provisioningStatus'])
                ->middleware(['role:admin'])
                ->name('blueprint.provisioning-status');

            Route::get('/blueprint-provisioning-handoff', [OnboardingProvisioningApiController::class, 'provisioningHandoff'])
                ->middleware(['role:admin'])
                ->name('blueprint.provisioning-handoff');

            Route::get('/blueprint-provisioning-handoff-payload', [OnboardingProvisioningApiController::class, 'provisioningHandoffPayload'])
                ->middleware(['role:admin'])
                ->name('blueprint.provisioning-handoff-payload');

            Route::get('/blueprint-provisioning-open-context', [OnboardingProvisioningApiController::class, 'provisioningOpenContext'])
                ->middleware(['role:admin'])
                ->name('blueprint.provisioning-open-context');

            Route::get('/blueprint-post-provisioning-summary', [OnboardingProvisioningApiController::class, 'postProvisioningSummary'])
                ->middleware(['role:admin'])
                ->name('blueprint.post-provisioning-summary');

            Route::post('/acknowledge-first-open', [OnboardingProvisioningApiController::class, 'acknowledgeFirstOpen'])
                ->middleware(['role:admin'])
                ->name('acknowledge-first-open');
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
        Route::get('/admin/wholesale/applications', [WholesaleApplicationInboxController::class, 'index'])->name('admin.wholesale.applications');
        Route::get('/admin/wholesale/applications/{accessRequest}', [WholesaleApplicationInboxController::class, 'show'])->name('admin.wholesale.applications.show');
        Route::post('/admin/wholesale/applications/{accessRequest}/approve', [WholesaleApplicationInboxController::class, 'approve'])->name('admin.wholesale.applications.approve');
        Route::post('/admin/wholesale/applications/{accessRequest}/reject', [WholesaleApplicationInboxController::class, 'reject'])->name('admin.wholesale.applications.reject');
        Route::post('/admin/wholesale/applications/{accessRequest}/resend-activation', [WholesaleApplicationInboxController::class, 'resendActivation'])->name('admin.wholesale.applications.resend-activation');
        Route::get('/admin/catalog/scents', AdminScentsCrud::class)->name('admin.catalog.scents');
        Route::get('/admin/catalog/costs', AdminCostsCrud::class)->name('admin.catalog.costs');
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
    Route::middleware(['role:admin,marketing_manager,manager'])
        ->prefix('marketing')
        ->name('marketing.')
        ->group(function () {
            Route::middleware(['tenant.access'])->group(function (): void {
                Route::get('/results', [MarketingResultsController::class, 'show'])
                    ->middleware('module:reporting')
                    ->name('results');
                Route::get('/customers', [MarketingCustomersController::class, 'index'])->name('customers');
                Route::get('/customers/data', [MarketingCustomersController::class, 'data'])->name('customers.data');
                Route::get('/customers/create', [MarketingCustomersController::class, 'create'])->name('customers.create');
                Route::post('/customers/create', [MarketingCustomersController::class, 'storeCreate'])->name('customers.store-create');
                Route::get('/customers/{marketingProfile}', [MarketingCustomersController::class, 'show'])->name('customers.show');
                Route::get('/customers/{marketingProfile}/email-deliveries/export', [MarketingCustomersController::class, 'exportEmailDeliveries'])
                    ->name('customers.email-deliveries.export');
                Route::patch('/customers/{marketingProfile}', [MarketingCustomersController::class, 'update'])->name('customers.update');
                Route::post('/customers/{marketingProfile}/birthday', [MarketingCustomersController::class, 'updateBirthday'])->name('customers.update-birthday');
                Route::post('/customers/{marketingProfile}/consent', [MarketingCustomersController::class, 'updateConsent'])->name('customers.update-consent');
                Route::post('/customers/{marketingProfile}/candle-cash/grant', [MarketingCustomersController::class, 'grantCandleCash'])->name('customers.candle-cash.grant');
                Route::post('/customers/{marketingProfile}/candle-cash/redeem', [MarketingCustomersController::class, 'redeemCandleCash'])->name('customers.candle-cash.redeem');
                Route::post('/customers/{marketingProfile}/candle-cash/redemptions/{redemption}/mark-redeemed', [MarketingCustomersController::class, 'markCandleCashRedemptionRedeemed'])
                    ->name('customers.candle-cash.redemptions.mark-redeemed');
                Route::post('/customers/{marketingProfile}/candle-cash/redemptions/{redemption}/cancel', [MarketingCustomersController::class, 'cancelCandleCashRedemption'])
                    ->name('customers.candle-cash.redemptions.cancel');
            });
            Route::middleware(['role:admin,marketing_manager'])->group(function (): void {
                Route::get('/', [MarketingPagesController::class, 'show'])
                    ->defaults('section', 'overview')
                    ->name('overview');
                Route::get('/messages', [MarketingPagesController::class, 'show'])
                    ->defaults('section', 'messages')
                    ->name('messages');
                Route::get('/messages/send', [MarketingMessagesController::class, 'send'])->name('messages.send');
                Route::get('/messages/deliveries', [MarketingMessagesController::class, 'deliveries'])->name('messages.deliveries');
                Route::get('/messages/search-customers', [MarketingMessagesController::class, 'searchCustomers'])->name('messages.search-customers');
                Route::post('/messages/save-audience', [MarketingMessagesController::class, 'saveAudience'])->name('messages.save-audience');
                Route::post('/messages/save-message', [MarketingMessagesController::class, 'saveMessage'])->name('messages.save-message');
                Route::post('/messages/set-step', [MarketingMessagesController::class, 'setStep'])->name('messages.set-step');
                Route::post('/messages/send-test', [MarketingMessagesController::class, 'sendTest'])->name('messages.send-test');
                Route::post('/messages/execute', [MarketingMessagesController::class, 'executeSend'])->name('messages.execute');
                Route::post('/messages/reset', [MarketingMessagesController::class, 'resetWizard'])->name('messages.reset');
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
                Route::post('/campaigns/{campaign}/issue-subscriber-reward', [MarketingCampaignsController::class, 'issueSubscriberReward'])->name('campaigns.issue-subscriber-reward');
                Route::post('/campaigns/{campaign}/send-selected-sms', [MarketingCampaignsController::class, 'sendSelectedSms'])->name('campaigns.send-selected-sms');
                Route::post('/campaigns/{campaign}/recipients/{recipient}/retry-sms', [MarketingCampaignsController::class, 'retryRecipientSms'])->name('campaigns.recipients.retry-sms');
                Route::post('/campaigns/{campaign}/send-approved-email', [MarketingCampaignsController::class, 'sendApprovedEmail'])->name('campaigns.send-approved-email');
                Route::post('/campaigns/{campaign}/send-selected-email', [MarketingCampaignsController::class, 'sendSelectedEmail'])->name('campaigns.send-selected-email');
                Route::post('/campaigns/{campaign}/recipients/{recipient}/retry-email', [MarketingCampaignsController::class, 'retryRecipientEmail'])->name('campaigns.recipients.retry-email');
                Route::post('/campaigns/{campaign}/send-smoke-test-email', [MarketingCampaignsController::class, 'sendSmokeTestEmail'])->name('campaigns.send-smoke-test-email');
                Route::post('/campaigns/{campaign}/recommendations/generate', [MarketingCampaignsController::class, 'generateRecommendations'])->name('campaigns.recommendations.generate');
                Route::post('/campaigns/{campaign}/add-profile', [MarketingCampaignsController::class, 'addProfileRecipient'])->name('campaigns.add-profile');

                Route::get('/automations', [MarketingPagesController::class, 'show'])
                    ->defaults('section', 'automations')
                    ->name('automations');
                Route::post('/automations/{workflow}/prepare', [MarketingPagesController::class, 'prepareAutomationWorkflow'])
                    ->name('automations.prepare');
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
                        Route::get('/redeem', [CandleCashPagesController::class, 'redeem'])->name('redeem');
                        Route::patch('/redeem/{reward}', [CandleCashPagesController::class, 'updateReward'])->name('redeem.update');
                        Route::get('/queue', [CandleCashPagesController::class, 'queue'])->name('queue');
                        Route::post('/queue/{completion}/approve', [CandleCashPagesController::class, 'approveCompletion'])->name('queue.approve');
                        Route::post('/queue/{completion}/reject', [CandleCashPagesController::class, 'rejectCompletion'])->name('queue.reject');
                        Route::get('/reviews', [CandleCashPagesController::class, 'reviews'])->name('reviews');
                        Route::post('/reviews/{review}/approve', [CandleCashPagesController::class, 'approveReview'])->name('reviews.approve');
                        Route::post('/reviews/{review}/reject', [CandleCashPagesController::class, 'rejectReview'])->name('reviews.reject');
                        Route::post('/reviews/{review}/response', [CandleCashPagesController::class, 'respondToReview'])->name('reviews.response');
                        Route::post('/reviews/{review}/update', [CandleCashPagesController::class, 'updateReview'])->name('reviews.update');
                        Route::post('/reviews/{review}/delete', [CandleCashPagesController::class, 'deleteReview'])->name('reviews.delete');
                        Route::post('/reviews/{review}/resend-notification', [CandleCashPagesController::class, 'resendReviewNotification'])->name('reviews.resend-notification');
                        Route::get('/customers', [CandleCashPagesController::class, 'customers'])->name('customers');
                        Route::post('/customers/{marketingProfile}/adjust', [CandleCashPagesController::class, 'adjustCustomer'])->name('customers.adjust');
                        Route::get('/gifts-report', [CandleCashPagesController::class, 'giftsReport'])->name('gifts-report');
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
                Route::middleware(['tenant.access'])->group(function (): void {
                    Route::get('/operations/reconciliation', [MarketingOperationsController::class, 'reconciliation'])
                        ->name('operations.reconciliation');
                    Route::post('/operations/reconciliation/issues/{event}/resolve', [MarketingOperationsController::class, 'resolveIssue'])
                        ->name('operations.reconciliation.issues.resolve');
                    Route::post('/operations/reconciliation/retry', [MarketingOperationsController::class, 'retryReconciliation'])
                        ->name('operations.reconciliation.retry');
                    Route::post('/operations/reconciliation/redemptions/{redemption}/mark-redeemed', [MarketingOperationsController::class, 'markRedemptionRedeemed'])
                        ->name('operations.reconciliation.redemptions.mark-redeemed');
                    Route::get('/operations/storefront/redemption-debug', [MarketingOperationsController::class, 'storefrontRedemptionDebug'])
                        ->name('operations.storefront-redemption-debug');
                });
                Route::get('/reviews', [MarketingPagesController::class, 'show'])
                    ->defaults('section', 'reviews')
                    ->name('reviews');
                Route::middleware(['tenant.access'])->group(function (): void {
                    Route::get('/modules', [MarketingModuleStoreController::class, 'index'])->name('modules');
                    Route::post('/modules/{moduleKey}/activate', [MarketingModuleStoreController::class, 'activate'])->name('modules.activate');
                    Route::post('/modules/{moduleKey}/request', [MarketingModuleStoreController::class, 'requestAccess'])->name('modules.request');
                });
                Route::get('/wishlist', [MarketingWishlistController::class, 'index'])->name('wishlist');
                Route::post('/wishlist/items/{item}/prepare-outreach', [MarketingWishlistController::class, 'prepareOutreach'])->name('wishlist.prepare-outreach');
                Route::post('/wishlist/queue/{queue}/send', [MarketingWishlistController::class, 'sendOutreach'])->name('wishlist.send-outreach');
                Route::get('/settings', [MarketingPagesController::class, 'show'])
                    ->defaults('section', 'settings')
                    ->name('settings');
                Route::post('/settings', [MarketingPagesController::class, 'saveSettings'])
                    ->name('settings.save');
                Route::middleware(['tenant.access'])->group(function (): void {
                    Route::get('/providers-integrations', [MarketingProvidersIntegrationsController::class, 'index'])
                        ->name('providers-integrations');
                    Route::get('/providers-integrations/shopify-customer-sync-health', [MarketingProvidersIntegrationsController::class, 'shopifyCustomerSyncHealth'])
                        ->name('providers-integrations.shopify-customer-sync-health');
                    Route::post('/providers-integrations/workflow-automations', [MarketingProvidersIntegrationsController::class, 'saveWorkflowAutomation'])
                        ->name('providers-integrations.workflow-automations.save');
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
                });
                Route::get('/suppression-consent', [MarketingPagesController::class, 'show'])
                    ->defaults('section', 'suppression-consent')
                    ->name('suppression-consent');
            });
        });

    Route::get('/marketing/candle-cash/google-business/callback', [GoogleBusinessProfileController::class, 'callback'])
        ->name('marketing.candle-cash.google-business.callback');
    Route::get('/marketing/providers-integrations/workflow-automations/asana/callback', [MarketingProvidersIntegrationsController::class, 'workflowAsanaCallback'])
        ->name('marketing.providers-integrations.workflow-automations.asana.callback');
    Route::get('/marketing/providers-integrations/workflow-automations/google-calendar/callback', [MarketingProvidersIntegrationsController::class, 'workflowGoogleCalendarCallback'])
        ->name('marketing.providers-integrations.workflow-automations.google-calendar.callback');

    // Keep accepting the older callback paths already registered in Google Cloud
    // so production OAuth can recover without waiting on a console-side change.
    Route::get('/apps/forestry/google/oauth', [GoogleBusinessProfileController::class, 'callback'])
        ->name('marketing.candle-cash.google-business.callback.legacy');
    Route::get('/apps/forestry/google/oauth,', [GoogleBusinessProfileController::class, 'callback'])
        ->name('marketing.candle-cash.google-business.callback.legacy-comma');
    Route::get('/apps/forestry/google/oauth/callback', [GoogleBusinessProfileController::class, 'callback'])
        ->name('marketing.candle-cash.google-business.callback.legacy-callback');

    Route::middleware(['role:admin,marketing_manager'])
        ->prefix('birthdays')
        ->name('birthdays.')
        ->group(function () {
            Route::get('/', [BirthdayPagesController::class, 'customers'])->name('customers');
            Route::post('/customers/{marketingProfile}/issue-reward', [BirthdayPagesController::class, 'issueReward'])->name('customers.issue-reward');

            Route::get('/analytics', [BirthdayPagesController::class, 'analytics'])->name('analytics');
            Route::get('/campaigns', [BirthdayPagesController::class, 'campaigns'])->name('campaigns');
            Route::get('/rewards', [BirthdayPagesController::class, 'rewards'])->name('rewards');
            Route::post('/rewards/{issuance}/activate', [BirthdayPagesController::class, 'activateReward'])->name('rewards.activate');
            Route::post('/rewards/{issuance}/status', [BirthdayPagesController::class, 'updateRewardStatus'])->name('rewards.status');
            Route::get('/settings', [BirthdayPagesController::class, 'settings'])->name('settings');
            Route::post('/settings', [BirthdayPagesController::class, 'saveSettings'])->name('settings.save');

            Route::middleware(['tenant.access'])->group(function (): void {
                Route::post('/customers/import/preview', [BirthdayPagesController::class, 'previewImport'])->name('customers.import.preview');
                Route::post('/customers/import', [BirthdayPagesController::class, 'runImport'])->name('customers.import.run');
                Route::get('/activity', [BirthdayPagesController::class, 'activity'])->name('activity');
            });
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
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.orders.create');
    Route::post('/orders/updated', [ShopifyWebhookController::class, 'ordersUpdated'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.orders.updated');
    Route::post('/orders/cancelled', [ShopifyWebhookController::class, 'ordersCancelled'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.orders.cancelled');
    Route::post('/refunds/create', [ShopifyWebhookController::class, 'refundsCreate'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.refunds.create');
    Route::post('/customers/create', [ShopifyWebhookController::class, 'customersCreate'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.customers.create');
    Route::post('/customers/update', [ShopifyWebhookController::class, 'customersUpdated'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.customers.update');
    Route::post('/subscription-contracts/create', [ShopifyWebhookController::class, 'subscriptionContractsCreate'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.subscription-contracts.create');
    Route::post('/subscription-contracts/update', [ShopifyWebhookController::class, 'subscriptionContractsUpdate'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.subscription-contracts.update');
    Route::post('/subscription-billing-attempts/success', [ShopifyWebhookController::class, 'subscriptionBillingAttemptsSuccess'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.subscription-billing-attempts.success');
    Route::post('/subscription-billing-attempts/failure', [ShopifyWebhookController::class, 'subscriptionBillingAttemptsFailure'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.subscription-billing-attempts.failure');
    Route::post('/customer-payment-methods/create', [ShopifyWebhookController::class, 'customerPaymentMethodsCreate'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.customer-payment-methods.create');
    Route::post('/customer-payment-methods/update', [ShopifyWebhookController::class, 'customerPaymentMethodsUpdate'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.customer-payment-methods.update');
    Route::post('/customer-payment-methods/revoke', [ShopifyWebhookController::class, 'customerPaymentMethodsRevoke'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.customer-payment-methods.revoke');
    Route::post('/customers/data-request', [ShopifyPrivacyWebhookController::class, 'customersDataRequest'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.customers.data-request');
    Route::post('/customers/redact', [ShopifyPrivacyWebhookController::class, 'customersRedact'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.customers.redact');
    Route::post('/shop/redact', [ShopifyPrivacyWebhookController::class, 'shopRedact'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('shopify.webhooks.shop.redact');
});

Route::get('/candle-club/vote/{poll}/{token}', [SubscriptionPublicController::class, 'showPoll'])
    ->middleware('throttle:120,1')
    ->name('subscriptions.public.poll');
Route::post('/candle-club/vote/{poll}/{token}/code', [SubscriptionPublicController::class, 'requestVoteCode'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:20,1')
    ->name('subscriptions.public.poll.code');
Route::post('/candle-club/vote/{poll}/{token}/vote', [SubscriptionPublicController::class, 'castVote'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:20,1')
    ->name('subscriptions.public.poll.vote');

Route::prefix('webhooks/twilio')->group(function () {
    Route::post('/status', [TwilioWebhookController::class, 'status'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('marketing.webhooks.twilio-status');
    Route::post('/inbound', [TwilioWebhookController::class, 'inbound'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('marketing.webhooks.twilio-inbound');
});

Route::prefix('webhooks/sendgrid')->group(function () {
    Route::post('/events', [SendGridWebhookController::class, 'events'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('marketing.webhooks.sendgrid-events');
    Route::post('/inbound', [SendGridInboundWebhookController::class, 'handle'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('marketing.webhooks.sendgrid-inbound');
});

Route::post('/webhooks/ses/events', [SesWebhookController::class, 'events'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('marketing.webhooks.ses-events');

Route::prefix('webhooks/stripe')->group(function () {
    Route::post('/events', [\App\Http\Controllers\Billing\StripeWebhookController::class, 'events'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->middleware('throttle:60,1')
        ->name('billing.webhooks.stripe-events');
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
Route::get('/share/scent-personality/{token}/image.png', [MarketingPublicEventController::class, 'showScentPersonalityShareImage'])
    ->where('token', '[A-Za-z0-9]{20,80}')
    ->middleware('throttle:60,1')
    ->name('marketing.public.scent-personality-share.image');
Route::get('/share/scent-personality/{token}', [MarketingPublicEventController::class, 'showScentPersonalityShare'])
    ->where('token', '[A-Za-z0-9]{20,80}')
    ->middleware('throttle:60,1')
    ->name('marketing.public.scent-personality-share');
Route::get('/share/scent-personality/{token}/quiz', [MarketingPublicEventController::class, 'showScentPersonalityShareQuiz'])
    ->where('token', '[A-Za-z0-9]{20,80}')
    ->middleware('throttle:60,1')
    ->name('marketing.public.scent-personality-share.quiz');
Route::post('/share/scent-personality/{token}/quiz', [MarketingPublicEventController::class, 'submitScentPersonalityShareQuiz'])
    ->where('token', '[A-Za-z0-9]{20,80}')
    ->middleware('throttle:60,1')
    ->name('marketing.public.scent-personality-share.submit');
Route::post('/share/scent-personality/{token}/events', [MarketingPublicEventController::class, 'storeScentPersonalityShareEvent'])
    ->where('token', '[A-Za-z0-9]{20,80}')
    ->middleware('throttle:120,1')
    ->name('marketing.public.scent-personality-share.event');
Route::get('/share/scent-personality/{token}/products/{handle}', [MarketingPublicEventController::class, 'redirectScentPersonalityShareProduct'])
    ->where('token', '[A-Za-z0-9]{20,80}')
    ->where('handle', '[A-Za-z0-9\-_]+')
    ->middleware('throttle:120,1')
    ->name('marketing.public.scent-personality-share.product');
Route::get('/share/scent-personality/{token}/products/{handle}/add-to-cart', [MarketingPublicEventController::class, 'addScentPersonalityShareProductToCart'])
    ->where('token', '[A-Za-z0-9]{20,80}')
    ->where('handle', '[A-Za-z0-9\-_]+')
    ->middleware('throttle:120,1')
    ->name('marketing.public.scent-personality-share.add-to-cart');
Route::get('/share/product/{handle}', [MarketingPublicEventController::class, 'showProductShare'])
    ->where('handle', '[A-Za-z0-9\-_]+')
    ->middleware('throttle:60,1')
    ->name('marketing.public.product-share');

Route::prefix('shopify/marketing')
    ->name('marketing.shopify.')
    ->middleware(['marketing.storefront.verify', 'throttle:120,1'])
    ->group(function () {
        Route::get('/account', [MarketingPublicEventController::class, 'customerDashboard'])->name('account');
        Route::post('/message', [MarketingPublicEventController::class, 'sendCustomerMessage'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('message');
        Route::post('/scent-quiz/results', [MarketingPublicEventController::class, 'saveCustomerScentQuizResult'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('scent-quiz.submit');
        Route::post('/social-share/started', [MarketingPublicEventController::class, 'socialShareStarted'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('social-share.started');
        Route::post('/social-share/claim', [MarketingPublicEventController::class, 'socialShareClaim'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('social-share.claim');
        Route::get('/rewards/balance', [MarketingShopifyIntegrationController::class, 'rewardBalance'])->name('rewards.balance');
        Route::get('/rewards/available', [MarketingShopifyIntegrationController::class, 'availableRewards'])->name('rewards.available');
        Route::get('/rewards/history', [MarketingShopifyIntegrationController::class, 'rewardHistory'])->name('rewards.history');
        Route::get('/customer/status', [MarketingShopifyIntegrationController::class, 'customerStatus'])->name('customer.status');
        Route::get('/health', [MarketingShopifyIntegrationController::class, 'proxyHealth'])->name('health');
        Route::get('/product-options', [ShopifyProductOptionsController::class, 'storefront'])->name('product-options');
        Route::get('/feedback', [ModernForestryAppFeedbackController::class, 'index'])->name('feedback');
        Route::post('/feedback', [ModernForestryAppFeedbackController::class, 'store'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->middleware('throttle:20,1')
            ->name('feedback.store');
        Route::get('/feedback/{ticket}', [ModernForestryAppFeedbackController::class, 'show'])->name('feedback.show');
        Route::post('/feedback/{ticket}/comments', [ModernForestryAppFeedbackController::class, 'comment'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->middleware('throttle:20,1')
            ->name('feedback.comments.store');
        Route::post('/feedback/{ticket}/vote', [ModernForestryAppFeedbackController::class, 'vote'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->middleware('throttle:40,1')
            ->name('feedback.vote');
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
        Route::post('/wholesale/application', [PlatformAccessRequestController::class, 'storeForWholesaleStorefront'])
            ->withoutMiddleware(['marketing.storefront.verify', VerifyCsrfToken::class])
            ->name('wholesale.application');
        Route::get('/birthday/status', [MarketingShopifyIntegrationController::class, 'birthdayStatus'])->name('birthday.status');
        Route::post('/birthday/capture', [MarketingShopifyIntegrationController::class, 'captureBirthday'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('birthday.capture');
        Route::post('/birthday/claim', [MarketingShopifyIntegrationController::class, 'claimBirthdayReward'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('birthday.claim');
        Route::get('/candle-cash/status', [MarketingShopifyIntegrationController::class, 'candleCashStatus'])->name('candle-cash.status');
        Route::get('/candle-club/poll', [SubscriptionStorefrontController::class, 'poll'])->name('candle-club.poll');
        Route::post('/candle-club/vote/code', [SubscriptionStorefrontController::class, 'requestVoteCode'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('candle-club.vote.code');
        Route::post('/candle-club/vote', [SubscriptionStorefrontController::class, 'castVote'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('candle-club.vote');
        Route::post('/candle-cash/tasks/submit', [MarketingShopifyIntegrationController::class, 'submitCandleCashTask'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('candle-cash.tasks.submit');
        Route::get('/wishlist/status', [MarketingShopifyIntegrationController::class, 'wishlistStatus'])->name('wishlist.status');
        Route::post('/wishlist/add', [MarketingShopifyIntegrationController::class, 'addWishlistItem'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('wishlist.add');
        Route::post('/wishlist/lists/create', [MarketingShopifyIntegrationController::class, 'createWishlistList'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('wishlist.lists.create');
        Route::post('/wishlist/remove', [MarketingShopifyIntegrationController::class, 'removeWishlistItem'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('wishlist.remove');
        Route::post('/funnel/event', [MarketingShopifyIntegrationController::class, 'logFunnelEvent'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('funnel.event');
        Route::get('/product-reviews/status', [MarketingShopifyIntegrationController::class, 'productReviewStatus'])->name('product-reviews.status');
        Route::get('/product-reviews/sitewide', [MarketingShopifyIntegrationController::class, 'sitewideReviewStatus'])->name('product-reviews.sitewide');
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
        Route::get('/account', [MarketingPublicEventController::class, 'customerDashboard'])->name('account');
        Route::post('/message', [MarketingPublicEventController::class, 'sendCustomerMessage'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('message');
        Route::post('/scent-quiz/results', [MarketingPublicEventController::class, 'saveCustomerScentQuizResult'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('scent-quiz.submit');
        Route::post('/social-share/started', [MarketingPublicEventController::class, 'socialShareStarted'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('social-share.started');
        Route::post('/social-share/claim', [MarketingPublicEventController::class, 'socialShareClaim'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('social-share.claim');
        Route::get('/rewards/balance', [MarketingShopifyIntegrationController::class, 'rewardBalance'])->name('rewards.balance');
        Route::get('/rewards/available', [MarketingShopifyIntegrationController::class, 'availableRewards'])->name('rewards.available');
        Route::get('/rewards/history', [MarketingShopifyIntegrationController::class, 'rewardHistory'])->name('rewards.history');
        Route::get('/customer/status', [MarketingShopifyIntegrationController::class, 'customerStatus'])->name('customer.status');
        Route::get('/health', [MarketingShopifyIntegrationController::class, 'proxyHealth'])->name('health');
        Route::get('/product-options', [ShopifyProductOptionsController::class, 'storefront'])->name('product-options');
        Route::get('/feedback', [ModernForestryAppFeedbackController::class, 'index'])->name('feedback');
        Route::post('/feedback', [ModernForestryAppFeedbackController::class, 'store'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->middleware('throttle:20,1')
            ->name('feedback.store');
        Route::get('/feedback/{ticket}', [ModernForestryAppFeedbackController::class, 'show'])->name('feedback.show');
        Route::post('/feedback/{ticket}/comments', [ModernForestryAppFeedbackController::class, 'comment'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->middleware('throttle:20,1')
            ->name('feedback.comments.store');
        Route::post('/feedback/{ticket}/vote', [ModernForestryAppFeedbackController::class, 'vote'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->middleware('throttle:40,1')
            ->name('feedback.vote');
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
        Route::post('/wholesale/application', [PlatformAccessRequestController::class, 'storeForWholesaleStorefront'])
            ->withoutMiddleware(['marketing.storefront.verify', VerifyCsrfToken::class])
            ->name('wholesale.application');
        Route::get('/birthday/status', [MarketingShopifyIntegrationController::class, 'birthdayStatus'])->name('birthday.status');
        Route::post('/birthday/capture', [MarketingShopifyIntegrationController::class, 'captureBirthday'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('birthday.capture');
        Route::post('/birthday/claim', [MarketingShopifyIntegrationController::class, 'claimBirthdayReward'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('birthday.claim');
        Route::get('/candle-cash/status', [MarketingShopifyIntegrationController::class, 'candleCashStatus'])->name('candle-cash.status');
        Route::get('/candle-club/poll', [SubscriptionStorefrontController::class, 'poll'])->name('candle-club.poll');
        Route::post('/candle-club/vote/code', [SubscriptionStorefrontController::class, 'requestVoteCode'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('candle-club.vote.code');
        Route::post('/candle-club/vote', [SubscriptionStorefrontController::class, 'castVote'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('candle-club.vote');
        Route::post('/candle-cash/tasks/submit', [MarketingShopifyIntegrationController::class, 'submitCandleCashTask'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('candle-cash.tasks.submit');
        Route::get('/wishlist/status', [MarketingShopifyIntegrationController::class, 'wishlistStatus'])->name('wishlist.status');
        Route::post('/wishlist/add', [MarketingShopifyIntegrationController::class, 'addWishlistItem'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('wishlist.add');
        Route::post('/wishlist/lists/create', [MarketingShopifyIntegrationController::class, 'createWishlistList'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('wishlist.lists.create');
        Route::post('/wishlist/remove', [MarketingShopifyIntegrationController::class, 'removeWishlistItem'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('wishlist.remove');
        Route::post('/funnel/event', [MarketingShopifyIntegrationController::class, 'logFunnelEvent'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('funnel.event');
        Route::get('/product-reviews/status', [MarketingShopifyIntegrationController::class, 'productReviewStatus'])->name('product-reviews.status');
        Route::get('/product-reviews/sitewide', [MarketingShopifyIntegrationController::class, 'sitewideReviewStatus'])->name('product-reviews.sitewide');
        Route::post('/product-reviews/submit', [MarketingShopifyIntegrationController::class, 'submitProductReview'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('product-reviews.submit');
        Route::post('/google-business/review/start', [MarketingShopifyIntegrationController::class, 'startGoogleBusinessReview'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('google-business.review.start');
    });

Route::prefix('shopify')->middleware('web')->group(function () {
    Route::get('/app', [ShopifyEmbeddedAppController::class, 'show'])->name('shopify.app');
    Route::get('/app/wholesale', [ShopifyEmbeddedAppController::class, 'showWholesale'])->name('shopify.app.wholesale');
    Route::get('/app/wholesale/applications/{accessRequest}', [ShopifyEmbeddedAppController::class, 'showWholesaleApplication'])
        ->name('shopify.app.wholesale.applications.show');
    Route::post('/app/wholesale/applications/{accessRequest}/approve', [ShopifyEmbeddedAppController::class, 'approveWholesaleApplication'])
        ->name('shopify.app.wholesale.applications.approve');
    Route::post('/app/wholesale/applications/{accessRequest}/reject', [ShopifyEmbeddedAppController::class, 'rejectWholesaleApplication'])
        ->name('shopify.app.wholesale.applications.reject');
    Route::post('/app/wholesale/applications/{accessRequest}/resend-activation', [ShopifyEmbeddedAppController::class, 'resendWholesaleApplicationActivation'])
        ->name('shopify.app.wholesale.applications.resend-activation');
    Route::get('/app/start', [ShopifyEmbeddedAppController::class, 'startHere'])->name('shopify.app.start');
    Route::get('/app/plans', [ShopifyEmbeddedAppController::class, 'plansAndAddons'])->name('shopify.app.plans');
    Route::get('/app/store', [ShopifyEmbeddedAppController::class, 'moduleStore'])->name('shopify.app.store');
    Route::post('/app/store/modules/{moduleKey}/activate', [ShopifyEmbeddedAppController::class, 'activateModule'])->name('shopify.app.store.activate');
    Route::post('/app/store/modules/{moduleKey}/request', [ShopifyEmbeddedAppController::class, 'requestModuleAccess'])->name('shopify.app.store.request');
    Route::get('/app/integrations', [ShopifyEmbeddedAppController::class, 'integrations'])->name('shopify.app.integrations');
    Route::get('/app/product-options', [ShopifyProductOptionsController::class, 'show'])->name('shopify.app.product-options');
    Route::get('/app/subscriptions', [ShopifyEmbeddedSubscriptionsController::class, 'show'])->name('shopify.app.subscriptions');
    Route::get('/app/rewards', [ShopifyEmbeddedRewardsController::class, 'index'])->name('shopify.app.rewards');
    Route::get('/app/rewards/earn', [ShopifyEmbeddedRewardsController::class, 'earn'])->name('shopify.app.rewards.earn');
    Route::get('/app/rewards/redeem', [ShopifyEmbeddedRewardsController::class, 'redeem'])->name('shopify.app.rewards.redeem');
    Route::get('/app/rewards/referrals', [ShopifyEmbeddedRewardsController::class, 'referrals'])->name('shopify.app.rewards.referrals');
    Route::get('/app/rewards/birthdays', [ShopifyEmbeddedRewardsController::class, 'birthdays'])->name('shopify.app.rewards.birthdays');
    Route::get('/app/rewards/vip', [ShopifyEmbeddedRewardsController::class, 'vip'])->name('shopify.app.rewards.vip');
    Route::get('/app/rewards/notifications', [ShopifyEmbeddedRewardsController::class, 'notifications'])->name('shopify.app.rewards.notifications');
    Route::get('/app/customers', [ShopifyEmbeddedCustomersController::class, 'manage'])->name('shopify.app.customers');
    Route::get('/app/customers/manage', [ShopifyEmbeddedCustomersController::class, 'manage'])->name('shopify.app.customers.manage');
    Route::get('/app/customers/segments', [ShopifyEmbeddedCustomersController::class, 'segments'])->name('shopify.app.customers.segments');
    Route::post('/app/customers/segments/scent-audiences/segment', [ShopifyEmbeddedCustomersController::class, 'createScentAudienceSegment'])
        ->name('shopify.app.customers.segments.scent-audiences.segment');
    Route::post('/app/customers/segments/scent-audiences/campaign', [ShopifyEmbeddedCustomersController::class, 'createScentAudienceCampaign'])
        ->name('shopify.app.customers.segments.scent-audiences.campaign');
    Route::get('/app/customers/activity', [ShopifyEmbeddedCustomersController::class, 'activity'])->name('shopify.app.customers.activity');
    Route::get('/app/customers/imports', [ShopifyEmbeddedCustomersController::class, 'imports'])->name('shopify.app.customers.imports');
    Route::get('/app/customers/questions', [ShopifyEmbeddedCustomersController::class, 'redirectLegacyToImports'])->name('shopify.app.customers.questions');
    Route::get('/app/customers/manage/{marketingProfile}', [ShopifyEmbeddedCustomersController::class, 'detail'])->name('shopify.app.customers.detail');
    Route::get('/app/assistant', [ShopifyEmbeddedAiAssistantController::class, 'start'])->name('shopify.app.assistant.start');
    Route::get('/app/assistant/opportunities', [ShopifyEmbeddedAiAssistantController::class, 'opportunities'])->name('shopify.app.assistant.opportunities');
    Route::get('/app/assistant/drafts', [ShopifyEmbeddedAiAssistantController::class, 'drafts'])->name('shopify.app.assistant.drafts');
    Route::post('/app/assistant/drafts/create', [ShopifyEmbeddedAiAssistantController::class, 'createDraftFromRecommendation'])->name('shopify.app.assistant.drafts.create');
    Route::post('/app/assistant/drafts/{campaign}/update', [ShopifyEmbeddedAiAssistantController::class, 'updateDraftCampaign'])->name('shopify.app.assistant.drafts.update');
    Route::get('/app/assistant/setup', [ShopifyEmbeddedAiAssistantController::class, 'setup'])->name('shopify.app.assistant.setup');
    Route::get('/app/assistant/activity', [ShopifyEmbeddedAiAssistantController::class, 'activity'])->name('shopify.app.assistant.activity');
    Route::get('/app/messaging', [ShopifyEmbeddedMessagingController::class, 'show'])->name('shopify.app.messaging');
    Route::get('/app/messaging/setup', [ShopifyEmbeddedMessagingController::class, 'setup'])->name('shopify.app.messaging.setup');
    Route::get('/app/messaging/analytics', [ShopifyEmbeddedMessagingController::class, 'analytics'])->name('shopify.app.messaging.analytics');
    Route::get('/app/reporting/marketing-results', [ShopifyEmbeddedMessagingController::class, 'marketingResults'])->name('shopify.app.reporting.marketing-results');
    Route::get('/app/messaging/responses', [ShopifyEmbeddedMessagingController::class, 'responses'])->name('shopify.app.messaging.responses');
    Route::get('/app/messaging/app-messages', [ShopifyEmbeddedMessagingController::class, 'appMessages'])->name('shopify.app.messaging.app-messages');
    Route::get('/app/development-notes', [ShopifyEmbeddedDevelopmentNotesController::class, 'show'])->name('shopify.app.development-notes');
    Route::get('/app/edit', [ShopifyEmbeddedSettingsController::class, 'editApp'])->name('shopify.app.edit');
    Route::get('/app/settings', [ShopifyEmbeddedSettingsController::class, 'show'])->name('shopify.app.settings');
    Route::prefix('app/api')->name('shopify.app.api.')->group(function () {
        Route::get('/dashboard', [ShopifyEmbeddedAppController::class, 'data'])->name('dashboard');
        Route::get('/dashboard-lite', [ShopifyEmbeddedAppController::class, 'liteData'])->name('dashboard-lite');
        Route::get('/search', [ShopifyEmbeddedAppController::class, 'search'])->name('search');
        Route::post('/product-options/rulesets', [ShopifyProductOptionsController::class, 'createRuleset'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('product-options.rulesets.create');
        Route::patch('/product-options/rulesets/{ruleset}', [ShopifyProductOptionsController::class, 'updateRuleset'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('product-options.rulesets.update');
        Route::delete('/product-options/rulesets/{ruleset}', [ShopifyProductOptionsController::class, 'deleteRuleset'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('product-options.rulesets.delete');
        Route::post('/dashboard/candle-cash-reminders', [ShopifyEmbeddedAppController::class, 'sendCandleCashEarnedReminders'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('dashboard.candle-cash-reminders');
        Route::patch('/subscriptions/candle-club/settings', [ShopifyEmbeddedSubscriptionsController::class, 'updateSettings'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('subscriptions.settings.update');
        Route::post('/subscriptions/migration/dry-run', [ShopifyEmbeddedSubscriptionsController::class, 'startMigrationDryRun'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('subscriptions.migration.dry-run');
        Route::post('/subscriptions/migration/approve-cutover', [ShopifyEmbeddedSubscriptionsController::class, 'approveCutover'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('subscriptions.migration.approve-cutover');
        Route::post('/subscriptions/contracts/{contract}/actions', [ShopifyEmbeddedSubscriptionsController::class, 'action'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('subscriptions.contracts.action');
        Route::post('/subscriptions/candle-club/feedback/{feedback}/export', [ShopifyEmbeddedSubscriptionsController::class, 'exportFeedback'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('subscriptions.candle-club.feedback.export');
        Route::get('/rewards', [ShopifyEmbeddedRewardsController::class, 'data'])->name('rewards');
        Route::get('/rewards/policy', [ShopifyEmbeddedRewardsController::class, 'policy'])
            ->name('rewards.policy');
        Route::patch('/rewards/policy', [ShopifyEmbeddedRewardsController::class, 'updatePolicy'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.policy.update');
        Route::post('/rewards/policy/review', [ShopifyEmbeddedRewardsController::class, 'reviewPolicy'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.policy.review');
        Route::post('/rewards/policy/defaults/alpha', [ShopifyEmbeddedRewardsController::class, 'applyAlphaDefaults'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.policy.defaults.alpha');
        Route::match(['GET', 'POST'], '/rewards/policy/reminders/explain', [ShopifyEmbeddedRewardsController::class, 'explainReminder'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.policy.reminders.explain');
        Route::get('/rewards/policy/reminders/customer-history', [ShopifyEmbeddedRewardsController::class, 'reminderCustomerHistory'])
            ->name('rewards.policy.reminders.customer-history');
        Route::post('/rewards/policy/reminders/requeue', [ShopifyEmbeddedRewardsController::class, 'requeueReminder'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.policy.reminders.requeue');
        Route::post('/rewards/policy/reminders/skip', [ShopifyEmbeddedRewardsController::class, 'skipReminder'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.policy.reminders.skip');
        Route::get('/rewards/policy/exports/{type}', [ShopifyEmbeddedRewardsController::class, 'exportRewardsData'])
            ->name('rewards.policy.exports');
        Route::get('/rewards/birthdays/analytics', [ShopifyEmbeddedRewardsController::class, 'birthdayAnalytics'])
            ->name('rewards.birthdays.analytics');
        Route::get('/rewards/birthdays/analytics/export', [ShopifyEmbeddedRewardsController::class, 'birthdayAnalyticsExport'])
            ->name('rewards.birthdays.analytics.export');
        Route::patch('/rewards/earn/{task}', [ShopifyEmbeddedRewardsController::class, 'updateEarnRule'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.earn.update');
        Route::patch('/rewards/redeem/{reward}', [ShopifyEmbeddedRewardsController::class, 'updateRedeemRule'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('rewards.redeem.update');
        Route::patch('/customers/manage/{marketingProfile}/identity', [ShopifyEmbeddedCustomersController::class, 'updateJson'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('customers.update');
        Route::get('/customers/manage/{marketingProfile}/sections', [ShopifyEmbeddedCustomersController::class, 'detailSectionsJson'])
            ->name('customers.detail-sections');
        Route::post('/customers/manage/{marketingProfile}/consent', [ShopifyEmbeddedCustomersController::class, 'updateConsentJson'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('customers.update-consent');
        Route::post('/customers/manage/{marketingProfile}/candle-cash', [ShopifyEmbeddedCustomersController::class, 'adjustCandleCashJson'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('customers.candle-cash.adjust');
        Route::post('/customers/manage/{marketingProfile}/message', [ShopifyEmbeddedCustomersController::class, 'sendMessageJson'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('customers.message');
        Route::post('/customers/manage/{marketingProfile}/candle-cash/send', [ShopifyEmbeddedCustomersController::class, 'sendCandleCashJson'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('customers.candle-cash.send');
        Route::get('/messaging/bootstrap', [ShopifyEmbeddedMessagingController::class, 'bootstrap'])
            ->name('messaging.bootstrap');
        Route::get('/messaging/audience-summary', [ShopifyEmbeddedMessagingController::class, 'audienceSummary'])
            ->name('messaging.audience.summary');
        Route::get('/messaging/customers/search', [ShopifyEmbeddedMessagingController::class, 'searchCustomers'])
            ->name('messaging.customers.search');
        Route::get('/messaging/products/search', [ShopifyEmbeddedMessagingController::class, 'searchProducts'])
            ->name('messaging.products.search');
        Route::get('/messaging/media', [ShopifyEmbeddedMessagingController::class, 'mediaIndex'])
            ->name('messaging.media.index');
        Route::post('/messaging/media', [ShopifyEmbeddedMessagingController::class, 'mediaStore'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.media.store');
        Route::get('/messaging/groups', [ShopifyEmbeddedMessagingController::class, 'groups'])
            ->name('messaging.groups');
        Route::get('/messaging/groups/{group}', [ShopifyEmbeddedMessagingController::class, 'groupDetail'])
            ->name('messaging.groups.detail');
        Route::post('/messaging/groups', [ShopifyEmbeddedMessagingController::class, 'createGroup'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.groups.create');
        Route::patch('/messaging/groups/{group}', [ShopifyEmbeddedMessagingController::class, 'updateGroup'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.groups.update');
        Route::post('/messaging/send/individual', [ShopifyEmbeddedMessagingController::class, 'sendIndividual'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.send.individual');
        Route::post('/messaging/preview/group', [ShopifyEmbeddedMessagingController::class, 'previewGroup'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.preview.group');
        Route::post('/messaging/send/group', [ShopifyEmbeddedMessagingController::class, 'sendGroup'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.send.group');
        Route::get('/messaging/responses', [ShopifyEmbeddedMessagingController::class, 'responsesIndex'])
            ->name('messaging.responses.index');
        Route::get('/messaging/responses/{conversation}', [ShopifyEmbeddedMessagingController::class, 'responsesShow'])
            ->name('messaging.responses.show');
        Route::post('/messaging/responses/{conversation}/actions', [ShopifyEmbeddedMessagingController::class, 'responsesUpdate'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.responses.update');
        Route::post('/messaging/responses/{conversation}/reply', [ShopifyEmbeddedMessagingController::class, 'responsesReply'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.responses.reply');
        Route::post('/messaging/campaigns/{campaign}/cancel', [ShopifyEmbeddedMessagingController::class, 'cancelCampaign'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.campaigns.cancel');
        Route::post('/messaging/smoke/sms', [ShopifyEmbeddedMessagingController::class, 'smokeSms'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.smoke.sms');
        Route::post('/messaging/smoke/email', [ShopifyEmbeddedMessagingController::class, 'smokeEmail'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.smoke.email');
        Route::post('/messaging/setup/complete', [ShopifyEmbeddedMessagingController::class, 'completeSetup'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.setup.complete');
        Route::post('/messaging/setup/support-alert', [ShopifyEmbeddedMessagingController::class, 'updateSupportAlertPhone'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.setup.support-alert.update');
        Route::post('/messaging/setup/sender-profile', [ShopifyEmbeddedMessagingController::class, 'saveSenderProfile'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.setup.sender-profile');
        Route::post('/messaging/setup/sender-test', [ShopifyEmbeddedMessagingController::class, 'testSenderProfile'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.setup.sender-test');
        Route::post('/messaging/setup/verification-refresh', [ShopifyEmbeddedMessagingController::class, 'refreshMessagingVerification'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.setup.verification-refresh');
        Route::get('/messaging/storefront-tracking/status', [ShopifyEmbeddedMessagingController::class, 'storefrontTrackingStatus'])
            ->name('messaging.storefront-tracking.status');
        Route::post('/messaging/storefront-tracking/connect-pixel', [ShopifyEmbeddedMessagingController::class, 'connectStorefrontPixel'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('messaging.storefront-tracking.connect-pixel');
        Route::get('/messaging/history', [ShopifyEmbeddedMessagingController::class, 'history'])
            ->name('messaging.history');
        Route::get('/settings/widgets', [ShopifyEmbeddedSettingsController::class, 'widgetSettings'])
            ->name('settings.widgets');
        Route::post('/settings/widgets', [ShopifyEmbeddedSettingsController::class, 'saveWidgetSettings'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('settings.widgets.save');
        Route::get('/development-notes/access', [ShopifyEmbeddedDevelopmentNotesController::class, 'access'])
            ->name('development-notes.access');
        Route::get('/development-notes/bootstrap', [ShopifyEmbeddedDevelopmentNotesController::class, 'bootstrap'])
            ->name('development-notes.bootstrap');
        Route::post('/development-notes/notes', [ShopifyEmbeddedDevelopmentNotesController::class, 'storeNote'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('development-notes.notes.store');
        Route::patch('/development-notes/notes/{note}', [ShopifyEmbeddedDevelopmentNotesController::class, 'updateNote'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('development-notes.notes.update');
        Route::delete('/development-notes/notes/{note}', [ShopifyEmbeddedDevelopmentNotesController::class, 'destroyNote'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('development-notes.notes.destroy');
        Route::post('/development-notes/change-logs', [ShopifyEmbeddedDevelopmentNotesController::class, 'storeChangeLog'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('development-notes.change-logs.store');
        Route::get('/settings/email', [ShopifyEmbeddedSettingsController::class, 'emailSettings'])
            ->name('settings.email');
        Route::post('/settings/email', [ShopifyEmbeddedSettingsController::class, 'saveEmailSettings'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('settings.email.save');
        Route::post('/settings/email/validate', [ShopifyEmbeddedSettingsController::class, 'validateEmailSettings'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('settings.email.validate');
        Route::post('/settings/email/test', [ShopifyEmbeddedSettingsController::class, 'sendTestEmail'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('settings.email.test');
        Route::post('/settings/email/health', [ShopifyEmbeddedSettingsController::class, 'emailProviderHealth'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('settings.email.health');
        Route::get('/settings/content', [ShopifyEmbeddedSettingsController::class, 'appContent'])
            ->name('settings.content');
        Route::post('/settings/content', [ShopifyEmbeddedSettingsController::class, 'saveAppContent'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('settings.content.save');
        Route::post('/settings/content/publish', [ShopifyEmbeddedSettingsController::class, 'publishAppContent'])
            ->withoutMiddleware([VerifyCsrfToken::class])
            ->name('settings.content.publish');
    });
    Route::get('/auth/{store}', [ShopifyAuthController::class, 'auth'])->name('shopify.auth');
    Route::get('/reinstall/{store}', [ShopifyAuthController::class, 'reinstall'])->name('shopify.reinstall');
    Route::get('/callback/{store}', [ShopifyAuthController::class, 'callback'])->name('shopify.callback');
});

Route::middleware(['guest', 'auth.tenant.context'])->prefix('auth/google')->name('auth.google.')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [GoogleAuthController::class, 'callback'])->name('callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/ui/preferences', [UiPreferencesController::class, 'update'])->name('ui.preferences.update');
    Route::post('/ui/preferences/sidebar-order', [UiPreferencesController::class, 'updateSidebarOrder'])->name('ui.preferences.sidebar-order');
    Route::post('/ui/preferences/theme', [UiPreferencesController::class, 'updateTheme'])->name('ui.preferences.theme');
});

Route::middleware('signed')
    ->get('/rewards/policy/exports/signed/{tenant}/{type}', [ShopifyEmbeddedRewardsController::class, 'downloadSignedRewardsExport'])
    ->name('rewards.policy.exports.signed');

require __DIR__.'/settings.php';
