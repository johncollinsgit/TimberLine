<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteOrder;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteLabelDocumentService;
use App\Services\ManagedWebsite\WebsiteLabelPdf;
use App\Services\ManagedWebsite\WebsiteLabelPrintService;
use App\Services\ManagedWebsite\WebsitePrintProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Print pilot', 'slug' => 'print-pilot']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now(), 'ui_preferences' => ['compact_tables' => true]]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.commerce_enabled' => false, 'managed_website.commerce_shipping_enabled' => false]);
    $site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->order = WebsiteOrder::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $site->id, 'number' => 'PRINT-001', 'lookup_token' => Str::random(48), 'payment_status' => 'paid', 'fulfillment_method' => 'ship', 'total_cents' => 2500, 'currency' => 'usd']);
    $fulfillment = WebsiteFulfillment::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id, 'method' => 'ship', 'status' => 'pending']);
    $this->shipment = WebsiteShipment::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id, 'website_fulfillment_id' => $fulfillment->id, 'provider' => 'easypost', 'provider_shipment_id' => 'shp_print_fixture', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'tracking_number' => 'TEST-NOT-POSTAGE', 'status' => 'label_purchased', 'purchased_at' => now(), 'parcel' => ['_native' => ['version' => 1, 'buy_state' => 'purchased', 'retired' => false]]]);
    $this->png = app(WebsiteLabelPdf::class)->testPng();
    $this->url = 'https://easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/20261015/print-fixture.png';
    $this->snapshot = WebsiteShipmentEvent::query()->create(['tenant_id' => $this->tenant->id, 'website_shipment_id' => $this->shipment->id, 'provider_event_id' => 'snapshot-print', 'event_type' => 'provider_snapshot', 'occurred_at' => now(), 'payload' => ['shipment' => ['id' => 'shp_print_fixture', 'postage_label' => ['id' => 'pl_print_fixture', 'label_url' => $this->url, 'label_file_type' => 'image/png', 'label_size' => '4x6', 'label_resolution' => 200]]]]);
    $this->assetResponse = Http::response($this->png, 200, ['Content-Type' => 'image/png']);
    Http::fake([$this->url => fn () => $this->assetResponse]);
    $this->documents = app(WebsiteLabelDocumentService::class);
    $this->prints = app(WebsiteLabelPrintService::class);
    $this->profileData = ['workstation_id' => (string) Str::uuid(), 'name' => 'USB packing desk', 'printer_family' => 'dymo', 'printer_name' => 'DYMO LabelWriter 4XL', 'connection' => 'usb', 'os' => 'mac', 'browser' => 'chrome', 'layout' => 'thermal_4x6', 'orientation' => 'portrait', 'media_confirmed' => true];
    $this->profile = app(WebsitePrintProfileService::class)->save($this->tenant, $this->actor, $this->profileData);
});

test('label PDFs preserve full raster and exact thermal Letter and A4 media', function (): void {
    $manifest = $this->documents->prepare($this->shipment);
    expect($manifest['width_px'])->toBe(800)->and($manifest['height_px'])->toBe(1200);
    foreach (['thermal_4x6' => '288 432', 'letter' => '612 792', 'a4' => '595.275591 841.889764'] as $layout => $dimensions) {
        $pdf = $this->documents->bytes($this->shipment, $layout);
        expect($pdf)->toStartWith('%PDF-1.4')->toContain('/MediaBox [0 0 '.$dimensions.']', '/Width 800 /Height 1200', '/PrintScaling /None', '/Interpolate false', '/BitsPerComponent 8', '/Predictor 15 /Colors 3');
        preg_match('/\/Subtype \/Image.*?stream\n(.*?)\nendstream/s', $pdf, $match);
        expect($match[1] ?? '')->not->toBe('');
        // Decode the actual PDF image stream independently through libpng.
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $embeddedPng = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 800, 1200, 8, 2, 0, 0, 0)).$chunk('IDAT', $match[1]).$chunk('IEND', '');
        $original = imagecreatefromstring($this->png);
        $embedded = imagecreatefromstring($embeddedPng);
        expect($embedded)->not->toBeFalse();
        foreach ([[25, 25], [24, 24], [200, 400], [201, 401], [400, 600], [400, 720], [600, 750], [799, 1199]] as [$x, $y]) {
            expect(imagecolorat($embedded, $x, $y))->toBe(imagecolorat($original, $x, $y));
        }
        unset($original, $embedded);
    }
    expect($this->documents->bytes($this->shipment, 'letter'))->toContain('288 0 0 432 36 324 cm');
    expect($this->documents->bytes($this->shipment, 'a4'))->toContain('288 0 0 432 36 373.889764 cm');
    expect(DB::table('website_shipment_events')->where('event_type', 'label_document_prepared')->value('payload'))->not->toContain('website-labels', 'source_sha256');
    Http::assertSentCount(1);
});

