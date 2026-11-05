<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Mail\WholesaleOutreachDigestMail;
use App\Models\MarketingEmailDelivery;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShopifyImportRun;
use App\Models\Tenant;
use App\Models\TenantWholesaleSetting;
use App\Models\WholesaleFollowUp;
use App\Models\WholesaleSuggestion;
use App\Services\Wholesale\WholesaleOperationsService;
use App\Services\Wholesale\WholesaleOutreachDigest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-09 13:00:00', 'UTC'));
    $this->tenant = Tenant::query()->create(['name' => 'Fictional Candle Company', 'slug' => 'modern-forestry']);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
    configureWholesaleReportingFixture();
});

function outreachOrder(Tenant $tenant, string $email, int $days = 130, float $value = 200, array $extra = []): Order
{
    return Order::factory()->create(array_merge([
        'tenant_id' => $tenant->id, 'shopify_store_key' => 'wholesale',
        'shopify_store' => 'wholesale', 'source' => 'shopify_wholesale',
        'order_type' => 'wholesale', 'customer_name' => 'Fictional Buyer',
        'shipping_company' => 'Fictional Shop '.$email, 'customer_email' => $email,
        'shopify_customer_id' => $email, 'total_price' => $value, 'refund_total' => 0,
        'currency_code' => 'USD', 'status' => 'complete', 'ordered_at' => now()->subDays($days),
    ], $extra));
}

test('outreach ranks ten qualified accounts and builds accurate editable latest order drafts', function (): void {
    foreach (range(1, 12) as $index) {
        outreachOrder($this->tenant, 'buyer'.$index.'@example.com', 160, $index * 100);
    }
    $last = Order::query()->where('customer_email', 'buyer12@example.com')->first();
    OrderLine::query()->create(['order_id' => $last->id, 'raw_title' => 'Cedar & Moss', 'raw_variant' => '16 oz', 'quantity' => 24]);
    outreachOrder($this->tenant, 'buyer12@example.com', 230, 40, ['order_number' => 'OLD-ORDER']);
    outreachOrder($this->tenant, 'retail@example.com', 160, 99999, ['shopify_store_key' => 'retail', 'shopify_store' => 'retail']);
    $other = Tenant::query()->create(['name' => 'Private other tenant', 'slug' => 'private-other']);
    outreachOrder($other, 'private@example.com', 160, 99999);
    $report = app(WholesaleOutreachDigest::class)->snapshot($this->tenant);

    expect($report['rows'])->toHaveCount(10)
        ->and($report['rows'][0]['email'])->toBe('buyer12@example.com')
        ->and(array_column($report['rows'], 'email'))->not->toContain('retail@example.com', 'private@example.com', 'buyer1@example.com');
    $row = $report['rows'][0];
    parse_str(parse_url($row['draft_url'], PHP_URL_QUERY), $draft);
    expect(parse_url($row['draft_url'], PHP_URL_HOST))->toBe('mail.google.com')
        ->and($draft['to'])->toBe('buyer12@example.com')
        ->and($draft['view'])->toBe('cm')
        ->and($draft['body'])->toContain('24 × Cedar & Moss (16 oz)', $last->order_number, $last->ordered_at->format('F j, Y'))
        ->not->toContain('OLD-ORDER', 'private@example.com', 'discount', 'in stock');
    $html = (new WholesaleOutreachDigestMail($report, 1))->render();
    expect($html)->toContain('Review email draft', 'Cedar &amp; Moss')
        ->not->toContain('private@example.com', 'retail@example.com', '@if', '@endif');
    expect(view('emails.wholesale.outreach-digest-text', compact('report'))->render())->toContain('buyer12@example.com');
    if ($preview = getenv('OUTREACH_PREVIEW_FILE')) {
        file_put_contents($preview, $html);
    }
});

