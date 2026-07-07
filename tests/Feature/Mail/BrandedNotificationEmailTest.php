<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;

/**
 * The transactional email template must be Everbranch-branded, not stock Laravel.
 * Renders the framework password-reset notification through our published mail views
 * and asserts the branding swapped over.
 */
test('the password reset email renders Everbranch branding', function (): void {
    $user = User::factory()->create(['email' => 'branded@example.test']);

    $html = (string) (new ResetPassword('test-token'))->toMail($user)->render();

    // Everbranch branding is present.
    expect($html)->toContain('Everbranch');                 // wordmark / salutation / footer
    expect($html)->toContain('everbranch-mark.png');        // leaf logo image
    expect($html)->toContain('#123c43');                    // brand-green CTA button (inlined theme)
});

test('the password reset email drops the stock Laravel branding', function (): void {
    $user = User::factory()->create(['email' => 'unbranded@example.test']);

    $html = (string) (new ResetPassword('test-token'))->toMail($user)->render();

    // No Laravel logo, and the salutation/footer no longer render the "Laravel" app name.
    expect($html)->not->toContain('laravel.com/img/notification-logo');
    expect($html)->not->toContain('>Laravel<');
});

test('the from name is Everbranch, not the polluted MAIL_FROM_NAME', function (): void {
    // Prod resolves MAIL_FROM_NAME to "${APP_NAME}" = "Laravel"; config must ignore that.
    expect(config('mail.from.name'))->toBe('Everbranch');
});
