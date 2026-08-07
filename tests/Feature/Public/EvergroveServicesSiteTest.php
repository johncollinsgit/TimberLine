<?php

use App\Models\OperatorAlertLog;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Services\Marketing\TwilioSmsService;

beforeEach(function (): void {
    $this->withoutVite();
});

test('evergrove public host renders the services site', function (): void {
    config()->set('evergrove.hosts', ['evergrovesoftware.com', 'www.evergrovesoftware.com']);

    $this->get('http://evergrovesoftware.com/')
        ->assertOk()
        ->assertSeeText('Evergrove')
        ->assertSee('brand/evergrove-logo.png?v=eg3', false)
        ->assertSeeText('Sign Up')
        ->assertSeeText('The app that keeps the job moving.')
        ->assertSeeText('Get a workflow audit')
        ->assertSeeText('See Everbranch')
        ->assertSee('data-public-phone-demo', false)
        ->assertSee('data-phone-tab="work"', false)
        ->assertSee('data-phone-tab="branches"', false)
        ->assertSee('data-phone-tab="account"', false)
        ->assertSeeText('Job board')
        ->assertSeeText('Growth tools')
        ->assertSeeText('Birthday')
        ->assertSeeText('Supplies used this month')
        ->assertSeeText('$3,842.19')
        ->assertSeeText('Employee spend')
        ->assertSeeText('28% of gross revenue')
        ->assertSeeText('Contract signed')
        ->assertSeeText('Launch Partner')
        ->assertSeeText('What Changes')
        ->assertSeeText('Less hunting. More doing.')
        ->assertSeeText('A small-business operating app, built by Evergrove.')
        ->assertSeeText('Launch partner pricing')
        ->assertSeeText('$59')
        ->assertSeeText('Click the mess')
        ->assertSeeText('Evergrove Studio')
        ->assertSeeText('Contact')
        ->assertSeeText('Product taste plus practical build work.')
        ->assertSee('data-clickable-details-card', false)
        ->assertSeeText('Job notes live in texts')
        ->assertSeeText('Quotes need babysitting')
        ->assertSeeText('Website and software project estimate')
        ->assertDontSeeText('Less Problems. More peace. The one place to run your business.');
});

test('everbranch public host keeps the everbranch product surface', function (): void {
    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSee('class="fb-public-body eb-studio-body"', false)
        ->assertSeeText('Your business has a rhythm.')
        ->assertSeeText('Everbranch helps you keep it.')
        ->assertSeeText('Become a launch partner')
        ->assertSeeText('Who it helps')
        ->assertSeeText('Contact')
        ->assertSee('data-studio-story', false)
        ->assertSee('data-studio-film', false)
        ->assertDontSee('data-industry-demo', false)
        ->assertSee('data-industry-option="retail"', false)
        ->assertSee('data-industry-option="field"', false)
        ->assertSee('data-industry-option="projects"', false)
        ->assertSee('data-industry-option="studio"', false)
        ->assertSee('everbranch-hvac-electrical-hero.jpg', false)
        ->assertSee('everbranch-hvac-electrical-field.jpg', false)
        ->assertSee('everbranch-field-owner-office.jpg', false)
        ->assertSee('data-studio-hero-slide', false)
        ->assertSeeText('Retail & product brands')
        ->assertSeeText('Field & service teams')
        ->assertSee(route('platform.plans'), false)
        ->assertSee(route('platform.start'), false)
        ->assertDontSee('data-public-tabs', false);
});

test('everbranch contact page stores messages in the landlord queue', function (): void {
    $this->get('http://theeverbranch.com/platform/contact')
        ->assertOk()
        ->assertSeeText('Tell Everbranch what keeps getting lost.')
        ->assertSee('name="source_page" value="everbranch_contact"', false)
        ->assertSeeText('Send message');

    $this->from('http://theeverbranch.com/platform/contact')
        ->post('/services/inquiries', [
            'name' => 'Shop Owner',
            'email' => 'owner@example.com',
            'company' => 'Owner Co',
            'current_tools' => 'texts and spreadsheets',
            'pain_point' => 'Customer follow-ups disappear.',
            'source_page' => 'everbranch_contact',
        ])
        ->assertRedirect('http://theeverbranch.com/platform/contact');

    $inquiry = ServiceInquiry::query()->firstOrFail();

    expect($inquiry->email)->toBe('owner@example.com')
        ->and($inquiry->source_page)->toBe('everbranch_contact')
        ->and($inquiry->pain_point)->toBe('Customer follow-ups disappear.');
});

