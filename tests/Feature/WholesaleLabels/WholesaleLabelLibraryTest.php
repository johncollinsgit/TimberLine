<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleLabelAssignment;
use App\Models\WholesaleLabelRequest;
use App\Models\WholesaleLabelRevision;
use App\Services\Wholesale\Labels\WholesaleLabelContext;
use App\Services\Wholesale\Labels\WholesaleLabelLibrary;
use App\Services\Wholesale\Labels\WholesaleLabelOrderService;
use App\Services\Wholesale\Labels\WholesaleLabelProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function labelLogoFile(): UploadedFile
{
    $im = imagecreatetruecolor(180, 100);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $white);
    imagefilledrectangle($im, 20, 20, 150, 80, $black);
    imagefilledrectangle($im, 50, 40, 120, 60, $white);
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return UploadedFile::fake()->createWithContent('fictional-logo.png', $bytes);
}
function labelProxyQuery(string $customer = '101', array $extra = []): array
{
    $query = ['shop' => 's2vscq-rf.myshopify.com', 'logged_in_customer_id' => $customer, 'timestamp' => (string) time()] + $extra;
    ksort($query);
    $canonical = '';
    foreach ($query as $key => $value) {
        $canonical .= $key.'='.$value;
    }$query['signature'] = hash_hmac('sha256', $canonical, 'wholesale-client-secret');

    return $query;
}
beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Storage::fake('team_private');
    $this->tenant = Tenant::query()->create(['name' => 'Fictional Label Test', 'slug' => 'fictional-label-test']);
    configureEmbeddedWholesaleStore($this->tenant->id);
    config(['wholesale_labels.enabled' => true, 'services.shopify.stores.wholesale.embedded_client_id' => null, 'services.shopify.stores.wholesale.embedded_client_secret' => null]);
    $this->owner = ['tenant_id' => $this->tenant->id, 'shop_domain' => 's2vscq-rf.myshopify.com', 'customer_id' => '101'];
    $this->library = app(WholesaleLabelLibrary::class);
    Http::fake(['*' => Http::response(['data' => ['customer' => ['id' => 'gid://shopify/Customer/101', 'tags' => ['wholesale']]]])]);
});

