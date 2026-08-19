<?php

use App\Http\Controllers\ShopifyEmbeddedSettingsController;
use App\Http\Controllers\ShopifyProductOptionsController;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyAppContentService;
use App\Services\Shopify\ModernForestryFundraiserInvoiceSettingsService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('settings', function (
    Request $request,
    ShopifyEmbeddedAppContext $contextService,
    ShopifyEmbeddedSettingsController $controller
) {
    if ($contextService->hasPageContext($request)) {
        return $controller->show(
            $request,
            $contextService,
            app(TwilioSenderConfigService::class),
            app(TenantResolver::class),
            app(TenantEmailSettingsService::class),
            app(ShopifyAppContentService::class),
            app(ModernForestryFundraiserInvoiceSettingsService::class),
            app(ModernForestryAlphaBootstrapService::class),
        );
    }

    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect('settings/profile');
})->name('shopify.embedded.settings');

// Shopify's live App URL is the Everbranch root, so Admin deep-link paths are
// appended directly to it. Internal navigation remains on `/shopify/app/...`.
Route::get('product-options', [ShopifyProductOptionsController::class, 'show'])
    ->middleware('shopify.embedded.surface')
    ->name('shopify.embedded.product-options');

Route::middleware(['auth'])->group(function () {
    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/password', 'pages::settings.password')->name('user-password.edit');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/two-factor', 'pages::settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');
});