test('everbranch walkthrough requests send one production safe operator text without creating demo access', function (): void {
    config()->set('everbranch.operator_alert_sms_enabled', true);
    config()->set('everbranch.operator_alert_phone', '+1 (555) 010-0101');

    $twilio = \Mockery::mock(TwilioSmsService::class);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->with(
            '15550100101',
            'Everbranch: New meeting request from Ridge Workshop.',
            \Mockery::on(fn (array $options): bool => ($options['source_type'] ?? null) === 'operator_alert')
        )
        ->andReturn(['success' => true, 'provider' => 'twilio', 'error_code' => null]);
    app()->instance(TwilioSmsService::class, $twilio);

    $this->get('http://theeverbranch.com/platform/contact?intent=walkthrough')
        ->assertOk()
        ->assertSeeText('Book a working session with Everbranch.')
        ->assertSee('name="source_page" value="everbranch_walkthrough"', false)
        ->assertSeeText('Request a meeting');

    $this->from('http://theeverbranch.com/platform/contact?intent=walkthrough')
        ->post('http://theeverbranch.com/services/inquiries', [
            'name' => 'Riley Morgan',
            'email' => 'owner@ridgeworkshop.co',
            'company' => 'Ridge Workshop',
            'pain_point' => 'We want to connect store orders to the team calendar.',
            'source_page' => 'everbranch_walkthrough',
        ])
        ->assertRedirect('http://theeverbranch.com/platform/contact?intent=walkthrough');

    expect(ServiceInquiry::query()->where('source_page', 'everbranch_walkthrough')->count())->toBe(1)
        ->and(User::query()->where('email', 'owner@ridgeworkshop.co')->exists())->toBeFalse()
        ->and(OperatorAlertLog::query()
            ->where('event_key', 'service_inquiry.created')
            ->value('status'))->toBe('sent');
});

test('authenticated users still see evergrove surface on evergrove public host', function (): void {
    config()->set('evergrove.hosts', ['evergrovesoftware.com', 'www.evergrovesoftware.com']);

    $user = User::factory()->create([
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('http://evergrovesoftware.com/')
        ->assertOk()
        ->assertSee('brand/evergrove-logo.png?v=eg3', false)
        ->assertSeeText('The app that keeps the job moving.')
        ->assertDontSeeText('Less Problems. More peace. The one place to run your business.');
});

test('app host sends guests toward login while lander redirects home', function (): void {
    $this->get('http://app.theeverbranch.com/')
        ->assertRedirect(route('login', absolute: false));

    $this->get('http://evergrovesoftware.com/lander')
        ->assertRedirect('/')
        ->assertStatus(301);
});

test('evergrove calculator pages render by tool path', function (): void {
    $this->get('http://evergrovesoftware.com/tools/project-estimate')
        ->assertOk()
        ->assertSee('brand/evergrove-logo.png?v=eg3', false)
        ->assertSeeText('Website and software project estimate')
        ->assertSee('data-tool-key="project_estimate"', false)
        ->assertSeeText('Estimated build range');

    $this->get('http://evergrovesoftware.com/tools/ai-roi')
        ->assertOk()
        ->assertSeeText('AI opportunity ROI calculator')
        ->assertSee('data-tool-key="ai_roi"', false);

    $this->get('http://evergrovesoftware.com/tools/automation-savings')
        ->assertOk()
        ->assertSeeText('Automation savings calculator')
        ->assertSee('data-tool-key="automation_savings"', false);
});

test('service inquiry submission stores calculator planning payload', function (): void {
    $payload = [
        'tool' => 'ai_roi',
        'inputs' => ['hours' => '10', 'hourly' => '60'],
        'low' => 900,
        'high' => 1600,
        'note' => 'Monthly planning value after estimated tool cost.',
    ];

    $this->from('http://evergrovesoftware.com/tools/ai-roi')
        ->post('/services/inquiries', [
            'name' => 'Sarah Owner',
            'email' => 'sarah@example.com',
            'company' => 'Acme Services',
            'website' => 'https://acme.example.com',
            'business_size' => '6_20',
            'current_tools' => 'Shopify, spreadsheets, email',
            'pain_point' => 'We retype orders into three places.',
            'timeline' => '30_days',
            'budget_range' => '7500_15000',
            'source_page' => 'ai_roi',
            'calculator_payload' => json_encode($payload),
        ])
        ->assertRedirect('http://evergrovesoftware.com/tools/ai-roi');

    $inquiry = ServiceInquiry::query()->firstOrFail();

    expect($inquiry->email)->toBe('sarah@example.com')
        ->and($inquiry->status)->toBe('new')
        ->and($inquiry->source_page)->toBe('ai_roi')
        ->and((array) $inquiry->calculator_payload)->toMatchArray($payload);
});

test('landlord operator can review service messages', function (): void {
    ServiceInquiry::query()->create([
        'name' => 'Lead One',
        'email' => 'lead@example.com',
        'company' => 'Lead Co',
        'pain_point' => 'Need an AI workflow.',
        'calculator_payload' => ['tool' => 'automation_savings', 'low' => 1200, 'high' => 2400],
        'source_page' => 'automation_savings',
        'status' => 'new',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('landlord.service-inquiries.index'))
        ->assertOk()
        ->assertSeeText('Messages & Inquiries')
        ->assertSeeText('Lead One')
        ->assertSeeText('automation_savings');

    $this->actingAs($admin)
        ->get(route('landlord.messages.index'))
        ->assertOk()
        ->assertSeeText('Messages & Inquiries');
});