test('outreach respects handled snoozed future and recently completed follow ups and deduplicates inboxes', function (): void {
    foreach (['kept', 'handled', 'snoozed', 'future', 'recent'] as $name) {
        outreachOrder($this->tenant, $name.'@example.com');
    }
    $customers = app(WholesaleOperationsService::class)->customers((int) $this->tenant->id)->keyBy('email');
    foreach (['handled' => 'already_handled', 'snoozed' => 'snoozed'] as $name => $status) {
        $customer = $customers[$name.'@example.com'];
        WholesaleSuggestion::query()->create([
            'tenant_id' => $this->tenant->id, 'public_id' => (string) Str::uuid(),
            'target_type' => 'customer', 'target_key' => $customer['public_key'],
            'suggestion_type' => 'customer_due_for_reorder', 'title' => 'Reviewed suggestion',
            'recommended_action' => 'Review the account', 'reason' => 'Qualified reorder evidence',
            'last_evaluated_at' => now(),
            'evidence_fingerprint' => Str::random(64), 'status' => $status,
            'snoozed_until' => now()->addWeek(),
            'supporting_evidence' => ['last_wholesale_order_at' => $customer['last_order_at']->toIso8601String()],
        ]);
    }
    foreach (['future', 'recent'] as $name) {
        WholesaleFollowUp::query()->create([
            'tenant_id' => $this->tenant->id, 'public_id' => (string) Str::uuid(),
            'target_type' => 'customer', 'target_key' => $customers[$name.'@example.com']['public_key'],
            'follow_up_type' => 'sales_review', 'title' => 'Follow up',
            'created_by_user_id' => \App\Models\User::factory()->create()->id,
            'status' => $name === 'future' ? 'open' : 'completed',
            'due_at' => now()->addDays(3), 'completed_at' => $name === 'recent' ? now()->subDay() : null,
        ]);
    }
    outreachOrder($this->tenant, 'KEPT@example.com', 135, 190, ['shopify_customer_id' => 'different-source-id']);
    outreachOrder($this->tenant, 'fresh@example.com', 5);
    outreachOrder($this->tenant, 'cancelled@example.com', 130, 200, ['status' => 'cancelled']);
    outreachOrder($this->tenant, 'refunded@example.com', 130, 200, ['refund_total' => 200]);
    outreachOrder($this->tenant, 'not-an-email');
    $report = app(WholesaleOutreachDigest::class)->snapshot($this->tenant);
    expect($report['rows'])->toHaveCount(1)
        ->and(strtolower($report['rows'][0]['email']))->toBe('kept@example.com')
        ->and($report['rows'][0]['reason'])->toContain('limited history');
});

