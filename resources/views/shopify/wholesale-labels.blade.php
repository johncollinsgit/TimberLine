<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
@if($authorized)
<style>
.wl-admin{display:grid;gap:24px}.wl-surface{background:#fff;border:1px solid #dce3db;border-radius:20px;padding:24px}.wl-heading{font-size:20px;font-weight:650;margin:0 0 16px}.wl-muted{color:#667266;font-size:13px}.wl-row{display:flex;gap:12px;flex-wrap:wrap;align-items:end}.wl-admin label{display:grid;gap:6px;font-size:13px}.wl-admin input,.wl-admin select{border:1px solid #bcc9bb;border-radius:8px;padding:9px;max-width:100%}.wl-admin button,.wl-admin .wl-link{border:1px solid #bdc9ba;border-radius:8px;padding:10px 15px;font-size:13px;font-weight:600;background:#f5f7f1;color:#294231;cursor:pointer}.wl-admin button[data-approve]{background:#294231;color:#fff}.wl-admin button:disabled{opacity:.5;cursor:wait}.wl-proofs{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:18px 0}.wl-proof{border:1px solid #dce3db;border-radius:14px;padding:12px;background:#fff;min-height:180px}.wl-checker{background-color:#fff;background-image:linear-gradient(45deg,#e3e8e1 25%,transparent 25%),linear-gradient(-45deg,#e3e8e1 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#e3e8e1 75%),linear-gradient(-45deg,transparent 75%,#e3e8e1 75%);background-size:20px 20px;background-position:0 0,0 10px,10px -10px,-10px 0}.wl-proof img{display:block;height:180px;width:100%;object-fit:contain}.wl-status{display:inline-block;border-radius:99px;background:#edf2e7;padding:4px 10px;font-size:12px}.wl-alert{background:#fff3d9;border:1px solid #d9b96a;border-radius:12px;padding:14px}.wl-version{border-top:1px solid #dce3db;padding-top:16px;margin-top:18px}.wl-feedback{white-space:pre-wrap;font-size:13px;padding:12px;color:#294231}.wl-feedback:empty{display:none}.wl-admin details summary{cursor:pointer;font-size:13px;font-weight:600}.wl-admin a{text-decoration:underline;text-underline-offset:3px}@media(max-width:650px){.wl-proofs{grid-template-columns:1fr}.wl-surface{padding:18px}.wl-row>*{max-width:100%}}
</style>
<div class="wl-admin" id="wl-admin" data-context="{{ $contextToken }}">
<p class="wl-muted">Originals remain unchanged. Black vector proofs require staff approval before custom-label production. Archived designs stay attached to previous orders.</p>
<section class="wl-surface">
<h2 class="wl-heading">Import customer artwork</h2>
<form data-label-import action="{{ route('shopify.app.wholesale.labels.import', [], false) }}" method="post" enctype="multipart/form-data">
@csrf
<div class="wl-row">
<label>Shopify customer ID<input name="customer_id" required pattern="[0-9]+" list="wl-customers"></label>
<datalist id="wl-customers">@foreach($customers as $account)<option value="{{ $account->shopify_customer_id }}">{{ $account->display_name }}</option>@endforeach</datalist>
<label>Label name <span class="wl-muted">Optional for batch imports; filenames supply names.</span><input name="name" maxlength="120"></label>
<label>Original files<input type="file" name="files[]" required multiple accept=".png,.jpg,.jpeg,.webp,.svg,.pdf"></label>
</div>
<label style="display:flex;gap:8px;margin:16px 0"><input type="checkbox" name="confirmed" value="1" required> I confirm these files belong to the selected customer.</label>
<p class="wl-muted">Up to 50 files, 20 MB each. PNG, JPEG, WebP, plain SVG or single-page PDF. Files are matched only to the customer you confirm.</p>
<button type="submit">Import files</button><div class="wl-feedback" role="status"></div>
</form>
</section>
@if($pending->isNotEmpty())
<section class="wl-surface"><h2 class="wl-heading">Locate previous labels</h2>
@foreach($pending as $item)<div class="wl-alert" style="margin-top:12px"><strong>Customer {{ $item->customer_id }}</strong> · {{ $item->shopify_order_id ? 'Order '.$item->shopify_order_id : 'Preparing an order' }}<p>{{ $item->instructions ?: 'Buyer requested their previous label.' }}</p>
<form data-label-import action="{{ route('shopify.app.wholesale.labels.import', [], false) }}" method="post" enctype="multipart/form-data">@csrf
<input type="hidden" name="customer_id" value="{{ $item->customer_id }}"><input type="hidden" name="request_id" value="{{ $item->id }}"><input type="hidden" name="name" value="Previous label"><input type="hidden" name="confirmed" value="1">
<label>Locate and upload their original file<input type="file" name="files[]" required accept=".png,.jpg,.jpeg,.webp,.svg,.pdf"></label><button type="submit" style="margin-top:8px">Attach located file</button><div class="wl-feedback" role="status"></div></form></div>@endforeach
</section>
@endif
<section class="wl-surface"><form method="get" class="wl-row">@foreach(app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class)->contextQuery(request()) as $key => $value)@if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<label>Search customer name, company, email, label name or ID<input name="search" value="{{ $search }}"></label><button>Search</button></form></section>
@forelse($designs as $design)
<section class="wl-surface"><div class="wl-row"><h2 class="wl-heading" style="margin:0">{{ $design->name }}</h2><span class="wl-muted">Customer {{ $design->customer_id }}</span>@if($design->archived_at)<span class="wl-status">Archived</span>@endif</div>
@foreach($revisions->get($design->id, collect()) as $revision)
<div class="wl-version"><span class="wl-status">{{ ucfirst($revision->status) }}</span> <span class="wl-muted">{{ $revision->original_name }} · {{ $revision->created_at->format('M j, Y g:i A') }} · {{ substr($revision->id, 0, 8) }}</span>
@if($revision->failure)<p class="wl-alert">{{ $revision->failure }}</p>@endif
<div class="wl-proofs">
<div><p class="wl-muted">Original upload preview</p><div class="wl-proof">@if($revision->preview_path)<img data-label-preview-url="{{ $embeddedUrl(route('shopify.app.wholesale.labels.asset', [$revision->id, 'preview'], false)) }}" alt="Original logo preview" loading="lazy">@else<p class="wl-muted">Preview preparing</p>@endif</div></div>
<div><p class="wl-muted">Black vector · transparent background</p><div class="wl-proof wl-checker">@if($revision->vector_preview_path)<img data-label-preview-url="{{ $embeddedUrl(route('shopify.app.wholesale.labels.asset', [$revision->id, 'vector-preview'], false)) }}" alt="Black vector proof" loading="lazy">@else<p class="wl-muted">Vector preparing</p>@endif</div></div>
</div>
<div class="wl-row"><a href="{{ $embeddedUrl(route('shopify.app.wholesale.labels.asset', [$revision->id, 'original'], false)) }}" data-label-download>Download original</a>@if($revision->vector_path)<a href="{{ $embeddedUrl(route('shopify.app.wholesale.labels.asset', [$revision->id, 'vector'], false)) }}" data-label-download>Download SVG</a>@endif
@if($revision->status === 'draft' && !$design->archived_at)<form data-label-action action="{{ route('shopify.app.wholesale.labels.action', $revision->id, false) }}" method="post">@csrf<input name="action" value="approve" type="hidden"><button data-approve>Approve this proof</button><div class="wl-feedback" role="status"></div></form>@endif
</div>
<details style="margin-top:16px"><summary>Adjust crop, retry conversion, or replace vector</summary>
<form data-label-action action="{{ route('shopify.app.wholesale.labels.action', $revision->id, false) }}" method="post" enctype="multipart/form-data">@csrf<input name="action" value="regenerate" type="hidden">
<div class="wl-row" style="margin-top:12px"><label>Black threshold (1–254)<input type="number" name="threshold" min="1" max="254" value="{{ $revision->settings['threshold'] ?? 160 }}"></label><label>Replacement black SVG (optional)<input name="vector" type="file" accept=".svg"></label></div>
<p class="wl-muted">Optional crop uses fractions of the original image: 0 is the first edge, 1 is the full dimension.</p><div class="wl-row">@foreach(['x'=>0,'y'=>0,'width'=>1,'height'=>1] as $field=>$default)<label>{{ ucfirst($field) }}<input name="crop[{{ $field }}]" type="number" step="0.01" min="{{ in_array($field,['width','height']) ? '0.01' : '0' }}" max="1" value="{{ $revision->settings['crop'][$field] ?? $default }}" style="width:90px"></label>@endforeach</div>
<button style="margin-top:12px">Create a new proof revision</button><div class="wl-feedback" role="status"></div></form></details>
@php $used = $assignments->filter(fn ($use) => $use->revision_id === $revision->id || $use->production_revision_id === $revision->id); @endphp
@if($used->isNotEmpty())<p class="wl-muted">Used on orders: @foreach($used->unique('shopify_order_id') as $use){{ $use->shopify_order_id }} ({{ str_replace('_',' ',$use->status) }}){{ !$loop->last ? ', ' : '' }}@endforeach</p>@endif
</div>
@endforeach
@if(!$design->archived_at && $revisions->get($design->id)?->first())<form data-label-action action="{{ route('shopify.app.wholesale.labels.action', $revisions->get($design->id)->first()->id, false) }}" method="post" style="margin-top:16px">@csrf<input type="hidden" name="action" value="archive"><button>Archive design</button><div class="wl-feedback" role="status"></div></form>@endif
<details style="margin-top:16px"><summary>Staff audit history</summary>@foreach($events->get($design->id, collect()) as $event)<p class="wl-muted">{{ $event->created_at->format('M j, Y g:i A') }} · {{ str_replace('_',' ',$event->action) }} · {{ $event->actor_id ? 'Staff #'.$event->actor_id : 'Buyer' }}</p>@endforeach</details>
</section>
@empty<section class="wl-surface"><h2 class="wl-heading">A home for every customer’s artwork</h2><p class="wl-muted">New uploads and imported labels will appear here. Use the import form to bring in files currently held on a staff computer.</p></section>@endforelse
</div>
@vite('resources/js/wholesale-label-admin.js')
@endif
</x-shopify-embedded-shell>
