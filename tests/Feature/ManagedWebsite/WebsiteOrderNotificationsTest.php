<?php

use App\Jobs\SendWebsiteOrderNotification;
use App\Mail\WebsiteOrderUpdateMail;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderLine;
use App\Models\WebsiteOrderNotification;
use App\Models\WebsiteShipment;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteOrderNotificationService;
use App\Services\ManagedWebsite\WebsiteOrderNotificationTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->nativeMailManager = app('mail.manager');
    Mail::fake();
    Queue::fake();
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Native order updates', 'slug' => 'order-updates']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.publishing_enabled' => true, 'managed_website.public_render_enabled' => true,
        'managed_website.order_notifications_enabled' => true, 'managed_website.order_notifications_tenant_ids' => [$this->tenant->id],
        'managed_website.order_notifications_sender_approved' => true, 'managed_website.order_notifications_mailer' => 'native_test',
        'managed_website.order_notifications_from_address' => 'orders@everbranch.example', 'managed_website.order_notifications_from_name' => 'Everbranch order updates',
        'mail.mailers.native_test' => ['transport' => 'array'], 'tenancy.domains.canonical.base_domain' => 'theeverbranch.com']);
    $websites = app(ManagedWebsiteService::class);
    $this->site = $websites->createSite($this->tenant, $this->actor);
    $websites->applyTheme($this->site, 'outdoor-elements', $this->actor);
    $websites->publish($this->site->fresh(), $this->actor);
    $this->site->refresh();
    $this->order = WebsiteOrder::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
        'number' => 'WEB-NOTIFICATION', 'lookup_token' => Str::random(56), 'currency' => 'usd', 'source' => 'native',
        'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'fulfillment_method' => 'ship', 'subtotal_cents' => 1000,
        'shipping_cents' => 200, 'tax_cents' => 99, 'total_cents' => 1299, 'customer_snapshot' => ['name' => 'Private Buyer', 'email' => 'private-buyer@example.test']]);
    WebsiteOrderLine::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id,
        'title' => 'Native kit', 'product_type' => 'physical', 'quantity' => 2, 'unit_price_cents' => 500, 'line_total_cents' => 1000, 'snapshot' => []]);
    $this->notifications = app(WebsiteOrderNotificationService::class);
    $this->record = fn (string $key = 'paid', string $kind = 'confirmed', ?WebsiteShipment $shipment = null) => DB::transaction(fn () => $this->notifications->record($this->order, $key, $kind, $shipment));
    $this->shipment = function (): WebsiteShipment {
        $fulfillment = WebsiteFulfillment::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id, 'method' => 'ship', 'status' => 'fulfilled']);

        return WebsiteShipment::query()->create(['tenant_id' => $this->tenant->id, 'website_order_id' => $this->order->id,
            'website_fulfillment_id' => $fulfillment->id, 'provider' => 'easypost', 'provider_shipment_id' => 'shp_'.Str::random(10),
            'carrier' => 'USPS', 'tracking_number' => '9400000000000000000000', 'status' => 'label_purchased']);
    };
});

test('accepted transitions have one encrypted frozen notification and one acceptance', function (): void {
    ($this->record)();
    ($this->record)();
    $notification = WebsiteOrderNotification::query()->sole();
    expect($notification->status)->toBeIn(['pending', 'queued'])->and($notification->snapshot['order_url'])->toStartWith('https://order-updates.theeverbranch.com/orders/track?');
    $raw = DB::table('website_order_notifications')->sole();
    expect($raw->snapshot)->not->toContain('private-buyer')->not->toContain($this->order->lookup_token)->not->toContain('Native kit');
    $this->order->update(['customer_snapshot' => ['email' => 'changed@example.test'], 'total_cents' => 999999]);
    $this->notifications->deliver($notification->id);
    $this->notifications->deliver($notification->id);
    expect($notification->fresh()->status)->toBe('accepted')->and($notification->fresh()->attempts)->toBe(1);
    Mail::assertSent(WebsiteOrderUpdateMail::class, fn ($mail) => $mail->hasTo('private-buyer@example.test') && $mail->update['total_cents'] === 1299 && $mail->envelope()->from->address === 'orders@everbranch.example');
    Mail::assertSentCount(1);
});

test('each recorded shipment transition remains distinct and label preparation never claims handoff', function (): void {
    $shipment = ($this->shipment)();
    foreach (['preparing', 'shipped', 'delivered', 'exception', 'label_updated'] as $kind) {
        ($this->record)('shipment-transition-'.$kind, $kind, $shipment);
        ($this->record)('shipment-transition-'.$kind, $kind, $shipment);
    }
    expect(WebsiteOrderNotification::query()->count())->toBe(5);
    $snapshot = WebsiteOrderNotification::query()->where('kind', 'preparing')->sole()->snapshot;
    expect($snapshot['message'])->toContain('not yet been handed')->and($snapshot)->not->toHaveKey('label_url');
});

