<?php

use App\Models\User;

test('landlord pages receive the full canvas shell treatment', function (): void {
    $host = parse_url(route('landlord.commercial.index'), PHP_URL_HOST);
    $host = is_string($host) && $host !== '' ? strtolower($host) : 'app.theeverbranch.com';

    config()->set('tenancy.landlord.primary_host', $host);
    config()->set('tenancy.landlord.hosts', [$host]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSee('mf-landlord-shell', false);

    expect(file_get_contents(resource_path('css/forestry-ui.css')))
        ->toContain('.mf-app-shell:is(.mf-landlord-shell, .mf-wide) .mf-shell-content');
});