test('repeat order intervals are positive and a recent reorder removes the account from outreach', function (): void {
    outreachOrder($this->tenant, 'recent-repeat@example.com', 80);
    outreachOrder($this->tenant, 'recent-repeat@example.com', 20);
    $customer = app(WholesaleOperationsService::class)->customers((int) $this->tenant->id)->first();
    expect($customer['median_reorder_days'])->toBe(60)
        ->and($customer['average_reorder_days'])->toBe(60)
        ->and($customer['timing_state'])->toBe('active')
        ->and($customer['predicted_reorder_at']->toDateString())->toBe(now()->addDays(40)->toDateString());
    expect(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toBe([]);
});

test('outreach refuses incomplete history instead of filling a list from partial production imports', function (): void {
    app()->forgetInstance(\App\Services\Wholesale\WholesaleReportingSnapshot::class);
    outreachOrder($this->tenant, 'partial-history@example.com');
    expect(fn () => app(WholesaleOutreachDigest::class)->snapshot($this->tenant))->toThrow(DomainException::class);
});

test('outreach reports import freshness and requires a confirmed owned wholesale workspace', function (): void {
    ShopifyImportRun::query()->create(['tenant_id' => $this->tenant->id, 'store_key' => 'wholesale', 'is_dry_run' => false, 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(5)]);
    expect(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['import_stale'])->toBeFalse();
    TenantWholesaleSetting::query()->where('tenant_id', $this->tenant->id)->delete();
    expect(fn () => app(WholesaleOutreachDigest::class)->snapshot($this->tenant))->toThrow(DomainException::class);
});

test('outreach sends only to internal recipient once per week and dry runs send nothing', function (): void {
    Mail::fake();
    outreachOrder($this->tenant, 'customer@example.com');
    $options = ['--tenant' => 'modern-forestry', '--email' => 'info@theforestrystudio.com'];
    $this->artisan('wholesale:send-outreach-digest', [...$options, '--dry-run' => true])->assertSuccessful();
    Mail::assertNothingSent();
    expect(MarketingEmailDelivery::query()->where('template_key', 'wholesale_outreach_digest')->count())->toBe(0);
    $this->artisan('wholesale:send-outreach-digest', $options)->assertSuccessful();
    $this->artisan('wholesale:send-outreach-digest', $options)->assertSuccessful();
    Mail::assertSent(WholesaleOutreachDigestMail::class, fn ($mail) => $mail->hasTo('info@theforestrystudio.com') && ! $mail->hasTo('customer@example.com'));
    Mail::assertSentCount(1);
    $this->travel(7)->days();
    $this->artisan('wholesale:send-outreach-digest', $options)->assertSuccessful();
    Mail::assertSentCount(2);
});

test('uncertain digest delivery is reserved and is never automatically repeated', function (): void {
    \Illuminate\Support\Facades\Log::spy();
    Mail::shouldReceive('to')->once()->with('info@theforestrystudio.com')->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Provider accepted then disconnected'));
    $options = ['--tenant' => 'modern-forestry', '--email' => 'info@theforestrystudio.com'];
    $this->artisan('wholesale:send-outreach-digest', $options)->assertFailed();
    $this->artisan('wholesale:send-outreach-digest', $options)->assertSuccessful();
    expect(MarketingEmailDelivery::query()->where('template_key', 'wholesale_outreach_digest')->count())->toBe(1)
        ->and(MarketingEmailDelivery::query()->where('template_key', 'wholesale_outreach_digest')->first()->status)->toBe('sending');
});

test('weekly outreach schedule stays at monday eight eastern across daylight saving time', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'wholesale:send-outreach-digest'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * 1')
        ->and($event->timezone)->toBe('America/New_York')
        ->and($event->command)->toContain('info@theforestrystudio.com', 'modern-forestry');
    foreach (['2026-11-09 13:00:00', '2027-07-12 12:00:00'] as $utc) {
        $this->travelTo(now()->parse($utc, 'UTC'));
        expect($event->isDue(app()))->toBeTrue();
        $this->travel(1)->hours();
        expect($event->isDue(app()))->toBeFalse();
    }
});

test('do not contact accounts are excluded from internal outreach recommendations', function (): void {
    outreachOrder($this->tenant, 'blocked@example.com');
    \App\Models\WholesaleProspect::query()->create([
        'tenant_id' => $this->tenant->id, 'public_id' => (string) Str::uuid(),
        'business_name' => 'Fictional Blocked Shop', 'discovery_source' => 'operator',
        'public_business_email' => 'BLOCKED@example.com', 'do_not_contact' => true,
    ]);
    expect(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toBe([]);
});

test('failed history verification sends only one internal attention notice and no customer recommendations', function (): void {
    Mail::fake();
    \Illuminate\Support\Facades\Log::spy();
    $source = \Mockery::mock(\App\Services\Wholesale\WholesaleReportingSnapshot::class);
    $source->shouldReceive('refresh')->andThrow(new RuntimeException('History is unavailable'));
    app()->instance(\App\Services\Wholesale\WholesaleReportingSnapshot::class, $source);
    $options = ['--tenant' => 'modern-forestry', '--email' => 'info@theforestrystudio.com'];
    $this->artisan('wholesale:send-outreach-digest', $options)->assertFailed();
    $this->artisan('wholesale:send-outreach-digest', $options)->assertSuccessful();
    Mail::assertSentCount(1);
    Mail::assertSent(WholesaleOutreachDigestMail::class, fn ($mail) => $mail->hasTo('info@theforestrystudio.com') && $mail->report['rows'] === [] && $mail->report['import_stale']);
});