test('disabled at transition rows never become a historical send after activation', function (): void {
    config()->set('managed_website.order_notifications_enabled', false);
    ($this->record)();
    $notification = WebsiteOrderNotification::query()->sole();
    expect($notification->status)->toBe('blocked');
    config()->set('managed_website.order_notifications_enabled', true);
    ($this->record)();
    $this->notifications->deliver($notification->id);
    expect($this->notifications->scan()['enqueued'])->toBe(0);
    Queue::assertNotPushed(SendWebsiteOrderNotification::class);
    Mail::assertNothingSent();
});

test('recording a rolled back transition leaves no notification', function (): void {
    try {
        DB::transaction(function (): void {
            $this->notifications->record($this->order, 'rolled-back', 'confirmed');
            throw new RuntimeException('Rollback fixture');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Rollback fixture');
    }
    expect(WebsiteOrderNotification::query()->count())->toBe(0);
    Queue::assertNotPushed(SendWebsiteOrderNotification::class);
});

test('scanner recovers a committed notification whose queue handoff was lost', function (): void {
    ($this->record)();
    WebsiteOrderNotification::query()->sole()->update(['next_attempt_at' => now()->subSecond()]);
    Queue::fake();
    $this->artisan('website:dispatch-order-notifications', ['--limit' => 1])->assertSuccessful();
    Queue::assertPushed(SendWebsiteOrderNotification::class, fn ($job) => $job->notificationId === WebsiteOrderNotification::query()->sole()->id);
    Mail::assertNothingSent();
});

test('transport ambiguity becomes unknown and cannot be automatically resent', function (): void {
    ($this->record)();
    $notification = WebsiteOrderNotification::query()->sole();
    $mock = Mockery::mock(WebsiteOrderNotificationTransport::class)->makePartial();
    $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('Fixture response lost after acceptance'));
    $this->app->instance(WebsiteOrderNotificationTransport::class, $mock);
    $this->notifications->deliver($notification->id);
    expect($notification->fresh()->status)->toBe('unknown')->and($notification->fresh()->failure_code)->toBe('transport_acceptance_unknown');
    $this->notifications->deliver($notification->id);
    expect($this->notifications->scan()['enqueued'])->toBe(0);
    Mail::assertNothingSent();
});

test('only local preparation failures receive bounded retries before sending', function (): void {
    ($this->record)();
    $notification = WebsiteOrderNotification::query()->sole();
    $mock = Mockery::mock(WebsiteOrderNotificationTransport::class);
    $mock->shouldReceive('prepare')->times(5)->andThrow(new RuntimeException('Fixture view unavailable'));
    $mock->shouldNotReceive('send');
    $this->app->instance(WebsiteOrderNotificationTransport::class, $mock);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->notifications->deliver($notification->id);
        if ($attempt < 5) {
            expect($notification->fresh()->status)->toBe('retry');
            $this->travel(61)->minutes();
        }
    }
    expect($notification->fresh()->status)->toBe('failed')->and($notification->fresh()->attempts)->toBe(5);
    Mail::assertNothingSent();
});

test('expired sending leases become unknown without another send attempt', function (): void {
    ($this->record)();
    $notification = WebsiteOrderNotification::query()->sole();
    $notification->update(['status' => 'processing', 'attempts' => 1, 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subSecond()]);
    Queue::fake();
    expect($this->notifications->scan()['unknown'])->toBe(1)->and($notification->fresh()->status)->toBe('unknown');
    Queue::assertNotPushed(SendWebsiteOrderNotification::class);
    Mail::assertNothingSent();
});

test('a duplicate worker cannot claim an active sending lease', function (): void {
    ($this->record)();
    $notification = WebsiteOrderNotification::query()->sole();
    $notification->update(['status' => 'processing', 'attempts' => 1, 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(5)]);
    $this->notifications->deliver($notification->id);
    expect($notification->fresh()->attempts)->toBe(1);
    Mail::assertNothingSent();
});

test('paused checkout does not pause already approved transactional updates', function (): void {
    ($this->record)();
    config()->set('managed_website.commerce_enabled', false);
    $this->notifications->deliver(WebsiteOrderNotification::query()->sole()->id);
    Mail::assertSentCount(1);
});

test('sender identity changes block queued messages instead of silently retargeting their transport', function (): void {
    ($this->record)();
    config()->set('managed_website.order_notifications_from_address', 'other@example.test');
    $notification = WebsiteOrderNotification::query()->sole();
    $this->notifications->deliver($notification->id);
    expect($notification->fresh()->status)->toBe('blocked')->and($notification->fresh()->failure_code)->toBe('readiness_changed');
    Mail::assertNothingSent();
});