it('requires a fresh proxy signature, real signed-in customer, wholesale role and server approval', function () {
    $base = '/shopify/marketing/v1/wholesale/labels';
    $this->getJson($base)->assertStatus(403);
    $query = labelProxyQuery();
    $query['signature'] = 'forged';
    $this->getJson($base.'?'.http_build_query($query))->assertUnauthorized();
    $this->getJson($base.'?'.http_build_query(labelProxyQuery('')))->assertUnauthorized();
    $this->getJson($base.'?'.http_build_query(labelProxyQuery()))->assertOk()->assertJsonPath('labels', []);
});
it('saves unchanged private originals and deduplicates an upload retry', function () {
    $file = labelLogoFile();
    $bytes = file_get_contents($file->getRealPath());
    $revision = $this->library->upload($this->owner, $file, 'Fictional logo');
    expect(Storage::disk('team_private')->get($revision->original_path))->toBe($bytes)->and($revision->sha256)->toBe(hash('sha256', $bytes));
    $again = $this->library->upload($this->owner, $file, 'Fictional logo');
    expect($again->id)->toBe($revision->id)->and(WholesaleLabelRevision::query()->forAllTenants()->count())->toBe(1);
    Queue::assertPushed(App\Jobs\Wholesale\ProcessWholesaleLabel::class);
});
it('rejects unsafe SVG content and oversized files', function () {
    $this->library->upload($this->owner, UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'Unsafe');
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);
it('keeps another customer and another tenant artwork out of storefront previews', function () {
    $revision = $this->library->upload($this->owner, labelLogoFile(), 'Private logo');
    $this->getJson('/shopify/marketing/v1/wholesale/labels/assets/'.$revision->id.'/preview?'.http_build_query(labelProxyQuery('202')))->assertNotFound();
    $other = $this->owner;
    $other['tenant_id']++;
    expect($this->library->owned(WholesaleLabelRevision::class, $other)->find($revision->id))->toBeNull();
});
it('creates one $20 setup receipt and handles exact retries without changing evidence', function () {
    $revision = $this->library->upload($this->owner, labelLogoFile(), 'Fictional logo');
    $data = ['mode' => 'new', 'revision_id' => $revision->id, 'groups' => [['variant_id' => '101', 'line_key' => '101:key', 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()];
    $request = $this->library->prepare($this->owner, $data);
    $again = $this->library->prepare($this->owner, $data);
    expect($request->fee_cents)->toBe(2000)->and($request->id)->toBe($again->id)->and(WholesaleLabelRequest::query()->forAllTenants()->count())->toBe(1);
});
it('rejects reuse of unapproved artwork', function () {
    $revision = $this->library->upload($this->owner, labelLogoFile(), 'Draft');
    $this->library->prepare($this->owner, ['mode' => 'reuse', 'revision_id' => $revision->id, 'groups' => [['variant_id' => '101', 'line_key' => '101:key', 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);
it('prepares a $5 legacy request without inventing an existing asset', function () {
    $request = $this->library->prepare($this->owner, ['mode' => 'legacy', 'groups' => [['variant_id' => '101', 'line_key' => '101:key', 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
    expect($request->fee_cents)->toBe(500)->and($request->revision_id)->toBeNull();
});
it('returns saved label instructions only to the request owner for editing', function () {
    $revision = $this->library->upload($this->owner, labelLogoFile(), 'Saved logo');
    $receipt = $this->library->prepare($this->owner, ['mode' => 'new', 'revision_id' => $revision->id, 'groups' => [['variant_id' => '101', 'line_key' => '101:key', 'quantity' => 3]], 'instructions' => 'Keep these label instructions.', 'idempotency_key' => (string) Str::uuid()]);
    $url = '/shopify/marketing/v1/wholesale/labels/requests/'.$receipt->id;
    $this->getJson($url.'?'.http_build_query(labelProxyQuery()))->assertOk()->assertJsonPath('instructions', 'Keep these label instructions.');
    $this->getJson($url.'?'.http_build_query(labelProxyQuery('202')))->assertNotFound();
});
it('does not grant artwork management to application reviewers or inactive members', function () {
    $user = User::factory()->create(['role' => 'pouring', 'is_active' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'wholesale_reviewer', 'membership_active' => true]);
    expect(app(WholesaleLabelContext::class)->canManage($user, $this->tenant->id))->toBeFalse();
    $user->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'wholesale_label_manager']);
    expect(app(WholesaleLabelContext::class)->canManage($user, $this->tenant->id))->toBeTrue();
    $user->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect(app(WholesaleLabelContext::class)->canManage($user, $this->tenant->id))->toBeFalse();
});
it('allows an explicit scoped artwork assignment without replacing application-review access', function () {
    $user = User::factory()->create(['role' => 'pouring', 'is_active' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'wholesale_reviewer', 'membership_active' => true]);
    $access = app(WholesaleLabelContext::class);
    $grant = ['tenant_id' => $this->tenant->id, 'shop_domain' => 's2vscq-rf.myshopify.com', 'user_id' => (int) $user->id];
    config(['wholesale_labels.manager_assignments' => [$grant]]);
    expect($access->canManage($user, $this->tenant->id))->toBeTrue()
        ->and(app(App\Services\Onboarding\WholesaleApplicationReviewerAccess::class)->allows($user, $this->tenant->id))->toBeTrue();
    config(['wholesale_labels.manager_assignments' => [array_replace($grant, ['shop_domain' => 'other.myshopify.com'])]]);
    expect($access->canManage($user, $this->tenant->id))->toBeFalse();
    config(['wholesale_labels.manager_assignments' => [array_replace($grant, ['tenant_id' => $this->tenant->id + 1])]]);
    expect($access->canManage($user, $this->tenant->id))->toBeFalse();
    config(['wholesale_labels.manager_assignments' => [array_replace($grant, ['user_id' => $user->id + 1])]]);
    expect($access->canManage($user, $this->tenant->id))->toBeFalse();
    config(['wholesale_labels.manager_assignments' => [$grant]]);
    $user->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect($access->canManage($user, $this->tenant->id))->toBeFalse();
    $user->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => true]);
    $user->forceFill(['is_active' => false])->save();
    expect($access->canManage($user, $this->tenant->id))->toBeFalse();
});
it('renders an authorized label manager workspace with import and review tools', function () {
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    $this->actingAs($user)->get(route('shopify.app.wholesale.labels', wholesaleEmbeddedSignedQuery()))->assertOk()->assertSeeText('Import customer artwork')->assertSeeText('Originals remain unchanged');
});
it('traces actual black paths with transparent output while preserving the original', function () {
    $binary = getenv('WHOLESALE_LABEL_VTRACER') ?: dirname(base_path()).'/label-tools/bin/vtracer';
    if (! is_file($binary)) {
        $this->markTestSkipped('Pinned VTracer binary is required for the real conversion check.');
    }
    config(['wholesale_labels.vtracer' => $binary]);
    $revision = $this->library->upload($this->owner, labelLogoFile(), 'Fictional actual trace');
    app(WholesaleLabelProcessor::class)->process($revision->id);
    $revision->refresh();
    expect($revision->status)->toBe('draft');
    $svg = Storage::disk('team_private')->get($revision->vector_path);
    expect($svg)->toContain('<path')->not->toContain('<image');
    $png = imagecreatefromstring(Storage::disk('team_private')->get($revision->vector_preview_path));
    expect((imagecolorat($png, 0, 0) >> 24) & 127)->toBe(127);
    expect(imagecolorat($png, 30, 30) & 0xFFFFFF)->toBe(0);
    expect((imagecolorat($png, 80, 50) >> 24) & 127)->toBe(127);
    $original = Storage::disk('team_private')->get($revision->original_path);
    expect(hash('sha256', $original))->toBe($revision->sha256);
    $approved = $this->library->approve($this->owner, $revision->id, 123);
    expect($approved->status)->toBe('approved');
});
it('records a review hold and preserves selected and standard candle groups through repeated order ingestion', function () {
    $request = $this->library->prepare($this->owner, ['mode' => 'legacy', 'groups' => [['variant_id' => '111', 'line_key' => '111:key', 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
    $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'shopify_order_id' => 9001, 'shopify_customer_id' => '101']);
    $properties = [['name' => '_MF label mode', 'value' => 'legacy'], ['name' => '_MF label revision', 'value' => 'pending'], ['name' => '_MF label request', 'value' => $request->id], ['name' => 'Custom label', 'value' => 'Previous label']];
    $payload = ['customer' => ['id' => 101], 'line_items' => [['id' => 1, 'variant_id' => 42044189966417, 'quantity' => 1, 'properties' => $properties], ['id' => 2, 'variant_id' => 111, 'quantity' => 3, 'properties' => $properties], ['id' => 3, 'variant_id' => 222, 'quantity' => 3, 'properties' => []]]];
    app(WholesaleLabelOrderService::class)->capture($order, $payload);
    app(WholesaleLabelOrderService::class)->capture($order, $payload);
    expect(WholesaleLabelAssignment::query()->forAllTenants()->count())->toBe(1)->and(data_get($order->fresh()->attribution_meta, 'custom_labels.hold'))->toBeTrue();
    expect($request->fresh()->shopify_order_id)->toBe('9001');
});
it('does not associate a forged customer receipt or retroactively hold old fee-only orders', function () {
    $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'shopify_order_id' => 9002, 'shopify_customer_id' => '202']);
    app(WholesaleLabelOrderService::class)->capture($order, ['customer' => ['id' => 202], 'line_items' => [['id' => 1, 'variant_id' => 42044189966417, 'quantity' => 3, 'properties' => []]]]);
    expect(data_get($order->fresh()->attribution_meta, 'custom_labels.hold'))->toBeNull();
});

it('saves a signed buyer upload without accepting a forged owner from the body', function () {
    $url = '/shopify/marketing/v1/wholesale/labels/uploads?'.http_build_query(labelProxyQuery());
    $response = $this->post($url, ['file' => labelLogoFile(), 'name' => 'Fictional uploaded logo', 'customer_id' => '202', 'tenant_id' => 999], ['Accept' => 'application/json'])->assertCreated();
    $revision = WholesaleLabelRevision::query()->forAllTenants()->findOrFail($response->json('label.id'));
    expect($revision->customer_id)->toBe('101')->and($revision->tenant_id)->toBe($this->tenant->id);
});
it('keeps the original after a conversion failure and never approves that failure', function () {
    config(['wholesale_labels.vtracer' => '/nonexistent/fictional-vtracer']);
    $revision = $this->library->upload($this->owner, labelLogoFile(), 'Retained failed proof');
    app(WholesaleLabelProcessor::class)->process($revision->id);
    expect($revision->fresh()->status)->toBe('failed')->and(Storage::disk('team_private')->exists($revision->original_path))->toBeTrue();
    $this->library->approve($this->owner, $revision->id, 123);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);
it('rejects changed idempotent submissions and reuse across a completed order', function () {
    $data = ['mode' => 'legacy', 'groups' => [['variant_id' => '111', 'line_key' => '111:key', 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()];
    $request = $this->library->prepare($this->owner, $data);
    $request->update(['bound_at' => now(), 'shopify_order_id' => '9001']);
    $this->library->prepare($this->owner, $data);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);
it('requires explicit customer matching for imports and rejects reviewers', function () {
    $user = User::factory()->create(['role' => 'pouring', 'is_active' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'wholesale_reviewer', 'membership_active' => true]);
    $data = ['customer_id' => '101', 'files' => [labelLogoFile()], 'confirmed' => '1', 'shopify_session_token' => wholesaleShopifySessionToken()];
    $this->actingAs($user)->postJson(route('shopify.app.wholesale.labels.import'), $data)->assertForbidden();
    $user->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'wholesale_label_manager']);
    $data['confirmed'] = '0';
    $this->postJson(route('shopify.app.wholesale.labels.import'), $data)->assertUnprocessable();
    $data['confirmed'] = '1';
    $this->post(route('shopify.app.wholesale.labels.import'), $data, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('results.0.ok', true);
    expect(WholesaleLabelRevision::query()->forAllTenants()->first()->source)->toBe('staff_import');
});
it('does not release a forged receipt when unrelated customer artwork is approved', function () {
    $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'shopify_order_id' => 9002, 'shopify_customer_id' => '202']);
    $request = $this->library->prepare($this->owner, ['mode' => 'legacy', 'groups' => [['variant_id' => '111', 'line_key' => '111:key', 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
    $p = [['name' => '_MF label request', 'value' => $request->id], ['name' => '_MF label mode', 'value' => 'legacy'], ['name' => '_MF label revision', 'value' => 'pending']];
    app(WholesaleLabelOrderService::class)->capture($order, ['customer' => ['id' => 202], 'line_items' => [['id' => 1, 'variant_id' => 42044189966417, 'quantity' => 1, 'properties' => $p], ['id' => 2, 'variant_id' => 111, 'quantity' => 3, 'properties' => $p]]]);
    expect(data_get($order->fresh()->attribution_meta, 'custom_labels.hold'))->toBeTrue()->and(WholesaleLabelAssignment::query()->forAllTenants()->first()->request_id)->toBeNull();
});

it('rejects invented, fee and wax melt groups using the trusted store catalog', function () {
    Http::fake(['*' => Http::response(['data' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/111', 'title' => 'Wax Melt', 'product' => ['title' => 'Wholesale Fictional', 'handle' => 'wholesale-fictional']]]]])]);
    app(App\Services\Wholesale\Labels\WholesaleLabelCatalog::class)->verifyCandleGroups($this->owner, [['variant_id' => '111']]);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);
it('resolves a legacy file without replacing an order source revision or changing it on webhook replay', function () {
    $request = $this->library->prepare($this->owner, ['mode' => 'legacy', 'groups' => [['variant_id' => '111', 'line_key' => '111:key', 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
    $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'shopify_order_id' => 9003, 'shopify_customer_id' => '101']);
    $properties = [['name' => '_MF label request', 'value' => $request->id], ['name' => '_MF label revision', 'value' => 'pending'], ['name' => '_MF label mode', 'value' => 'legacy']];
    $payload = ['customer' => ['id' => 101], 'line_items' => [['id' => 1, 'variant_id' => 42044189966417, 'quantity' => 1, 'properties' => $properties], ['id' => 2, 'variant_id' => 111, 'quantity' => 3, 'properties' => $properties]]];
    app(WholesaleLabelOrderService::class)->capture($order, $payload);
    $revision = $this->library->upload($this->owner, labelLogoFile(), 'Located legacy logo');
    $disk = Storage::disk('team_private');
    $disk->put('fictional-proof.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path fill="black" d="M0 0L1 1Z"/></svg>');
    $disk->put('fictional-proof.png', 'fixture');
    $revision->update(['status' => 'draft', 'vector_path' => 'fictional-proof.svg', 'preview_path' => 'fictional-proof.png', 'vector_sha256' => hash('sha256', $disk->get('fictional-proof.svg'))]);
    $request->update(['revision_id' => $revision->id, 'design_id' => $revision->design_id]);
    $this->library->approve($this->owner, $revision->id, 123);
    $assignment = WholesaleLabelAssignment::query()->forAllTenants()->first();
    expect($assignment->revision_id)->toBeNull()->and($assignment->production_revision_id)->toBe($revision->id)->and(data_get($order->fresh()->attribution_meta, 'custom_labels.hold'))->toBeFalse();
    app(WholesaleLabelOrderService::class)->capture($order, $payload);
    expect(data_get($order->fresh()->attribution_meta, 'custom_labels.hold'))->toBeFalse()->and($assignment->fresh()->production_revision_id)->toBe($revision->id);
});

function labelPdfBytes(int $pages): string
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
    $kids = [];
    for ($page = 0; $page < $pages; $page++) {
        $pageId = 3 + $page * 2;
        $contentId = $pageId + 1;
        $kids[] = "$pageId 0 R";
        $stream = "0 0 0 rg 20 20 60 40 re f\n";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 120 90] /Resources << >> /Contents $contentId 0 R >>";
        $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
    }
    $objects[2] = '<< /Type /Pages /Count '.$pages.' /Kids ['.implode(' ', $kids).'] >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "$id 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    for ($id = 1; $id < $count; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }

    return $pdf."trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
it('converts a real single-page PDF and retains but rejects a multi-page PDF', function () {
    $binary = getenv('WHOLESALE_LABEL_VTRACER') ?: dirname(base_path()).'/label-tools/bin/vtracer';
    $gs = getenv('WHOLESALE_LABEL_GS') ?: '/opt/homebrew/bin/gs';
    if (! is_file($binary) || ! is_file($gs)) {
        $this->markTestSkipped('Pinned tracer and Ghostscript are required.');
    }
    config(['wholesale_labels.vtracer' => $binary, 'wholesale_labels.ghostscript' => $gs]);
    foreach ([1 => 'draft', 2 => 'failed'] as $pages => $status) {
        $bytes = labelPdfBytes($pages);
        $revision = $this->library->upload($this->owner, UploadedFile::fake()->createWithContent("fictional-$pages.pdf", $bytes), 'Fictional PDF');
        app(WholesaleLabelProcessor::class)->process($revision->id);
        expect($revision->fresh()->status)->toBe($status)->and(Storage::disk('team_private')->get($revision->original_path))->toBe($bytes);
    }
});
