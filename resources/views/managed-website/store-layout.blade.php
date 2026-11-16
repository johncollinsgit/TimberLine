@php
    $storeSettings = $storeSettings ?? (array) ($site->publishedSiteVersion?->settings ?? $site->settings);
    $storePreview = (bool) ($storePreview ?? false);
    $storefront = $storefront ?? app(\App\Services\ManagedWebsite\WebsiteStorefrontPresentation::class)->capabilities($site, request(), $storePreview);
    $storeAccountEnabled = $storefront['can_account'];
    $storeCartEnabled = $storefront['can_cart'];
    $storeName = data_get($storeSettings, 'theme_name') ?: $site->tenant?->brandProfile?->display_name ?: $site->tenant?->name;
    $starterStore = str_starts_with((string) data_get($storeSettings, 'theme_key'), 'starter-');
    $storeFont = data_get($storeSettings, 'typography') === 'serif' ? 'Georgia, ui-serif, serif' : 'Inter, ui-sans-serif, system-ui, sans-serif';
    $storeColor = static fn (string $key, string $fallback): string => preg_match('/^#[0-9a-fA-F]{6}$/D', (string) data_get($storeSettings, 'theme_palette.'.$key)) ? data_get($storeSettings, 'theme_palette.'.$key) : $fallback;