test('revoked guest access tokens block pending links', function (): void {
    ($this->record)();
    $this->order->update(['lookup_token' => Str::random(56)]);
    $notification = WebsiteOrderNotification::query()->sole();
    $this->notifications->deliver($notification->id);
    expect($notification->fresh()->status)->toBe('blocked');
    Mail::assertNothingSent();
});

test('unapproved fallback transports and missing rollout readiness cannot enqueue mail', function (string $change): void {
    match ($change) {
        'log' => config()->set('mail.mailers.native_test.transport', 'log'),
        'failover' => config()->set('mail.mailers.native_test.transport', 'failover'),
        'unapproved' => config()->set('managed_website.order_notifications_sender_approved', false),
        'unlisted' => config()->set('managed_website.order_notifications_tenant_ids', []),
        'global-fallback' => config()->set('managed_website.order_notifications_mailer', ''),
        'private-site' => $this->site->update(['public_enabled' => false]),
    };
    expect($this->notifications->readiness($this->tenant)['ready'])->toBeFalse();
    ($this->record)();
    expect(WebsiteOrderNotification::query()->sole()->status)->toBe('blocked');
    Mail::assertNothingSent();
})->with(['log', 'failover', 'unapproved', 'unlisted', 'global-fallback', 'private-site']);

test('foreign shipments and connected imported orders cannot enter this outbox', function (): void {
    $shipment = ($this->shipment)();
    $shipment->update(['tenant_id' => Tenant::query()->create(['name' => 'Other', 'slug' => 'other-notifications'])->id]);
    expect(fn () => ($this->record)('foreign', 'preparing', $shipment))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->order->update(['source' => 'shopify']);
    ($this->record)();
    expect(WebsiteOrderNotification::query()->count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('recording and delivery never create marketing or legacy records', function (): void {
    $tables = ['orders', 'customers', 'marketing_profiles', 'messaging_contact_channel_states', 'tenant_messaging_ledger_entries'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => Illuminate\Support\Facades\Schema::hasTable($table) ? DB::table($table)->count() : null]);
    ($this->record)();
    $this->notifications->deliver(WebsiteOrderNotification::query()->sole()->id);
    foreach ($tables as $table) {
        expect(Illuminate\Support\Facades\Schema::hasTable($table) ? DB::table($table)->count() : null)->toBe($before[$table]);
    }
    Http::assertNothingSent();
});

test('blocked history needs an explicit authorized and audited retry after readiness is restored', function (): void {
    config()->set('managed_website.order_notifications_enabled', false);
    ($this->record)();
    $row = WebsiteOrderNotification::query()->sole();
    config()->set('managed_website.order_notifications_enabled', true);
    expect($this->notifications->scan()['enqueued'])->toBe(0);
    $this->notifications->retryBlocked($this->order, $row->id, $this->actor, 'Approved the verified transactional sender for this order.');
    $this->notifications->deliver($row->id);
    expect($row->fresh()->status)->toBe('accepted')->and($this->order->events()->where('event_type', 'notification_retry_requested')->count())->toBe(1);
    Mail::assertSentCount(1);
});

test('unknown acceptance needs evidence and a separate explicit retry before any resend', function (): void {
    ($this->record)();
    $row = WebsiteOrderNotification::query()->sole();
    $row->update(['status' => 'unknown', 'failure_code' => 'transport_acceptance_unknown']);
    expect(fn () => $this->notifications->retryBlocked($this->order, $row->id, $this->actor, 'Trying to bypass provider review.'))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->notifications->resolveUnknown($this->order, $row->id, $this->actor, 'confirmed_not_accepted', 'Provider support confirmed request ABC was rejected before acceptance.');
    expect($row->fresh()->status)->toBe('blocked')->and($this->notifications->scan()['enqueued'])->toBe(0);
    Mail::assertNothingSent();
    $this->notifications->retryBlocked($this->order, $row->id, $this->actor, 'Retrying after confirmed provider nonacceptance ABC.');
    $this->notifications->deliver($row->id);
    Mail::assertSentCount(1);
});

test('operator confirmed acceptance closes ambiguity without another email', function (): void {
    ($this->record)();
    $row = WebsiteOrderNotification::query()->sole();
    $row->update(['status' => 'unknown']);
    $this->notifications->resolveUnknown($this->order, $row->id, $this->actor, 'accepted', 'Provider reference ABC confirms this message was accepted.');
    $this->notifications->deliver($row->id);
    expect($row->fresh()->status)->toBe('accepted')->and($row->fresh()->failure_code)->toBe('operator_confirmed_acceptance');
    Mail::assertNothingSent();
});

test('inactive or marketing-only members cannot retry or resolve order updates', function (string $membership): void {
    config()->set('managed_website.order_notifications_enabled', false);
    ($this->record)();
    $row = WebsiteOrderNotification::query()->sole();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, $membership === 'inactive' ? ['membership_active' => false] : ['role' => 'marketing_manager']);
    expect(fn () => $this->notifications->retryBlocked($this->order, $row->id, $this->actor, 'A sufficient operator reason is recorded here.'))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $row->update(['status' => 'unknown']);
    expect(fn () => $this->notifications->resolveUnknown($this->order, $row->id, $this->actor, 'accepted', 'A sufficient provider reference is recorded here.'))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($row->fresh()->status)->toBe('unknown');
    Mail::assertNothingSent();
})->with(['inactive', 'marketing-only']);

test('an obsolete queued preparing notice cannot follow a delivery update', function (): void {
    $shipment = ($this->shipment)();
    ($this->record)('packed', 'preparing', $shipment);
    $row = WebsiteOrderNotification::query()->sole();
    $shipment->update(['status' => 'delivered']);
    $this->notifications->deliver($row->id);
    expect($row->fresh()->status)->toBe('superseded');
    Mail::assertNothingSent();
});

test('queued shipped notices are superseded when the latest parcel has an exception', function (string $status, bool $active): void {
    $shipment = ($this->shipment)();
    ($this->record)('shipped', 'shipped', $shipment);
    $row = WebsiteOrderNotification::query()->sole();
    $shipment->update(['status' => $status, 'parcel' => ['_native' => ['carrier_accepted_at' => now()->toIso8601String(), 'exception_active' => $active]]]);
    $this->notifications->deliver($row->id);
    expect($row->fresh()->status)->toBe('superseded')->and($row->fresh()->failure_code)->toBe('transition_superseded');
    Mail::assertNothingSent();
})->with([['failure', true], ['error', false], ['return_to_sender', false], ['in_transit', true]]);

test('initial return to sender sends its exception update without an on its way notice', function (): void {
    $shipment = ($this->shipment)();
    $shipment->update(['status' => 'return_to_sender', 'parcel' => ['_native' => ['carrier_accepted_at' => now()->toIso8601String(), 'exception_active' => true]]]);
    ($this->record)('shipped', 'shipped', $shipment);
    ($this->record)('exception-1', 'exception', $shipment);
    foreach (WebsiteOrderNotification::query()->orderBy('id')->get() as $row) {
        $this->notifications->deliver($row->id);
    }
    expect(WebsiteOrderNotification::query()->where('kind', 'shipped')->sole()->status)->toBe('superseded');
    Mail::assertSent(WebsiteOrderUpdateMail::class, fn ($mail) => $mail->update['kind'] === 'exception');
    Mail::assertSentCount(1);
});

test('nonpersistent or fake transports cannot qualify for production sending', function (): void {
    $this->app->detectEnvironment(fn () => 'production');
    expect($this->notifications->readiness($this->tenant)['ready'])->toBeFalse();
    Mail::assertNothingSent();
});

test('the explicit Laravel array transport renders one MIME message with the approved sender and private link', function (): void {
    Mail::swap($this->nativeMailManager);
    ($this->record)();
    $row = WebsiteOrderNotification::query()->sole();
    $this->notifications->deliver($row->id);
    expect($row->fresh()->status)->toBe('accepted');
    $messages = Mail::mailer('native_test')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $message = $messages->first()->getOriginalMessage();
    expect($message->getFrom()[0]->getAddress())->toBe('orders@everbranch.example')
        ->and($message->getTo()[0]->getAddress())->toBe('private-buyer@example.test')
        ->and(iterator_to_array($message->getHeaders()->all('Message-ID')))->toHaveCount(1)
        ->and($message->getHtmlBody())->toContain('/orders/track?')->toContain('Total paid: $12.99')
        ->and($message->getTextBody())->toContain('Total paid: $12.99')->toContain('&token=')->not->toContain('&amp;token=');
    Http::assertNothingSent();
});

test('platform order mail requires the confirmed mode and reviewed SES sender configuration', function (): void {
    config(['managed_website.order_notifications_mailer' => 'ses_platform',
        'managed_website.order_notifications_from_address' => 'no-reply@theeverbranch.com']);
    expect($this->notifications->readiness($this->tenant)['ready'])->toBeFalse();
    config(['managed_website.order_notifications_mailer' => 'ses_platform_confirmed']);
    expect($this->notifications->readiness($this->tenant)['ready'])->toBeFalse();
    config(['ses_migration.platform_enabled' => true, 'ses_migration.platform_key' => 'fixture',
        'ses_migration.platform_secret' => 'fixture', 'ses_migration.platform_configuration_set' => 'platform-fixture',
        'ses_migration.approved_attempt_cost_micros' => 1000]);
    expect($this->notifications->readiness($this->tenant)['ready'])->toBeTrue();
});