test('landscape rotates the thermal label without changing its physical size', function (): void {
    $this->documents->prepare($this->shipment, 'landscape');
    expect($this->documents->bytes($this->shipment, 'thermal_4x6', 'landscape'))->toContain('/MediaBox [0 0 432 288]', '0 288 -432 0 432 0 cm');
    expect($this->documents->bytes($this->shipment, 'letter', 'landscape'))->toContain('/MediaBox [0 0 792 612]', '288 0 0 432 36 144 cm');
});

test('repeat preparation download and reprint use stored documents without provider or postage calls', function (): void {
    $id = (string) Str::uuid();
    $attempt = $this->prints->request($this->shipment, $this->actor, $this->profile['id'], $id);
    expect($this->prints->request($this->shipment, $this->actor, $this->profile['id'], $id))->toBe($attempt);
    $this->prints->result($this->shipment, $this->actor, $id, 'cancelled');
    $this->prints->request($this->shipment, $this->actor, $this->profile['id'], (string) Str::uuid());
    $this->documents->prepare($this->shipment);
    $this->documents->bytes($this->shipment, 'thermal_4x6');
    expect(WebsiteShipmentEvent::query()->where('event_type', 'label_print_requested')->count())->toBe(2)->and(WebsiteShipment::query()->count())->toBe(1)->and($this->order->fresh()->fulfillment_status)->not->toBe('fulfilled');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' || $request->hasHeader('Authorization'));
});

test('only an explicit operator result records paper output and results are idempotent', function (): void {
    $id = (string) Str::uuid();
    $this->prints->request($this->shipment, $this->actor, $this->profile['id'], $id);
    expect(WebsiteShipmentEvent::query()->where('event_type', 'label_print_result')->count())->toBe(0);
    $this->prints->result($this->shipment, $this->actor, $id, 'operator_confirmed');
    $this->prints->result($this->shipment, $this->actor, $id, 'operator_confirmed');
    expect(WebsiteShipmentEvent::query()->where('event_type', 'label_print_result')->count())->toBe(1);
    expect(fn () => $this->prints->result($this->shipment, $this->actor, $id, 'failed'))->toThrow(HttpException::class);
    expect($this->shipment->fresh()->status)->toBe('label_purchased');
});

