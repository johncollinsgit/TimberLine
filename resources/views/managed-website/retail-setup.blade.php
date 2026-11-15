@php
    $photos = app(\App\Services\ManagedWebsite\WebsiteStarterCatalog::class)->photos($tenant);
    $cover = data_get($retailCards ?? [], 'products.0.image') ?: data_get($photos, '0.url');
    $collectionCover = data_get($retailCards ?? [], 'collections.0.image') ?: data_get($photos, '1.url', data_get($photos, '0.url'));
    $editorPage = $pages->firstWhere('slug', '/') ?? $pages->first();
    $stepStatus = static fn (bool $done, bool $started = false): string => $done ? '✅' : ($started ? '🟡' : '❌');
@endphp
<section id="retail-setup" aria-labelledby="retail-setup-heading" class="space-y-4">
    <div class="eb-section-heading"><div><h2 id="retail-setup-heading">Your shop</h2><p>Your products, photos, and next steps.</p></div></div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <article class="overflow-hidden rounded-[20px] border border-zinc-200 bg-white">
            @if($cover)<img src="{{ $cover }}" alt="" class="h-36 w-full object-cover" loading="lazy">@endif
            <div class="space-y-3 p-5"><h3 class="font-semibold"><span aria-label="{{ $retailGuide['photos_ready'] ? 'Done' : ($retailGuide['product_count'] ? 'In progress' : 'Not started') }}">{{ $stepStatus($retailGuide['photos_ready'], $retailGuide['product_count'] > 0) }}</span> Products &amp; photos</h3>
                <p class="text-sm text-zinc-600">{{ $retailGuide['active_products'] }} active{{ $retailGuide['missing_photos'] > 0 ? ' · '.$retailGuide['missing_photos'].' need a photo' : '' }}. Use your actual product photos.</p>
                @if($retailGuide['can_manage_catalog'])<a class="eb-admin-button" href="{{ route('managed-website.products.create', ['tenant' => $tenant->slug]) }}">{{ $retailGuide['product_count'] ? 'Add product' : 'Add your first product' }}</a>@endif
                @if($retailGuide['unpaid_trial'])<a class="eb-admin-button" href="{{ route('self-service.purchase', ['tenant' => $tenant->slug]) }}#activate-plan">Activate plan</a><p class="text-sm text-zinc-600">Activate to add catalog products and photos. Your private website stays saved.</p>@elseif(! $retailGuide['can_manage_catalog'])<p class="text-sm text-zinc-600">Catalog editing is paused. Your saved work stays available.</p>@endif
                @if($retailGuide['can_manage_catalog'] && $retailGuide['product_count'])<a class="text-sm font-semibold underline" href="{{ route('managed-website.products.index', ['tenant' => $tenant->slug]) }}">Manage products</a>@endif
            </div>
        </article>
        <article class="overflow-hidden rounded-[20px] border border-zinc-200 bg-white">
            @if($collectionCover)<img src="{{ $collectionCover }}" alt="" class="h-36 w-full object-cover" loading="lazy">@endif
            <div class="space-y-3 p-5"><h3 class="font-semibold"><span aria-label="{{ $retailGuide['collections_ready'] ? 'Done' : ($retailGuide['collections'] ? 'In progress' : 'Not started') }}">{{ $stepStatus($retailGuide['collections_ready'], $retailGuide['collections'] > 0) }}</span> Collections</h3>
                <p class="text-sm text-zinc-600">Link active products and choose a cover photo. {{ $retailGuide['collections'] }} active{{ $retailGuide['missing_collection_photos'] ? ' · '.$retailGuide['missing_collection_photos'].' need a cover' : '' }}{{ $retailGuide['unlinked_collections'] ? ' · '.$retailGuide['unlinked_collections'].' need active products' : '' }}.</p>
                @if($retailGuide['can_manage_catalog'])<a class="eb-admin-button" href="{{ route('managed-website.collections.index', ['tenant' => $tenant->slug]) }}">Manage collections</a>@else<p class="text-sm text-zinc-600">{{ $retailGuide['unpaid_trial'] ? 'Add collections after activating your plan.' : 'Collection editing is paused.' }}</p>@endif
            </div>
        </article>
        <article class="eb-admin-panel space-y-3 rounded-[20px] p-5"><h3 class="font-semibold"><span aria-label="{{ $retailGuide['has_shop_link'] ? 'Done' : ($retailGuide['active_products'] ? 'In progress' : 'Not started') }}">{{ $stepStatus($retailGuide['has_shop_link'], $retailGuide['active_products'] > 0) }}</span> Menu</h3><p class="text-sm text-zinc-600">Add Shop to your draft menu after adding a product. Publish when ready.</p>
            @if($editorPage && $isEditorEnabled)<a class="eb-admin-button" href="{{ route('managed-website.editor', ['page' => $editorPage, 'tenant' => $tenant->slug, 'menu' => 1]) }}">Edit menu</a>@endif
        </article>
        <article class="eb-admin-panel space-y-3 rounded-[20px] p-5"><h3 class="font-semibold">Preview</h3><p class="text-sm text-zinc-600">Check products, photos, and options privately. No orders or payments.</p><a class="eb-admin-button" href="{{ route('managed-website.preview.shop', ['tenant' => $tenant->slug]) }}">Preview shop</a></article>
        <article class="eb-admin-panel space-y-3 rounded-[20px] p-5"><h3 class="font-semibold"><span aria-label="{{ $retailGuide['payments_ready'] ? 'Done' : 'In progress' }}">{{ $stepStatus($retailGuide['payments_ready'], true) }}</span> Payments</h3><p class="text-sm text-zinc-600">{{ $retailGuide['payments_ready'] ? 'Your business payment account is verified.' : 'Connect your business account. Online payment stays off until setup is verified.' }}</p>
            @if($retailGuide['can_setup_payments'])<a class="eb-admin-button" href="{{ route('tenant-payments.index', ['tenant' => $tenant->slug]) }}">Set up payments</a>@else<p class="text-sm text-zinc-600">Payment setup is not available for this workspace yet.</p>@endif
        </article>
        <article class="eb-admin-panel space-y-3 rounded-[20px] p-5"><h3 class="font-semibold"><span aria-label="{{ $site->public_enabled ? 'Done' : 'In progress' }}">{{ $stepStatus((bool) $site->public_enabled, true) }}</span> Publish</h3><p class="text-sm text-zinc-600">{{ $site->public_enabled ? 'Site is live. Publish again to update saved changes.' : 'Publish your saved website and menu.' }} Online payment can stay off.</p>
            @if($isEditorEnabled && $canPublishSite)<form method="POST" action="{{ route('managed-website.publish', ['tenant' => $tenant->slug]) }}">@csrf<button type="submit" class="eb-admin-button">Publish website</button></form>@endif
        </article>
    </div>
</section>
