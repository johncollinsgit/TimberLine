<?php

use App\Http\Controllers\Marketplace\AccountController;
use App\Http\Controllers\Marketplace\MakerController;
use App\Http\Controllers\Marketplace\MakerVideoController;
use App\Http\Controllers\Marketplace\OperatorController;
use App\Http\Controllers\Marketplace\ShopifyWebhookController;
use App\Http\Controllers\Marketplace\SocialVideoImportController;
use App\Http\Controllers\Marketplace\StorefrontController;
use App\Http\Controllers\Marketplace\VideoReviewController;
use App\Http\Controllers\Marketplace\WatchController;
use App\Http\Controllers\Marketplace\WorkspaceController;
use App\Http\Middleware\MarketplaceAppHost;
use App\Http\Middleware\MarketplacePublicHost;
use Illuminate\Support\Facades\Route;

Route::get('/marketplace/preview', \App\Http\Controllers\Marketplace\AccountPreviewController::class)
    ->middleware('auth')->name('marketplace.preview');

if (config('marketplace.host')) {
    Route::domain(config('marketplace.host'))->middleware(MarketplacePublicHost::class)->name('marketplace.public.')->group(function (): void {
        Route::get('/', [StorefrontController::class, 'index'])->name('home');
        require __DIR__.'/marketplace-video-catalog.php';
        Route::get('/markets', [\App\Http\Controllers\Marketplace\MarketController::class, 'index'])->name('markets');
        Route::prefix('/maker/{id}/studio/social')->whereNumber('id')->middleware('throttle:20,1')->group(function () {
            Route::get('/accounts', [SocialVideoImportController::class, 'accounts'])->name('maker.social.accounts');
            Route::get('/{platform}/{account}', [SocialVideoImportController::class, 'clips'])->whereIn('platform', ['instagram', 'facebook'])->whereNumber('account');
            Route::post('/{platform}/{account}/{clip}', [SocialVideoImportController::class, 'file'])->whereIn('platform', ['instagram', 'facebook'])->whereNumber(['account', 'clip']);
        });
        Route::get('/watch', [WatchController::class, 'index'])->name('watch');
        Route::get('/watch/{videoId}', [WatchController::class, 'show'])->whereNumber('videoId')->name('watch.show');
        Route::get('/watch/{videoId}/film', [WatchController::class, 'file'])->whereNumber('videoId')->name('watch.file');
        Route::get('/watch/{videoId}/poster.jpg', [WatchController::class, 'poster'])->whereNumber('videoId')->name('watch.poster');
        Route::get('/watch/{videoId}/captions.vtt', [WatchController::class, 'captions'])->whereNumber('videoId')->name('watch.captions');
        Route::post('/watch/{videoId}/play', [WatchController::class, 'viewed'])->whereNumber('videoId')->middleware('throttle:60,1')->name('watch.play');
        Route::post('/watch/{videoId}/products/{tagId}', [WatchController::class, 'shop'])->whereNumber(['videoId', 'tagId'])->middleware('throttle:60,1')->name('watch.shop');
        Route::get('/maker/{id}/studio', [MakerVideoController::class, 'index'])->whereNumber('id')->name('maker.videos');
        Route::get('/maker/{id}/studio/new', [MakerVideoController::class, 'edit'])->whereNumber('id')->name('maker.videos.new');
        Route::post('/maker/{id}/studio', [MakerVideoController::class, 'save'])->whereNumber('id')->middleware('throttle:20,60')->name('maker.videos.create');
        Route::get('/maker/{id}/studio/{videoId}', [MakerVideoController::class, 'edit'])->whereNumber(['id', 'videoId'])->name('maker.videos.edit');
        Route::post('/maker/{id}/studio/{videoId}', [MakerVideoController::class, 'save'])->whereNumber(['id', 'videoId'])->middleware('throttle:30,60')->name('maker.videos.save');
        Route::get('/maker/{id}/studio/{videoId}/source', [MakerVideoController::class, 'source'])->whereNumber(['id', 'videoId'])->name('maker.videos.source');
        Route::get('/products/{slug}', [StorefrontController::class, 'product'])->name('product');
        Route::get('/makers/{slug}', [StorefrontController::class, 'maker'])->name('maker');
        Route::get('/policies/{type}', [StorefrontController::class, 'policy'])->name('policy');
        Route::get('/cart', [StorefrontController::class, 'cart'])->name('cart');
        Route::post('/cart/add', [StorefrontController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
        Route::post('/cart/remove', [StorefrontController::class, 'remove'])->name('cart.remove');
        Route::post('/checkout', [StorefrontController::class, 'checkout'])->middleware('throttle:10,1')->name('checkout');
        Route::get('/checkout/{uuid}/paypal', [\App\Http\Controllers\Marketplace\PayPalController::class, 'show'])->name('paypal');
        Route::post('/checkout/{uuid}/paypal', [\App\Http\Controllers\Marketplace\PayPalController::class, 'act'])->middleware('throttle:15,1')->name('paypal.act');
        Route::get('/checkout/{uuid}/shop-pay', [\App\Http\Controllers\Marketplace\WalletController::class, 'show'])->name('wallet');
        Route::post('/checkout/{uuid}/shop-pay', [\App\Http\Controllers\Marketplace\WalletController::class, 'event'])->middleware('throttle:60,1')->name('wallet.event');
        Route::post('/checkout/{uuid}/shop-pay/cancel', [\App\Http\Controllers\Marketplace\WalletController::class, 'cancel'])->name('wallet.cancel');
        Route::get('/checkout/{uuid}', [StorefrontController::class, 'progress'])->name('checkout.status');
        Route::get('/account', [AccountController::class, 'index'])->name('account');
        Route::post('/account/link', [AccountController::class, 'send'])->middleware('throttle:3,10')->name('account.link');
        Route::get('/account/verify/{token}', [AccountController::class, 'verify'])->name('account.verify');
        Route::post('/account/verify/{token}', [AccountController::class, 'consume'])->middleware('throttle:10,1')->name('account.consume');
        Route::post('/account/logout', [AccountController::class, 'logout'])->name('account.logout');
        Route::get('/account/orders/{id}', [AccountController::class, 'order'])->whereNumber('id')->name('account.order');
        Route::get('/sell', [MakerController::class, 'index'])->name('sell');
        Route::post('/sell', [MakerController::class, 'apply'])->middleware('throttle:5,60')->name('apply');
        Route::get('/maker/{id}', [MakerController::class, 'dashboard'])->whereNumber('id')->name('maker.dashboard');
        Route::post('/maker/{id}/listings', [MakerController::class, 'save'])->name('maker.listings');
        Route::post('/maker/{id}/listings/{listingId}/pause', [MakerController::class, 'pause'])->name('maker.pause');
        Route::post('/maker/{id}/rates', [MakerController::class, 'accept'])->name('maker.rates');
        Route::post('/maker/{id}/inventory', [MakerController::class, 'inventory'])->name('maker.inventory');
        Route::post('/maker/{id}/orders/{lineId}', [MakerController::class, 'fulfill'])->middleware('throttle:15,1')->name('maker.fulfill');
        Route::post('/maker/{id}/markets', [\App\Http\Controllers\Marketplace\MarketController::class, 'apply'])->name('maker.markets');
        Route::post('/maker/{id}/store-links', [\App\Http\Controllers\Marketplace\MarketController::class, 'link'])->name('maker.links');
        Route::post('/maker/{id}/store-links/{linkId}', [\App\Http\Controllers\Marketplace\MarketController::class, 'unlink'])->name('maker.links.remove');
        Route::post('/maker/{id}/details', [\App\Http\Controllers\Marketplace\MarketController::class, 'details'])->name('maker.details');
        Route::post('/maker/{id}/connect', [MakerController::class, 'connect'])->name('maker.connect');
    });
}

Route::middleware(['auth', MarketplaceAppHost::class])->prefix('marketplace')->name('marketplace.workspace.')->group(function (): void {
    Route::get('/connect/{token}', [WorkspaceController::class, 'show'])->name('connect');
    Route::post('/connect/{token}', [WorkspaceController::class, 'connect'])->name('connect.save');
    Route::get('/workspaces/{id}', [WorkspaceController::class, 'catalog'])->name('catalog');
    Route::post('/workspaces/{id}/video-variants', [\App\Http\Controllers\Marketplace\VideoCatalogController::class, 'shareNative'])->name('video-variants');
    Route::post('/workspaces/{id}/import', [WorkspaceController::class, 'import'])->name('import');
    Route::post('/workspaces/{id}/inventory', [WorkspaceController::class, 'allocate'])->name('inventory');
});

Route::middleware(['auth', MarketplaceAppHost::class.':operator'])->prefix('marketplace/operations')->name('marketplace.operator.')->group(function (): void {
    Route::get('/', [OperatorController::class, 'index'])->name('index');
    Route::get('/videos', [VideoReviewController::class, 'index'])->name('videos');
    Route::post('/videos/invitations', [VideoReviewController::class, 'invite'])->name('videos.invite');
    Route::get('/videos/{videoId}', [VideoReviewController::class, 'show'])->whereNumber('videoId')->name('videos.show');
    Route::get('/videos/{videoId}/{asset}', [VideoReviewController::class, 'preview'])->whereNumber('videoId')->whereIn('asset', ['film', 'captions'])->name('videos.preview');
    Route::post('/videos/{videoId}/costs', [VideoReviewController::class, 'costs'])->whereNumber('videoId')->name('videos.costs');
    Route::post('/videos/{videoId}', [VideoReviewController::class, 'decide'])->whereNumber('videoId')->name('videos.decide');
    Route::get('/makers/{id}', [OperatorController::class, 'makerDetail'])->name('maker.detail');
    Route::post('/markets', [\App\Http\Controllers\Marketplace\MarketController::class, 'save'])->name('markets.save');
    Route::post('/markets/{id}/makers', [\App\Http\Controllers\Marketplace\MarketController::class, 'participant'])->name('markets.makers');
    Route::post('/settings', [OperatorController::class, 'settings'])->name('settings');
    Route::post('/wallet', [\App\Http\Controllers\Marketplace\WalletController::class, 'connect'])->middleware('throttle:5,10')->name('wallet.connect');
    Route::post('/connection', [OperatorController::class, 'connection'])->middleware('throttle:5,10')->name('connection');
    Route::post('/makers/{id}', [OperatorController::class, 'maker'])->name('maker');
    Route::post('/listings/{id}', [OperatorController::class, 'listing'])->name('listing');
    Route::post('/pickup', [OperatorController::class, 'pickup'])->name('pickup');
    Route::post('/rates', [OperatorController::class, 'rate'])->name('rate');
    Route::post('/orders/{id}', [OperatorController::class, 'order'])->name('order');
    Route::post('/refunds/{id}/preview', [OperatorController::class, 'previewRefund'])->name('refund.preview');
    Route::post('/refunds/{id}', [OperatorController::class, 'refund'])->name('refund');
    Route::post('/statements', [OperatorController::class, 'statement'])->name('statement');
    Route::post('/events/{id}', [OperatorController::class, 'retryEvent'])->name('event.retry');
});

Route::post('/webhooks/marketplace/shopify/{connection}', [ShopifyWebhookController::class, 'receive'])->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])->middleware('throttle:240,1')->name('marketplace.webhook');
Route::post('/webhooks/marketplace/stripe', [\App\Http\Controllers\Marketplace\StripeWebhookController::class, 'receive'])->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])->middleware('throttle:240,1')->name('marketplace.stripe.webhook');

Route::post('/webhooks/marketplace/paypal', [\App\Http\Controllers\Marketplace\PayPalWebhookController::class, 'receive'])->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])->middleware('throttle:240,1')->name('marketplace.paypal.webhook');