test('retired and unresolved labels cannot be prepared or printed', function (string $state, bool $retired): void {
    $this->shipment->update(['parcel' => ['_native' => ['version' => 1, 'buy_state' => $state, 'retired' => $retired]]]);
    expect(fn () => $this->documents->prepare($this->shipment))->toThrow(HttpException::class);
    expect(fn () => $this->prints->request($this->shipment, $this->actor, $this->profile['id'], (string) Str::uuid()))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with([['unknown', false], ['approval_required', false], ['purchased', true]]);

test('label asset URLs cannot forward credentials or fetch arbitrary private hosts', function (string $url): void {
    $payload = $this->snapshot->payload;
    $payload['shipment']['postage_label']['label_url'] = $url;
    $this->snapshot->update(['payload' => $payload]);
    expect(fn () => $this->documents->prepare($this->shipment))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with(['http://easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/a.png', 'https://127.0.0.1/files/postage_label/a.png', 'https://easypost-files.s3.us-west-2.amazonaws.com.evil.test/files/postage_label/a.png', 'https://name@easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/a.png', 'https://easypost-files.s3.us-west-2.amazonaws.com:444/files/postage_label/a.png', 'https://easypost-files.s3.us-west-2.amazonaws.com/other/a.png']);

test('redirected corrupt and oversized responses preserve postage without a ready document', function (string $kind): void {
    $this->assetResponse = match ($kind) {
        'redirect' => Http::response('', 302, ['Location' => 'https://127.0.0.1/private']),
        'oversized' => Http::response("\x89PNG\r\n\x1a\n".str_repeat('x', WebsiteLabelPdf::MAX_BYTES), 200, ['Content-Type' => 'image/png']),
        default => Http::response(substr($this->png, 0, 40), 200, ['Content-Type' => 'image/png']),
    };
    expect(fn () => $this->documents->prepare($this->shipment))->toThrow(ValidationException::class);
    expect($this->documents->manifest($this->shipment))->toBeNull()->and(data_get($this->shipment->fresh()->parcel, '_native.buy_state'))->toBe('purchased');
    Http::assertSentCount(1);
})->with(['redirect', 'corrupt', 'oversized']);

test('wrong source dimensions and corrupt stored PDFs fail closed while document retry retains postage', function (): void {
    $payload = $this->snapshot->payload;
    $payload['shipment']['postage_label']['label_resolution'] = 300;
    $this->snapshot->update(['payload' => $payload]);
    expect(fn () => $this->documents->prepare($this->shipment))->toThrow(HttpException::class);
    $payload['shipment']['postage_label']['label_resolution'] = 200;
    $this->snapshot->update(['payload' => $payload]);
    $manifest = $this->documents->prepare($this->shipment);
    Storage::disk('local')->put($manifest['files']['thermal_4x6']['path'], '%PDF-corrupt');
    expect(fn () => $this->documents->bytes($this->shipment, 'thermal_4x6'))->toThrow(HttpException::class);
    $this->documents->prepare($this->shipment);
    expect($this->documents->bytes($this->shipment, 'thermal_4x6'))->toStartWith('%PDF-1.4');
});

test('profiles and requests require an active authorized tenant member and preserve unrelated preferences', function (): void {
    expect($this->actor->fresh()->ui_preferences['compact_tables'])->toBeTrue();
    $other = Tenant::query()->create(['name' => 'Another retailer', 'slug' => 'another-print-retailer']);
    $this->actor->tenants()->attach($other->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $other->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    expect(fn () => app(WebsitePrintProfileService::class)->find($other, $this->actor, $this->profile['id']))->toThrow(HttpException::class);
    $worker = User::factory()->create(['is_active' => true]);
    $worker->tenants()->attach($this->tenant->id, ['role' => 'member', 'membership_active' => true]);
    expect(fn () => app(WebsitePrintProfileService::class)->profiles($this->tenant, $worker))->toThrow(HttpException::class);
    expect(fn () => $this->prints->request($this->shipment, $worker, $this->profile['id'], (string) Str::uuid()))->toThrow(HttpException::class);
});

test('Windows profiles support sheet media while direct and narrow label formats are rejected', function (): void {
    $windows = app(WebsitePrintProfileService::class)->save($this->tenant, $this->actor, array_replace($this->profileData, ['os' => 'windows', 'printer_family' => 'desktop', 'layout' => 'a4', 'orientation' => 'landscape']));
    expect($windows['adapter'])->toBe('browser_pdf')->and($windows['os'])->toBe('windows');
    expect(fn () => app(WebsitePrintProfileService::class)->save($this->tenant, $this->actor, $this->profileData + ['adapter' => 'raw_usb']))->toThrow(ValidationException::class);
    expect(fn () => app(WebsitePrintProfileService::class)->save($this->tenant, $this->actor, array_replace($this->profileData, ['layout' => 'small_address_label'])))->toThrow(ValidationException::class);
});

test('authenticated paused-commerce label routes protect provider URLs and never infer print success', function (): void {
    $this->actingAs($this->actor)->withSession(['tenant_id' => $this->tenant->id]);
    $this->get(route('managed-website.printing.settings'))->assertOk()->assertSee('Mac')->assertSee('Windows');
    $this->get(route('managed-website.labels.show', $this->shipment))->assertOk()->assertDontSee($this->url);
    $this->post(route('managed-website.labels.prepare', $this->shipment), ['orientation' => 'portrait'])->assertRedirect();
    $this->get(route('managed-website.labels.download', $this->shipment))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $id = (string) Str::uuid();
    $this->post(route('managed-website.labels.print', $this->shipment), ['request_id' => $id, 'profile_id' => $this->profile['id']])->assertRedirect();
    $this->get(route('managed-website.labels.print-view', ['shipment' => $this->shipment, 'requestId' => $id]))->assertOk()->assertSee('No result has been recorded automatically.')->assertDontSee($this->url);
    $this->get(route('managed-website.labels.image', ['shipment' => $this->shipment, 'requestId' => $id]))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(WebsiteShipmentEvent::query()->where('event_type', 'label_print_result')->count())->toBe(0);
    Http::assertSentCount(1);
});

test('test PDF creates no postage or provider calls', function (): void {
    $this->actingAs($this->actor)->withSession(['tenant_id' => $this->tenant->id]);
    $this->get(route('managed-website.printing.test', ['layout' => 'letter']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(WebsiteShipment::query()->count())->toBe(1)->and(WebsiteShipmentEvent::query()->where('event_type', 'label_print_requested')->count())->toBe(0);
    Http::assertNothingSent();
});

test('foreign tenant downloads and another operator print request fail closed', function (): void {
    $this->documents->prepare($this->shipment);
    $other = Tenant::query()->create(['name' => 'Foreign print tenant', 'slug' => 'foreign-print-tenant']);
    $this->actor->tenants()->attach($other->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $other->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    $this->actingAs($this->actor)->withSession(['tenant_id' => $other->id]);
    $this->get(route('managed-website.labels.download', $this->shipment))->assertNotFound();
    $id = (string) Str::uuid();
    $this->prints->request($this->shipment, $this->actor, $this->profile['id'], $id);
    $manager = User::factory()->create(['is_active' => true]);
    $manager->tenants()->attach($this->tenant->id, ['role' => 'manager', 'membership_active' => true]);
    expect(fn () => $this->prints->attempt($this->shipment, $manager, $id))->toThrow(HttpException::class);
});
