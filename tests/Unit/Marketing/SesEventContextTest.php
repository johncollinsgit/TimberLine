<?php

use App\Services\Marketing\Email\SesEventContext;

it('requires the single native tenant auto-tag observed in authenticated AWS events', function (): void {
    expect(SesEventContext::nativeTenant(['mail' => ['tags' => ['ses:tenant' => ['everbranch-tenant-900000001']]]]))->toBe('everbranch-tenant-900000001');
    foreach ([[], ['ses:tenant-name' => ['everbranch-tenant-900000001']], ['ses:tenant' => 'everbranch-tenant-900000001'],
        ['ses:tenant' => ['everbranch-tenant-900000001', 'other']], ['ses:tenant' => [1 => 'everbranch-tenant-900000001']], ['ses:tenant' => [null]]] as $tags) {
        expect(SesEventContext::nativeTenant(['mail' => ['tags' => $tags]]))->toBeNull();
    }
});

it('binds the SES source mailbox with optional display names and rejects address lists', function (): void {
    foreach (['no-reply@theeverbranch.com', 'Everbranch <no-reply@theeverbranch.com>', '"Everbranch, Inc." <NO-REPLY@theeverbranch.com>'] as $source) {
        expect(SesEventContext::source(['mail' => ['source' => $source]]))->toBe('no-reply@theeverbranch.com');
    }
    foreach (['', 'invalid', 'no-reply@theeverbranch.com, other@example.test', ['no-reply@theeverbranch.com']] as $source) {
        expect(SesEventContext::source(['mail' => ['source' => $source]]))->toBeNull();
    }
});