@endphp
@if(data_get($storeSettings, 'theme_key') === 'sawyer-naturals')
@include('managed-website.sawyer.layout')
@else
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $storeName }}</title><style>body{margin:0;color:#18263a;background:#fff;font-family:ui-sans-serif,system-ui,sans-serif}*{box-sizing:border-box}.store-nav,.store-shell,.store-hero{margin:auto;max-width:1120px;padding-left:24px;padding-right:24px}.store-nav{align-items:center;border-bottom:1px solid #dce3e8;display:flex;height:68px;justify-content:space-between}.store-nav a{color:inherit;font-size:14px;font-weight:800;text-decoration:none}.store-hero{background:#eef3f8;max-width:none;padding-bottom:76px;padding-top:76px;text-align:center}.store-hero p,.store-card p{color:#164b7a;font-size:11px;font-weight:900;letter-spacing:.14em;text-transform:uppercase}.store-hero h1,.store-product h1,.store-heading h2{font-family:Georgia,serif;font-size:clamp(34px,6vw,62px);letter-spacing:-.04em;margin:12px auto;max-width:14ch}.store-hero span,.store-copy{color:#536273;font-size:16px;line-height:1.6}.store-shell{padding-bottom:70px;padding-top:46px}.store-grid{display:grid;gap:20px;grid-template-columns:repeat(3,1fr);margin-top:25px}.store-card{border:1px solid #dce3e8;padding-bottom:18px}.store-card__image,.store-product__image{background:linear-gradient(135deg,#dbe7f2,#f7f3ea);min-height:210px}.store-card p,.store-card h3,.store-card span,.store-card a{display:block;margin-left:18px;margin-right:18px}.store-card h3{font-size:18px;margin-bottom:7px}.store-card span{color:#536273;font-size:14px;line-height:1.5;min-height:64px}.store-card a,.store-back{color:#164b7a;font-size:13px;font-weight:800;margin-top:14px}.store-product{display:grid;gap:55px;grid-template-columns:1fr 1fr}.store-product__image{min-height:500px}.store-product h1{font-size:48px;margin:15px 0}.store-product label{display:block;font-size:13px;font-weight:800;margin:13px 0}.store-product input,.store-product textarea{border:1px solid #b8c6d3;border-radius:4px;display:block;font:inherit;margin-top:6px;padding:10px;width:100%}.store-product textarea{min-height:120px}.store-button{background:#164b7a;border:0;border-radius:4px;color:#fff;cursor:pointer;font-size:14px;font-weight:800;margin-top:15px;padding:13px 17px}.store-checkout{margin-top:30px;max-width:570px}.store-notice{background:#e7f6eb;border:1px solid #bde0c8;padding:12px}@media(max-width:700px){.store-grid,.store-product{grid-template-columns:1fr}.store-product__image{min-height:280px}.store-hero{padding-bottom:55px;padding-top:55px}}.store-card__image{aspect-ratio:4/3;overflow:hidden}.store-card__image img,.store-product__image img{display:block;width:100%;height:100%;object-fit:cover}.store-product__photos{display:grid;gap:16px}.store-product__image{min-height:0;aspect-ratio:1;overflow:hidden;border-radius:14px}.store-card{overflow:hidden;border-radius:16px}.store-card a{min-height:44px;display:flex;align-items:center}.store-card .store-card-photo{display:block;margin:0;min-height:0}</style>
<style>
.store-order{max-width:820px;padding-top:56px}
.store-order__eyebrow{font-size:12px;font-weight:800;letter-spacing:.14em;margin:0 0 12px}
.store-order h1{font-size:clamp(30px,5vw,48px);line-height:1.15;letter-spacing:-.035em;margin:0 0 20px}
.store-order h1 span,.store-order__details strong{overflow-wrap:anywhere}
.store-order__status{line-height:1.65;margin:0;max-width:60ch}
.store-order__summary{border:1px solid #dce3e8;border-radius:16px;margin-top:28px;padding:0 24px}
.store-order__item,.store-order__charge,.store-order__total{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:baseline;gap:20px;padding:22px 0}
.store-order__charge{padding:12px 0}
.store-order__item+.store-order__item,.store-order__total{border-top:1px solid #dce3e8}
.store-order__details{display:grid;gap:8px;min-width:0;line-height:1.45}
.store-order__details span{font-size:14px}
.store-order__amount{font-variant-numeric:tabular-nums;white-space:nowrap}
.store-order__total{font-size:18px}
.store-order__actions{display:flex;margin-top:24px}
.store-order__actions .store-button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;margin:0;text-align:center;text-decoration:none}
@media(max-width:480px){.store-order{padding-top:36px}.store-order__summary{padding:0 18px}.store-order__item,.store-order__charge,.store-order__total{gap:12px}.store-order__actions .store-button{width:100%}}
</style>
@if($starterStore)<style>
body{background:{{ $storeColor('surface', '#fffefa') }};color:{{ $storeColor('ink', '#183f35') }};font-family:{{ $storeFont }}}
.store-nav a,.store-back,.store-card a{color:{{ $storeColor('brand', '#246a55') }}}
.store-hero{background:{{ $storeColor('soft', '#f0f4ee') }};padding-top:48px;padding-bottom:48px}
.store-hero h1,.store-product h1,.store-heading h2{font-family:inherit;font-weight:750;max-width:24ch;font-size:clamp(28px,5vw,48px)}
.store-button{background:{{ $storeColor('brand', '#246a55') }};border-radius:12px}.store-card{background:#fff;border-color:#dbe3dc}.store-card__image{background:{{ $storeColor('soft', '#f0f4ee') }}}.store-preview{background:#fff5cf;color:#443402;margin:0;padding:12px 24px;font-size:14px;text-align:center}.store-preview a{margin-left:12px;font-weight:700}.store-nav{gap:16px;min-height:68px;height:auto;flex-wrap:wrap;padding-top:16px;padding-bottom:16px}
</style>@endif
</head><body>
@if($storePreview)<p class="store-preview" role="status">Private catalog preview. Shopping and payment are disabled.<a href="{{ route('managed-website.index', ['tenant' => $site->tenant->slug]) }}#retail-setup">Back to Website</a></p>@endif<nav class="store-nav"><a href="{{ $homeUrl ?? '/' }}">{{ $storeName }}</a>@if(data_get($storeSettings, 'domain_choice') === 'everbranch_subdomain' && ! $storePreview)<a href="{{ ($shopUrl ?? route('managed-website.store.index')) }}">Services</a>@else<div style="display:flex;gap:18px"><a href="{{ $shopUrl ?? route('managed-website.store.index') }}">Shop</a>@if($storeAccountEnabled)<a href="{{ route('managed-website.store.account') }}">Account</a>@endif
@if($storeCartEnabled)<a href="{{ route('managed-website.store.cart') }}">Cart</a>@endif</div>@endif</nav>@yield('store-content')</body></html>

@endif
