<?php

use App\Models\User;
use App\Services\Navigation\VoiceNavigationRegistry;
use App\Services\Search\Providers\NavigationSearchProvider;
use Illuminate\Http\Request;

test('mobile voice inventory projects current grants and exposes unsupported web destinations truthfully', function (): void {
    $this->mock(NavigationSearchProvider::class)->shouldReceive('search')->with('', Mockery::any())->andReturn([
        ['title' => 'Deep report', 'url' => '/reports/deep', 'meta' => ['aliases' => ['deep report']]],
    ]);
    $registry = app(VoiceNavigationRegistry::class);
    $payload = $registry->mobile(Request::create('/api/mobile/v1/workspaces/fictional/bootstrap'), User::factory()->make(),
        [['key' => 'home', 'label' => 'Home'], ['key' => 'work', 'label' => 'Work']],
        [['module_key' => 'field_service', 'display_name' => 'Field Service']], ['can_manage' => false]);
    expect($payload['version'])->toBe(1)
        ->and(collect($payload['pages'])->pluck('key'))->not->toContain('account:branding', 'module:customers', 'tab:hours');
    $report = collect($payload['pages'])->firstWhere('title', 'Deep report');
    expect($report['availability'])->toBe('unavailable')->and($report)->not->toHaveKey('destination');
    $field = collect($payload['pages'])->firstWhere('key', 'module:field_service');
    expect($field['destination'])->toBe(['kind' => 'field_service'])->and($field['aliases'])->toContain('schedule');
});
