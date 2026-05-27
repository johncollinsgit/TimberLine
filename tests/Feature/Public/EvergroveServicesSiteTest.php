<?php

use App\Models\ServiceInquiry;
use App\Models\User;

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
        ->assertSeeText('Turn scattered operations into useful software.')
        ->assertSeeText('AI systems consulting')
        ->assertSeeText('Website and software project estimate')
        ->assertSeeText('Modern Forestry')
        ->assertDontSeeText('Run the business you actually have.');
});

test('everbranch public host keeps the everbranch product surface', function (): void {
    $this->get('http://theeverbranch.com/')
        ->assertOk()
        ->assertSeeText('Run the business you actually have.')
        ->assertSeeText('Everbranch brings customers, work, money, materials, communication, and next steps into one intelligent workspace.')
        ->assertDontSeeText('Turn scattered operations into useful software.');
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

test('landlord operator can review service inquiries', function (): void {
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
        ->assertSeeText('Service Inquiry Queue')
        ->assertSeeText('Lead One')
        ->assertSeeText('automation_savings');
});
